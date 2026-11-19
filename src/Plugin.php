<?php
namespace SocialRelay;

final class Plugin
{
    public static function cron_schedules(array $schedules): array
    {
        $schedules['social_relay_minute'] = ['interval' => 60, 'display' => __('Every minute', 'social-relay')];
        return $schedules;
    }

    public static function boot(): void
    {
        add_action('wp_after_insert_post', [self::class, 'after_insert'], 20, 4);
        add_action('social_relay_worker', [self::class, 'work']);
        add_action('social_relay_deferred_product', [self::class, 'deferred_product']);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_post_social_relay_action', [Admin::class, 'handle']);
        add_action('admin_post_social_relay_queue_post', [Admin::class, 'manual_queue']);
        add_action('add_meta_boxes', [Admin::class, 'meta_boxes']);
        add_action('save_post', [Admin::class, 'save_post_exclusion']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('social-relay', Cli::class);
        }
        add_action('admin_init', static function (): void {
            if (!Database::ready()) {
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
        if (!self::can_publish($post)) {
            return;
        }
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            if (!wp_next_scheduled('social_relay_deferred_product', [$postId])) {
                if (!wp_schedule_single_event(time() + 60, 'social_relay_deferred_product', [$postId])) {
                    self::queue_post($post);
                }
            }
            return;
        }
        self::queue_post($post);
    }

    public static function deferred_product(int $postId): void
    {
        $post = get_post($postId);
        if ($post) {
            self::queue_post($post);
        }
    }

    public static function can_publish(\WP_Post $post): bool
    {
        $type = get_post_type_object($post->post_type);
        return $post->post_status === 'publish'
            && $post->post_password === ''
            && $post->post_type !== 'attachment'
            && $type && $type->public && is_post_type_viewable($type)
            && get_post_meta($post->ID, '_social_relay_exclude', true) !== '1';
    }

    /** @return int|false The number of new jobs, or false on a storage error. */
    public static function queue_post(\WP_Post $post): int|false
    {
        return self::can_publish($post) ? self::enqueue($post) : 0;
    }

    public static function preview(\WP_Post $post): array
    {
        return self::candidates($post) ?: [];
    }

    private static function candidates(\WP_Post $post): array|false
    {
        global $wpdb;
        $recipes = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Database::table('recipes') . ' WHERE post_type = %s AND enabled = 1 ORDER BY priority DESC, id ASC',
            $post->post_type
        ), ARRAY_A);
        if ($wpdb->last_error) {
            return false;
        }
        $connections = [];
        if ($recipes) {
            $ids = array_values(array_unique(array_map('intval', array_column($recipes, 'connection_id'))));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,name,provider,surface,endpoint,active FROM ' . Database::table('connections') . " WHERE id IN ($placeholders)", ...$ids), ARRAY_A);
            if ($wpdb->last_error) {
                return false;
            }
            foreach ($rows ?: [] as $row) {
                $connections[(int) $row['id']] = $row;
            }
        }
        $out = [];
        $excluded = get_post_meta($post->ID, '_social_relay_exclude', true) === '1';
        $publishable = self::can_publish($post);
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
                'eligible' => $publishable && $trace['pass'] && $text !== '' && $compatibility['compatible'],
                'compatibility' => $compatibility,
                'excluded' => $excluded,
                'text' => $text,
            ];
        }
        return $out;
    }

    /**
     * Reconcile first-publication jobs against current recipes and the saved post.
     * A prior job for that recipe and destination is authoritative, even if its text changed.
     */
    public static function reconcile_post(\WP_Post $post, bool $apply): int|false
    {
        return self::can_publish($post) ? self::enqueue($post, true, $apply) : 0;
    }

    private static function enqueue(\WP_Post $post, bool $missing_only = false, bool $apply = true): int|false
    {
        if (!Database::ready()) {
            // First-publish hooks can run during a CLI import before any administrator has visited.
            Database::activate();
            if (!Database::ready()) {
                Database::storage_error('enqueue');
                return false;
            }
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $jobs = Database::table('jobs');
        $count = 0;
        $failed = false;
        $items = self::candidates($post);
        if ($items === false) {
            Database::storage_error('enqueue');
            return false;
        }
        $existing = [];
        if ($missing_only) {
            $prior = $wpdb->get_results($wpdb->prepare("SELECT recipe_id, connection_id FROM $jobs WHERE post_id = %d", $post->ID), ARRAY_A);
            if ($wpdb->last_error) {
                Database::storage_error('enqueue');
                return false;
            }
            foreach ($prior as $row) {
                $existing[$row['recipe_id'] . ':' . $row['connection_id']] = true;
            }
        }
        $keys = [];
        foreach ($items as $item) {
            if (!$item['eligible']) {
                continue;
            }
            $recipe = $item['recipe'];
            $connection = $item['connection'];
            if (!$connection || !$connection['active']) {
                continue;
            }
            $key = PublicationKey::first(get_current_blog_id(), (int) $post->ID, (int) $recipe['connection_id'], $item['text']);
            if (isset($existing[$recipe['id'] . ':' . $recipe['connection_id']]) || isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            if (!$apply) {
                if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $jobs WHERE idem_key = %s", $key))) {
                    ++$count;
                }
                if ($wpdb->last_error) {
                    return false;
                }
                continue;
            }
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
            $json = wp_json_encode($snapshot);
            $result = $json === false ? false : $wpdb->query($wpdb->prepare(
                "INSERT INTO $jobs (idem_key, post_id, recipe_id, connection_id, status, run_at, snapshot, created_at, updated_at)
                 VALUES (%s, %d, %d, %d, 'queued', %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id",
                $key, $post->ID, $recipe['id'], $recipe['connection_id'], $now, $json, $now, $now
            ));
            if ($result === 1) {
                ++$count;
                Database::audit('job_queued', 'job', (int) $wpdb->insert_id);
            } elseif ($result === false) {
                $failed = true;
            }
        }
        if ($failed) {
            Database::storage_error('enqueue');
            return false;
        }
        return $count;
    }

    public static function work(): void
    {
        if (!Database::ready() || !Security::can_send()) {
            return;
        }
        global $wpdb;
        $table = Database::table('jobs');
        $now = current_time('mysql', true);
        // Preflight jobs have never sent. An idempotent receiver can safely accept the same key again.
        $recovered = $wpdb->query($wpdb->prepare(
            "UPDATE $table
             SET status = IF(dispatch_mode IN ('preflight', 'idempotent'), 'retry_wait', 'delivery_unknown'),
                 error_code = IF(dispatch_mode IN ('preflight', 'idempotent'), '', 'delivery_unknown'),
                 run_at = %s, updated_at = %s, lock_token = '', locked_until = NULL
             WHERE status = 'publishing' AND locked_until <= %s LIMIT 10",
            $now, $now, $now
        ));
        if ($recovered === false) {
            Database::storage_error('recover_leases');
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status IN ('queued','retry_wait') AND run_at <= %s ORDER BY run_at ASC, id ASC LIMIT 10", $now));
        if ($wpdb->last_error) {
            Database::storage_error('read_jobs');
            return;
        }
        foreach ($ids as $id) {
            if (!Security::can_send()) {
                break;
            }
            $token = bin2hex(random_bytes(16));
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'publishing', dispatch_mode = 'preflight', lock_token = %s, locked_until = %s, updated_at = %s WHERE id = %d AND status IN ('queued','retry_wait') AND run_at <= %s", $token, gmdate('Y-m-d H:i:s', time() + 300), current_time('mysql', true), $id, $now));
            if ($claimed === false) {
                Database::storage_error('claim_job');
                break;
            }
            if ($claimed !== 1) {
                continue;
            }
            if (!self::process((int) $id, $token)) {
                break;
            }
        }
    }

    private static function process(int $id, string $token): bool
    {
        global $wpdb;
        $jobs = Database::table('jobs');
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id = %d AND lock_token = %s", $id, $token), ARRAY_A);
        if (!$job) {
            if ($wpdb->last_error) {
                Database::storage_error('read_job');
            }
            return true;
        }
        if (!Security::can_send()) {
            self::finish($job, $token, 'retry_wait', '', '');
            return true;
        }
        $post = get_post((int) $job['post_id']);
        if (!$post || !self::can_publish($post)) {
            self::finish($job, $token, 'cancelled', '', '');
            return true;
        }
        $connection = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('connections') . ' WHERE id = %d AND active = 1', $job['connection_id']), ARRAY_A);
        if (!$connection) {
            if ($wpdb->last_error) {
                Database::storage_error('read_connection');
                return false;
            }
            self::finish($job, $token, 'blocked', '', 'connection');
            return true;
        }
        $snapshot = json_decode($job['snapshot'], true);
        if (!is_array($snapshot)) {
            self::finish($job, $token, 'permanent_failure', '', 'rejected');
            return true;
        }
        if (!Database::reserve_delivery()) {
            self::record($job, $token, ['status' => 'retry_wait', 'run_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
            return false;
        }
        $mode = !empty(Publisher::capabilities($connection['provider'])['idempotency_key']) ? 'idempotent' : 'single';
        $dispatch = $wpdb->query($wpdb->prepare(
            "UPDATE $jobs SET dispatch_mode = %s WHERE id = %d AND lock_token = %s AND locked_until > %s",
            $mode, $id, $token, current_time('mysql', true)
        ));
        if ($dispatch !== 1) {
            if ($dispatch === false) {
                Database::storage_error('start_delivery');
            }
            return false;
        }
        $result = Publisher::send($connection, $snapshot, $job['idem_key']);
        if ($result['ok']) {
            self::finish($job, $token, 'published', $result['remote_id'], '');
            return true;
        }
        $attempts = (int) $job['attempts'] + 1;
        if (!empty($result['unknown'])) {
            self::finish($job, $token, 'delivery_unknown', '', 'delivery_unknown', $attempts);
            return true;
        }
        if ($result['retry'] && $attempts < 5) {
            $delay = min(3600, (int) (60 * (2 ** ($attempts - 1)))) + random_int(0, 30);
            if (self::record($job, $token, [
                'status' => 'retry_wait', 'attempts' => $attempts,
                'run_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'error_code' => $result['code'],
            ])) {
                Database::audit('job_retry', 'job', $id);
            }
            return true;
        }
        self::finish($job, $token, 'permanent_failure', '', $result['code'], $attempts);
        return true;
    }

    private static function finish(array $job, string $token, string $status, string $remoteId, string $error, ?int $attempts = null): void
    {
        $data = ['status' => $status, 'remote_id' => $remoteId, 'error_code' => $error];
        if ($attempts !== null) {
            $data['attempts'] = $attempts;
        } elseif ($status === 'published') {
            $data['attempts'] = (int) $job['attempts'] + 1;
        }
        if (self::record($job, $token, $data)) {
            Database::audit('job_' . $status, 'job', (int) $job['id']);
        }
    }

    private static function record(array $job, string $token, array $data): bool
    {
        global $wpdb;
        $data += ['lock_token' => '', 'locked_until' => null, 'dispatch_mode' => '', 'updated_at' => current_time('mysql', true)];
        $updated = $wpdb->update(Database::table('jobs'), $data, ['id' => $job['id'], 'status' => 'publishing', 'lock_token' => $token]);
        if ($updated === false) {
            Database::storage_error('write_job_result');
        }
        return $updated === 1;
    }
}
