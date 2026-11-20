<?php
declare(strict_types=1);

use SocialRelay\Database;
use SocialRelay\Plugin;
use SocialRelay\Security;

$root = $argv[1] ?? '';
if (!is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/wordpress.php /path/to/disposable-wordpress\n");
    exit(2);
}
define('SOCIAL_RELAY_ENCRYPTION_KEY_ID', 'test-key');
define('SOCIAL_RELAY_ENCRYPTION_KEYS', ['test-key' => str_repeat('ab', 32)]);
require $root . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true) || !class_exists(Database::class)) {
    throw new RuntimeException('Use local, loopback-only WordPress with Social Relay active.');
}

function verify(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

global $wpdb;
$jobs = Database::table('jobs');
$connections = Database::table('connections');
$audit = Database::table('audit');
$rate = Database::table('rate');
Database::activate();
verify(Database::ready(), 'schema and all indexes verified on activation');
verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $jobs") === 0, 'fixture has an empty queue');
$wpdb->query("ALTER TABLE $jobs DROP INDEX expired_jobs");
$wpdb->query("ALTER TABLE $jobs DROP COLUMN provider_state");
update_option('social_relay_schema', 3);
$stopMigration = static fn(array $queries): array => array_map(
    static fn(string $sql): string => preg_replace('/^[ \t]*KEY expired_jobs \(status,locked_until,id\),\r?\n/m', '', $sql),
    $queries
);
add_filter('dbdelta_queries', $stopMigration);
Database::activate();
verify(!Database::ready() && (int) get_option('social_relay_schema') === 3 && get_option('social_relay_migration_error') === '1', 'an incomplete migration retains its prior version');
remove_filter('dbdelta_queries', $stopMigration);
Database::activate();
verify(Database::ready() && $wpdb->get_var("SHOW COLUMNS FROM $jobs LIKE 'provider_state'") === 'provider_state', 'a subsequent migration restores the missing index and staged delivery column');

$token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcd';
$wrapped = Security::encrypt($token);
verify(str_starts_with($wrapped, 'test-key:') && Security::decrypt($wrapped) === $token, 'independent versioned encryption key round trip');
$saltKey = hash_hkdf('sha256', wp_salt('auth') . wp_salt('secure_auth'), 32, 'social-relay-connection-v1');
$nonce = random_bytes(12);
$tag = '';
$cipher = openssl_encrypt($token, 'aes-256-gcm', $saltKey, OPENSSL_RAW_DATA, $nonce, $tag);
verify(Security::decrypt(base64_encode($nonce . $tag . $cipher)) === $token, 'existing salt-encrypted credentials remain readable');

$now = current_time('mysql', true);
$wpdb->insert($connections, ['name' => 'Test only', 'provider' => 'telegram', 'surface' => '@test_channel', 'endpoint' => '', 'secret' => $wrapped, 'active' => 1, 'created_at' => $now]);
$connection = $wpdb->insert_id;
$wpdb->insert(Database::table('recipes'), [
    'name' => 'Post type condition', 'post_type' => 'post', 'connection_id' => $connection,
    'rule_json' => wp_json_encode(['field' => 'post_type', 'operator' => 'eq', 'value' => 'post']),
    'template' => '{title}', 'created_at' => $now, 'updated_at' => $now,
]);
$post_id = static function (string $title, string $password = '', string $type = 'post'): int {
    return wp_insert_post(['post_title' => $title, 'post_status' => 'publish', 'post_password' => $password, 'post_type' => $type]);
};
$job = static function (int $post) use ($wpdb, $jobs): array {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE post_id = %d", $post), ARRAY_A) ?? [];
};
$protected = $post_id('Password protected publication', 'test-only');
verify(!$job($protected) && !Plugin::preview(get_post($protected))[0]['eligible'], 'password-protected posts are skipped and previewed as ineligible');
$original = $post_id('Public post matching the real normalized type');
verify($job($original)['status'] === 'queued' && Plugin::queue_post(get_post($original)) === 0, 'normal first-publication hook matches type and deduplicates identical content');
$savedJob = $job($original);
// An older recipe may already have queued the same text and destination.
$wpdb->update($jobs, ['recipe_id' => 0], ['id' => $savedJob['id']]);
verify(Plugin::reconcile_post(get_post($original), false) === 0, 'dry run recognizes the stored publication key across recipes');
$wpdb->update($jobs, ['recipe_id' => $savedJob['recipe_id']], ['id' => $savedJob['id']]);

remove_action('wp_after_insert_post', [Plugin::class, 'after_insert'], 20);
$missing = $post_id('Unqueued publication');
add_action('wp_after_insert_post', [Plugin::class, 'after_insert'], 20, 4);
verify(Plugin::reconcile_post(get_post($missing), false) === 1 && !$job($missing), 'dry run counts a missing job without writing it');
wp_update_post(['ID' => $original, 'post_title' => 'Edited after first publication']);
verify(Plugin::reconcile_post(get_post($original), true) === 0, 'reconciliation preserves a previously queued snapshot');
for ($i = 1; $i <= 103; ++$i) {
    $post_id('Bulk import post ' . $i);
}
verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $jobs") === 104, 'bulk import retains jobs beyond the old intake cap');
for ($i = 0; $i < 10; ++$i) {
    verify(Database::reserve_delivery(), 'delivery budget grants slot ' . ($i + 1));
}
verify(!Database::reserve_delivery(), 'delivery budget stops the next attempt without removing a job');
$wpdb->update($rate, ['next_slot' => 0], ['id' => 1]);

$due = static function (int $post) use ($wpdb, $jobs, $now): void {
    $wpdb->query("UPDATE $jobs SET run_at = '2099-01-01 00:00:00'");
    $wpdb->update($jobs, ['run_at' => $now], ['post_id' => $post]);
};
$calls = 0;
$profile = 'timeout';
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$calls, &$profile) {
    if (!str_starts_with($url, 'https://api.telegram.org/')) {
        return new WP_Error('test_blocked', 'External HTTP is blocked in this test.');
    }
    ++$calls;
    return $profile === 'timeout'
        ? new WP_Error('http_request_failed', 'simulated timeout')
        : ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{"ok":true,"result":{"message_id":42}}', 'headers' => []];
}, 10, 3);
$due($original);
Plugin::work();
verify($calls === 0 && $job($original)['status'] === 'queued', 'paused publishing leaves jobs queued');
update_option('social_relay_enabled', '1');
update_option('social_relay_allow_staging', '1');
wp_update_post(['ID' => $original, 'post_password' => 'locked-after-queue']);
Plugin::work();
verify($calls === 0 && $job($original)['status'] === 'cancelled', 'worker rechecks privacy before sending');

