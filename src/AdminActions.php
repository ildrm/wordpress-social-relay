<?php
namespace SocialRelay;

final class AdminActions
{
    public static function save_post_exclusion(int $postId): void
    {
        if (!wp_verify_nonce(sanitize_text_field(self::posted('social_relay_post_nonce')), 'social_relay_post_' . $postId) || !current_user_can('edit_post', $postId) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        update_post_meta($postId, '_social_relay_exclude', isset($_POST['social_relay_exclude']) ? '1' : '0');
    }

    public static function manual_queue(): void
    {
        $postId = absint(self::posted('post_id'));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$postId || !current_user_can('manage_social_relay') || !current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You cannot queue this post.', 'social-relay'), '', ['response' => 403]);
        }
        check_admin_referer('social_relay_queue_post_' . $postId);
        $post = get_post($postId);
        $result = $post && Plugin::can_publish($post) ? Plugin::reconcile_post($post, true) : false;
        if ($result !== false) {
            Database::audit('manual_queue_requested', 'post', $postId);
        }
        $notice = $result === false ? 'invalid' : ($result === 0 ? 'nothing_queued' : 'updated');
        wp_safe_redirect(add_query_arg(['page' => 'social-relay-jobs', 'sr_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'), '', ['response' => 403]);
        }
        check_admin_referer('social_relay_action');
        $action = sanitize_key(self::posted('sr_action'));
        $page = 'social-relay';
        $notice = 'invalid';
        $return = [];
        global $wpdb;
        $now = current_time('mysql', true);
        if ($action === 'save_settings') {
            update_option('social_relay_enabled', isset($_POST['enabled']) ? '1' : '0', false);
            update_option('social_relay_allow_staging', isset($_POST['allow_staging']) ? '1' : '0', false);
            update_option('social_relay_remove_on_uninstall', isset($_POST['remove_on_uninstall']) ? '1' : '0', false);
            Database::audit('safety_settings_changed', 'settings', 0);
            $notice = 'saved';
        } elseif ($action === 'trust_site_url') {
            update_option('social_relay_origin_url', home_url('/'), false);
            Database::audit('site_location_trusted', 'settings', 0);
            $notice = 'saved';
        } elseif ($action === 'save_connection') {
            $page = 'social-relay-destinations';
            $name = sanitize_text_field(self::posted('name'));
            $provider = sanitize_key(self::posted('provider'));
            $surface = sanitize_text_field(self::posted('surface'));
            if ($provider === 'whatsapp') {
                $surface = substr($surface, 0, strcspn($surface, '?#'));
            }
            $endpoint = esc_url_raw(self::posted('endpoint'), ['https']);
            $secret = self::posted('secret');
            if ($provider !== 'webhook') {
                $secret = trim($secret);
            }
            $descriptor = ProviderCatalog::get($provider);
            $valid = $descriptor && $name !== '' && Text::length($name) <= 190
                && strlen($endpoint) <= 2048 && (self::posted('endpoint') === '' || $endpoint !== '')
                && ConnectionConfig::valid_destination($provider, $surface, $endpoint)
                && ConnectionConfig::valid_secret($provider, $secret);
            $return = $descriptor ? ['provider' => $provider] : [];
            if ($valid) {
                try {
                    $encrypted = $descriptor['manual'] ? '' : Security::encrypt($secret);
                    $saved = $wpdb->insert(Database::table('connections'), ['name' => $name, 'provider' => $provider, 'surface' => $surface, 'endpoint' => $endpoint, 'secret' => $encrypted, 'active' => 1, 'created_at' => $now]);
                    if ($saved) {
                        Database::audit('connection_created', 'connection', (int) $wpdb->insert_id);
                        $notice = 'saved';
                        $return = [];
                    }
                } catch (\RuntimeException $exception) {
                    $notice = 'invalid';
                }
            }
        } elseif ($action === 'replace_secret') {
            $page = 'social-relay-destinations';
            $id = absint(self::posted('id'));
            $table = Database::table('connections');
            $provider = $wpdb->get_var($wpdb->prepare("SELECT provider FROM $table WHERE id = %d", $id));
            $secret = $provider === 'webhook' ? self::posted('secret') : trim(self::posted('secret'));
            $valid = is_string($provider) && $provider !== 'whatsapp' && ConnectionConfig::valid_secret($provider, $secret);
            if ($valid) {
                try {
                    $saved = $wpdb->update($table, ['secret' => Security::encrypt($secret)], ['id' => $id, 'provider' => $provider]);
                    if ($saved === 1) {
                        Database::audit('connection_secret_replaced', 'connection', $id);
                        $notice = 'saved';
                    }
                } catch (\RuntimeException $exception) {
                    $notice = 'invalid';
                }
            }
        } elseif ($action === 'toggle_connection') {
            $page = 'social-relay-destinations';
            $id = absint(self::posted('id'));
            $row = $wpdb->get_row($wpdb->prepare('SELECT active FROM ' . Database::table('connections') . ' WHERE id = %d', $id), ARRAY_A);
            $active = self::posted('active');
            if ($row && $active === '') {
                $active = $row['active'] ? '0' : '1';
            }
            if ($row && in_array($active, ['0', '1'], true)
                && $wpdb->update(Database::table('connections'), ['active' => (int) $active], ['id' => $id, 'active' => (int) $row['active']]) !== false) {
                Database::audit($active === '1' ? 'connection_enabled' : 'connection_disabled', 'connection', $id);
                $notice = 'saved';
            }
        } elseif ($action === 'save_recipe') {
            $page = 'social-relay-recipes';
            $id = absint(self::posted('id'));
            $return = $id ? ['edit' => $id] : ['create' => 1];
            $name = sanitize_text_field(self::posted('name'));
            $postType = sanitize_key(self::posted('post_type'));
            $profile = sanitize_key(self::posted('profile'));
            $objective = sanitize_key(self::posted('objective'));
            $connectionId = absint(self::posted('connection_id'));
            $priority = max(-1000, min(1000, (int) self::posted('priority')));
            $template = sanitize_textarea_field(self::posted('template'));
            $type = get_post_type_object($postType);
            $connections = Database::table('connections');
            $recipes = Database::table('recipes');
            $connection = $wpdb->get_var($wpdb->prepare(
                "SELECT c.id FROM $connections c WHERE c.id = %d AND (c.active = 1 OR EXISTS (SELECT 1 FROM $recipes r WHERE r.id = %d AND r.connection_id = c.id))",
                $connectionId, $id
            ));
            $rule = self::posted_rule($postType);
            $count = 0;
            if ($type && $type->public && $postType !== 'attachment' && is_post_type_viewable($type) && $connection
                && $name !== '' && Text::length($name) <= 190 && $profile !== '' && strlen($profile) <= 40
                && $objective !== '' && strlen($objective) <= 40 && $template !== '' && strlen($template) <= 20000 && Rules::validate($rule, 0, $count)) {
                $data = ['name' => $name, 'post_type' => $postType, 'profile' => $profile, 'objective' => $objective, 'connection_id' => $connectionId, 'priority' => $priority, 'enabled' => isset($_POST['enabled']) ? 1 : 0, 'rule_json' => wp_json_encode($rule), 'template' => $template, 'updated_at' => $now];
                if ($id) {
                    $version = absint(self::posted('version'));
                    $data['version'] = $version + 1;
                    $updated = $wpdb->update(Database::table('recipes'), $data, ['id' => $id, 'version' => $version]);
                    $notice = $updated === 1 ? 'saved' : 'conflict';
                } else {
                    $data['version'] = 1;
                    $data['created_at'] = $now;
                    $saved = $wpdb->insert(Database::table('recipes'), $data);
                    $id = (int) $wpdb->insert_id;
                    $notice = $saved ? 'saved' : 'invalid';
                }
                if ($notice === 'saved') {
                    Database::audit('recipe_saved', 'recipe', $id);
                    $return = [];
                }
            }
        } elseif (in_array($action, ['save_mapping', 'remove_mapping'], true)) {
            $page = 'social-relay-mapping';
            $postType = sanitize_key(self::posted('post_type'));
            $target = sanitize_key(self::posted('target'));
            $source = sanitize_text_field(self::posted('source'));
            $type = get_post_type_object($postType);
            $mapping = (array) get_option('social_relay_field_mapping', []);
            if ($action === 'remove_mapping' && isset($mapping[$postType][$target])) {
                unset($mapping[$postType][$target]);
                if (!$mapping[$postType]) {
                    unset($mapping[$postType]);
                }
                if (update_option('social_relay_field_mapping', $mapping, false)) {
                    Database::audit('field_mapping_removed', 'settings', 0);
                    $notice = 'removed';
                }
            } elseif ($action === 'save_mapping' && $type && $type->public && $postType !== 'attachment' && is_post_type_viewable($type)
                && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $target)
                && !in_array($target, ['title', 'excerpt', 'body', 'url', 'author', 'date', 'profile'], true)
                && preg_match('/^[A-Za-z0-9_\-]{1,100}$/D', $source)) {
                $unchanged = ($mapping[$postType][$target] ?? null) === $source;
                $mapping[$postType][$target] = $source;
                if ($unchanged || update_option('social_relay_field_mapping', $mapping, false)) {
                    Database::audit('field_mapping_saved', 'settings', 0);
                    $notice = 'saved';
                }
            }
        } elseif ($action === 'run_worker') {
            $page = 'social-relay-jobs';
            if (Security::can_send()) {
                $hook = 'social_relay_run_once';
                if (wp_next_scheduled($hook) || wp_schedule_single_event(time(), $hook)) {
                    spawn_cron();
                    $notice = 'worker_ran';
                }
            } else {
                $notice = 'worker_paused';
            }
        } elseif (in_array($action, ['retry_job', 'cancel_job', 'retry_unknown', 'confirm_delivery', 'confirm_manual'], true)) {
            $page = 'social-relay-jobs';
            $notice = self::update_job($action, absint(self::posted('id')), $now);
        }
        wp_safe_redirect(add_query_arg(['page' => $page, 'sr_notice' => $notice] + $return, admin_url('admin.php')));
        exit;
    }

    private static function posted(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
    }

    private static function posted_rule(string $postType): ?array
    {
        $advanced = trim(self::posted('rule_json'));
        if ($advanced !== '') {
            $rule = strlen($advanced) <= 24000 ? json_decode($advanced, true, 24) : null;
            return is_array($rule) ? $rule : null;
        }
        $taxonomy = sanitize_key(self::posted('taxonomy'));
        $term = sanitize_title(self::posted('term'));
        $field = sanitize_key(self::posted('field'));
        $operator = sanitize_key(self::posted('field_operator')) ?: 'eq';
        $value = sanitize_text_field(self::posted('field_value'));
        $conditions = [];
        if ($taxonomy !== '' || $term !== '') {
            if ($term === '' || !taxonomy_exists($taxonomy) || !is_object_in_taxonomy($postType, $taxonomy)) {
                return null;
            }
            $conditions[] = ['field' => 'taxonomy:' . $taxonomy, 'operator' => 'contains', 'value' => $term];
        }
        if ($field !== '' || $value !== '') {
            if ($field === '' || ($value === '' && $operator !== 'exists') || !in_array($operator, ['eq', 'neq', 'contains', 'exists', 'gt', 'gte', 'lt', 'lte'], true)) {
                return null;
            }
            $conditions[] = ['field' => 'field:' . $field, 'operator' => $operator, 'value' => $value];
        }
        return $conditions ? ['all' => $conditions] : ['field' => 'post_type', 'operator' => 'eq', 'value' => $postType];
    }

    private static function update_job(string $action, int $id, string $now): string
    {
        global $wpdb;
        $jobs = Database::table('jobs');
        if ($action === 'confirm_manual') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT j.post_id, c.surface FROM $jobs j INNER JOIN " . Database::table('connections') .
                " c ON c.id = j.connection_id WHERE j.id = %d AND j.status = 'awaiting_manual' AND c.provider = 'whatsapp' AND c.active = 1",
                $id
            ), ARRAY_A);
            $post = $row ? get_post((int) $row['post_id']) : null;
            if (!$post || !Plugin::can_publish($post) || !ConnectionConfig::valid_surface('whatsapp', $row['surface'])) {
                return 'invalid';
            }
        }
        [$allowed, $status] = match ($action) {
            'retry_job' => ["('permanent_failure','blocked')", 'queued'],
            'retry_unknown' => ["('delivery_unknown')", 'queued'],
            'confirm_delivery' => ["('delivery_unknown')", 'published'],
            'confirm_manual' => ["('awaiting_manual')", 'published'],
            'cancel_job' => ["('queued','retry_wait','awaiting_manual')", 'cancelled'],
        };
        $remote = $status === 'published' ? Text::truncate(sanitize_text_field(self::posted('remote_id')), 190) : '';
        $reset = $status === 'queued' ? ', attempts = 0' : ", provider_state = ''";
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $jobs SET status = %s, run_at = %s, remote_id = %s, error_code = '', updated_at = %s $reset WHERE id = %d AND status IN $allowed",
            $status, $now, $remote, $now, $id
        ));
        if ($result !== 1) {
            return 'invalid';
        }
        Database::audit($action, 'job', $id);
        return $action === 'confirm_manual' ? 'manual_confirmed' : 'updated';
    }
}
