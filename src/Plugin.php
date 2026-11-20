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
        add_action('social_relay_run_once', [self::class, 'work']);
        add_action('social_relay_deferred_product', [self::class, 'deferred_product']);
        add_action('save_post', [AdminActions::class, 'save_post_exclusion']);
        if (is_admin()) {
            add_action('admin_menu', [Admin::class, 'menu']);
            add_action('admin_post_social_relay_action', [AdminActions::class, 'handle']);
            add_action('admin_post_social_relay_queue_post', [AdminActions::class, 'manual_queue']);
            add_action('add_meta_boxes', [Admin::class, 'meta_boxes']);
            add_action('admin_init', static function (): void {
                if (!Database::ready()) {
                    Database::activate();
                }
            });
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('social-relay', Cli::class);
        }
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
                    self::reconcile_post($post, true);
                }
            }
            return;
        }
        self::reconcile_post($post, true);
    }

    public static function deferred_product(int $postId): void
    {
        $post = get_post($postId);
        if ($post) {
            self::reconcile_post($post, true);
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
            $compatibility = $connection && $connection['active']
                ? Publisher::compatible($connection, self::message($content, $text))
                : ['compatible' => false, 'reason' => 'connection'];
            $out[] = [
                'recipe' => $recipe,
                'connection' => $connection,
                'content' => $content,
                'trace' => $trace,
                'eligible' => $publishable && $trace['pass'] && $text !== '' && $compatibility['compatible'],
                'matches' => $trace['pass'] && $text !== '' && $compatibility['compatible'],
                'compatibility' => $compatibility,
                'excluded' => $excluded,
                'text' => $text,
            ];
        }
        return $out;
    }

    private static function message(array $content, string $text): array
    {
        return [
            'title' => $content['title'], 'url' => $content['url'], 'text' => $text,
            'featured_image' => $content['featured_image'] ?? '',
            'featured_image_mime' => $content['featured_image_mime'] ?? '',
            'featured_image_alt' => $content['featured_image_alt'] ?? '',
        ];
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
        $keys = [];
        if ($missing_only) {
            $prior = $wpdb->get_results($wpdb->prepare("SELECT recipe_id, connection_id, idem_key FROM $jobs WHERE post_id = %d", $post->ID), ARRAY_A);
            if ($wpdb->last_error) {
                Database::storage_error('enqueue');
                return false;
            }
            foreach ($prior as $row) {
                $keys[$row['idem_key']] = true;
                $existing[$row['recipe_id'] . ':' . $row['connection_id']] = true;
            }
        }
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
                ++$count;
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
                'trace' => $item['trace'],
            ] + self::message($content, $item['text']);
            $status = !empty(Publisher::capabilities($connection['provider'])['manual']) ? 'awaiting_manual' : 'queued';
            $json = wp_json_encode($snapshot);
            $result = $json === false ? false : $wpdb->query($wpdb->prepare(
                "INSERT INTO $jobs (idem_key, post_id, recipe_id, connection_id, status, run_at, snapshot, created_at, updated_at)
                 VALUES (%s, %d, %d, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id",
                $key, $post->ID, $recipe['id'], $recipe['connection_id'], $status, $now, $json, $now, $now
            ));
            if ($result === 1) {
                ++$count;
                Database::audit('job_' . $status, 'job', (int) $wpdb->insert_id);
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
        DeliveryWorker::run();
    }
}
