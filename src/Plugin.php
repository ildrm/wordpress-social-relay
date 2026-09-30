<?php
namespace SocialRelay;

final class Plugin
{
    public static function boot(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['social_relay_minute'] = ['interval' => 60, 'display' => __('Every minute', 'social-relay')];
            return $schedules;
        });
        add_action('wp_after_insert_post', [self::class, 'after_insert'], 20, 4);
        add_action('social_relay_worker', [self::class, 'work']);
        add_action('social_relay_deferred_product', [self::class, 'deferred_product']);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_post_social_relay_action', [Admin::class, 'handle']);
        add_action('admin_post_social_relay_queue_post', [Admin::class, 'manual_queue']);
        add_action('add_meta_boxes', [Admin::class, 'meta_boxes']);
        add_action('save_post', [Admin::class, 'save_post_exclusion']);
        add_action('admin_init', static function (): void {
            if ((int) get_option('social_relay_schema', 0) < Database::SCHEMA_VERSION) {
                Database::activate();
            }
        });
        add_action('init', static function (): void {
            if (!wp_next_scheduled('social_relay_worker')) {
                wp_schedule_event(time() + 60, 'social_relay_minute', 'social_relay_worker');
            }
        });
    }

    public static function after_insert(int $postId, \WP_Post $post, bool $update, ?\WP_Post $postBefore): void
    {
        if ($post->post_status !== 'publish' || ($postBefore && $postBefore->post_status === 'publish') || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || !$type->public || get_post_meta($post->ID, '_social_relay_exclude', true) === '1') {
            return;
        }
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            if (!wp_next_scheduled('social_relay_deferred_product', [$postId])) {
                wp_schedule_single_event(time() + 60, 'social_relay_deferred_product', [$postId]);
            }
            return;
        }
        self::enqueue($post);
    }

    public static function deferred_product(int $postId): void
    {
        $post = get_post($postId);
        if ($post) {
            self::queue_post($post);
        }
    }

    public static function queue_post(\WP_Post $post): void
    {
        $type = get_post_type_object($post->post_type);
        if ($post->post_status === 'publish' && $type && $type->public && get_post_meta($post->ID, '_social_relay_exclude', true) !== '1') {
            self::enqueue($post);
        }
    }

    public static function preview(\WP_Post $post): array
    {
        global $wpdb;
        $recipes = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Database::table('recipes') . ' WHERE post_type = %s AND enabled = 1 ORDER BY priority DESC, id ASC LIMIT 100',
            $post->post_type
        ), ARRAY_A);
        $connections = [];
        if ($recipes) {
            $ids = array_values(array_unique(array_map('intval', array_column($recipes, 'connection_id'))));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,name,provider,surface,endpoint,active FROM ' . Database::table('connections') . " WHERE id IN ($placeholders)", ...$ids), ARRAY_A);
            foreach ($rows ?: [] as $row) {
                $connections[(int) $row['id']] = $row;
            }
        }
        $out = [];
        $excluded = get_post_meta($post->ID, '_social_relay_exclude', true) === '1';
        $contents = [];
        foreach ($recipes ?: [] as $recipe) {
            $profile = $recipe['profile'];
            if (!isset($contents[$profile])) {
                $contents[$profile] = Content::normalize($post, $profile);
            }
            $content = $contents[$profile];
            $rule = json_decode($recipe['rule_json'], true);
            $count = 0;
            if (!Rules::validate($rule, 0, $count)) {
                continue;
            }
            $trace = Rules::evaluate($rule, $content);
            $text = Template::render($recipe['template'], $content);
            $connection = $connections[(int) $recipe['connection_id']] ?? null;
            $compatibility = $connection && $connection['active'] ? Publisher::compatible($connection, ['text' => $text]) : ['compatible' => false, 'reason' => 'connection'];
            $out[] = [
                'recipe' => $recipe,
                'connection' => $connection,
                'content' => $content,
                'trace' => $trace,
                'eligible' => !$excluded && $trace['pass'] && $text !== '' && $compatibility['compatible'],
                'compatibility' => $compatibility,
                'excluded' => $excluded,
                'text' => $text,
            ];
        }
        return $out;
    }

    private static function enqueue(\WP_Post $post): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $recent = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Database::table('jobs') . ' WHERE created_at >= %s',
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ));
        if ($recent >= 100) {
            if (!get_transient('social_relay_intake_capped')) {
                set_transient('social_relay_intake_capped', $now, HOUR_IN_SECONDS);
            }
            return;
        }
        foreach (self::preview($post) as $item) {
            if ($recent >= 100) {
                if (!get_transient('social_relay_intake_capped')) {
                    set_transient('social_relay_intake_capped', $now, HOUR_IN_SECONDS);
                }
                break;
            }
            if (!$item['eligible']) {
                continue;
            }
            $recipe = $item['recipe'];
            $connection = $item['connection'];
            if (!$connection || !$connection['active']) {
                continue;
            }
            $key = PublicationKey::first(get_current_blog_id(), (int) $post->ID, (int) $recipe['connection_id'], $item['text']);
            $content = $item['content'];
            $snapshot = [
                'source_id' => $post->ID,
                'source_type' => $post->post_type,
                'source_revision' => $content['source_revision'],
                'recipe_id' => (int) $recipe['id'],
                'recipe_version' => (int) $recipe['version'],
                'connection_id' => (int) $recipe['connection_id'],
                'profile' => $content['profile'],
                'objective' => $recipe['objective'],
                'title' => $content['title'],
                'url' => $content['url'],
                'text' => $item['text'],
                'trace' => $item['trace'],
            ];
            $result = $wpdb->insert(Database::table('jobs'), [
                'idem_key' => $key,
                'post_id' => $post->ID,
                'recipe_id' => (int) $recipe['id'],
                'connection_id' => (int) $recipe['connection_id'],
                'status' => 'queued',
                'run_at' => $now,
                'snapshot' => wp_json_encode($snapshot),
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']);
            if ($result) {
                ++$recent;
                Database::audit('job_queued', 'job', (int) $wpdb->insert_id);
            }
        }
    }

    public static function work(): void
    {
        if (!Security::can_send()) {
            return;
        }
        global $wpdb;
        $table = Database::table('jobs');
        $now = current_time('mysql', true);
        // A worker interrupted after claiming a job may have sent it. Receivers should honor the idempotency key.
        $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'retry_wait', run_at = %s, lock_token = '', locked_until = NULL WHERE status = 'publishing' AND locked_until < %s LIMIT 10", $now, $now));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status IN ('queued','retry_wait') AND run_at <= %s ORDER BY run_at ASC, id ASC LIMIT 10", $now));
        foreach ($ids as $id) {
            if (!Security::can_send()) {
                break;
            }
            $token = bin2hex(random_bytes(16));
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'publishing', lock_token = %s, locked_until = %s, updated_at = %s WHERE id = %d AND status IN ('queued','retry_wait') AND run_at <= %s", $token, gmdate('Y-m-d H:i:s', time() + 300), $now, $id, $now));
            if ($claimed !== 1) {
                continue;
            }
            self::process((int) $id, $token);
        }
    }

    private static function process(int $id, string $token): void
    {
        global $wpdb;
        $jobs = Database::table('jobs');
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id = %d AND lock_token = %s", $id, $token), ARRAY_A);
        if (!$job) {
            return;
        }
        if (!Security::can_send()) {
            self::finish($job, $token, 'retry_wait', '', '');
            return;
        }
        $post = get_post((int) $job['post_id']);
        if (!$post || $post->post_status !== 'publish' || get_post_meta($post->ID, '_social_relay_exclude', true) === '1') {
            self::finish($job, $token, 'cancelled', '', '');
            return;
        }
        $connection = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('connections') . ' WHERE id = %d AND active = 1', $job['connection_id']), ARRAY_A);
        if (!$connection) {
            self::finish($job, $token, 'blocked', '', 'connection');
            return;
        }
        $snapshot = json_decode($job['snapshot'], true);
        if (!is_array($snapshot)) {
            self::finish($job, $token, 'permanent_failure', '', 'rejected');
            return;
        }
        $result = Publisher::send($connection, $snapshot, $job['idem_key']);
        if ($result['ok']) {
            self::finish($job, $token, 'published', $result['remote_id'], '');
            return;
        }
        $attempts = (int) $job['attempts'] + 1;
        if ($result['retry'] && $attempts < 5) {
            $delay = min(3600, (int) (60 * (2 ** ($attempts - 1)))) + random_int(0, 30);
            $wpdb->update($jobs, [
                'status' => 'retry_wait', 'attempts' => $attempts,
                'run_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'error_code' => $result['code'], 'lock_token' => '', 'locked_until' => null,
                'updated_at' => current_time('mysql', true),
            ], ['id' => $job['id'], 'lock_token' => $token]);
            Database::audit('job_retry', 'job', $id);
            return;
        }
        self::finish($job, $token, 'permanent_failure', '', $result['code'], $attempts);
    }

    private static function finish(array $job, string $token, string $status, string $remoteId, string $error, ?int $attempts = null): void
    {
        global $wpdb;
        $data = ['status' => $status, 'remote_id' => $remoteId, 'error_code' => $error,
            'lock_token' => '', 'locked_until' => null, 'updated_at' => current_time('mysql', true)];
        if ($attempts !== null) {
            $data['attempts'] = $attempts;
        } elseif ($status === 'published') {
            $data['attempts'] = (int) $job['attempts'] + 1;
        }
        $wpdb->update(Database::table('jobs'), $data, ['id' => $job['id'], 'lock_token' => $token]);
        Database::audit('job_' . $status, 'job', (int) $job['id']);
    }
}