$attempt = $post_id('An uncertain timeout');
$due($attempt);
Plugin::work();
Plugin::work();
verify($calls === 1 && $job($attempt)['status'] === 'delivery_unknown', 'Telegram timeout is never retried automatically');

$profile = 'success';
$accepted = $post_id('Acceptance before storage failure');
$due($accepted);
$blockReceipt = static function (string $query) use ($jobs): string {
    return str_starts_with($query, "UPDATE `$jobs`") && str_contains($query, "'published'") ? '' : $query;
};
add_filter('query', $blockReceipt);
Plugin::work();
remove_filter('query', $blockReceipt);
$id = (int) $job($accepted)['id'];
verify($calls === 2 && $job($accepted)['status'] === 'publishing' && $job($accepted)['dispatch_mode'] === 'single', 'worker retains its dispatch marker when the result write fails');
verify((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $audit WHERE object_type = 'job' AND object_id = %d AND action = 'job_published'", $id)) === 0, 'failed storage write never records an audit success');
$wpdb->update($jobs, ['locked_until' => '2000-01-01 00:00:00'], ['id' => $id]);
Plugin::work();
verify($calls === 2 && $job($accepted)['status'] === 'delivery_unknown', 'expired Telegram lease is quarantined without resending');

$recipes = Database::table('recipes');
$wpdb->update($recipes, ['enabled' => 0], ['connection_id' => $connection]);
$add_destination = static function (string $provider, string $surface, string $secret) use ($wpdb, $connections, $recipes, $now): int {
    $wpdb->insert($connections, [
        'name' => $provider, 'provider' => $provider, 'surface' => $surface, 'endpoint' => '',
        'secret' => $secret, 'active' => 1, 'created_at' => $now,
    ]);
    $id = (int) $wpdb->insert_id;
    $wpdb->insert($recipes, [
        'name' => $provider, 'post_type' => 'post', 'connection_id' => $id,
        'rule_json' => wp_json_encode(['field' => 'post_type', 'operator' => 'eq', 'value' => 'post']),
        'template' => "{title}\n{url}", 'created_at' => $now, 'updated_at' => $now,
    ]);
    return $id;
};
$manual = $add_destination('whatsapp', 'https://whatsapp.com/channel/0029VaRelayTest123', '');
$instagram = $add_destination('instagram', '123456', Security::encrypt('instagram-fixture-token'));
$find = static fn(int $post, int $destination): array => $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $jobs WHERE post_id = %d AND connection_id = %d", $post, $destination
), ARRAY_A) ?: [];

