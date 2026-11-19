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
update_option('social_relay_schema', 2);
$stopMigration = static fn(array $queries): array => array_map(
    static fn(string $sql): string => str_replace("            KEY expired_jobs (status,locked_until,id),\n", '', $sql),
    $queries
);
add_filter('dbdelta_queries', $stopMigration);
Database::activate();
verify(!Database::ready() && (int) get_option('social_relay_schema') === 2 && get_option('social_relay_migration_error') === '1', 'an incomplete migration retains its prior version');
remove_filter('dbdelta_queries', $stopMigration);
Database::activate();
verify(Database::ready(), 'a subsequent migration restores the missing index');

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
echo "WordPress and MariaDB integration checks passed.\n";