$media = wp_insert_attachment(['post_title' => 'Featured image', 'post_mime_type' => 'image/jpeg'], 'relay-featured.jpg');
update_post_meta($media, '_wp_attachment_image_alt', 'A photograph from the fixture');
add_filter('wp_get_attachment_url', static fn($url, $id) => (int) $id === $media ? 'https://site.example/relay-featured.jpg' : $url, 10, 2);

update_option('social_relay_enabled', '0');
$noImage = $post_id('Article without a featured image');
verify($find($noImage, $manual)['status'] === 'awaiting_manual', 'WhatsApp creates a reviewable job while automatic publishing is paused');
verify(!$find($noImage, $instagram), 'Instagram first publication requires an eligible featured image');
$post = wp_insert_post(['post_title' => 'Scheduled feature', 'post_status' => 'future', 'post_date' => '2099-01-02 00:00:00',
    'meta_input' => ['_thumbnail_id' => $media]]);
verify(!$find($post, $instagram), 'future posts do not queue before publication');
wp_publish_post($post);
$photo = $find($post, $instagram);
$snapshot = json_decode($photo['snapshot'] ?? '', true);
verify(($photo['status'] ?? '') === 'queued' && $snapshot['featured_image_mime'] === 'image/jpeg'
    && $snapshot['featured_image_alt'] === 'A photograph from the fixture', 'scheduled publication snapshots the featured image and alt text');
wp_update_post(['ID' => $post, 'post_status' => 'draft', 'post_title' => 'Edited feature']);
wp_publish_post($post);
verify($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $jobs WHERE post_id = %d", $post)) === '2', 're-publishing does not recreate existing first-publication jobs');

$graph = [];
$stage = 'IN_PROGRESS';
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$graph, &$stage) {
    if (!str_starts_with($url, 'https://graph.instagram.com/')) {
        return $pre;
    }
    $graph[] = [$args['method'], $url];
    $body = match (true) {
        str_ends_with($url, '/media') => ['id' => '777'],
        str_ends_with($url, '/777?fields=status_code') => ['status_code' => $stage],
        str_ends_with($url, '/media_publish') => ['id' => '778'],
        default => [],
    };
    return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode($body), 'headers' => []];
}, 20, 3);
update_option('social_relay_enabled', '1');
$wpdb->update($rate, ['next_slot' => 0], ['id' => 1]);
$due($post);
Plugin::work();
$prepared = $find($post, $instagram);
verify($prepared['status'] === 'retry_wait' && $prepared['provider_state'] === '777' && (int) $prepared['attempts'] === 0
    && count($graph) === 1, 'Instagram persists its container before dispatching a publication');
$due($post);
Plugin::work();
verify($find($post, $instagram)['provider_state'] === '777' && count($graph) === 2, 'polling a preparing Instagram image does not create a new container');
$stage = 'FINISHED';
$due($post);
Plugin::work();
$published = $find($post, $instagram);
verify($published['status'] === 'published' && $published['remote_id'] === '778' && $published['provider_state'] === ''
    && count($graph) === 4, 'Instagram publishes the persisted container and stores the final receipt');
verify($find($post, $manual)['status'] === 'awaiting_manual', 'the automatic worker leaves the WhatsApp action for the operator');
echo "WordPress and MariaDB integration checks passed.\n";
