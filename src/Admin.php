<?php
namespace SocialRelay;

final class Admin
{
    public static function menu(): void
    {
        add_menu_page(__('Social Relay', 'social-relay'), __('Social Relay', 'social-relay'), 'manage_social_relay', 'social-relay', [self::class, 'dashboard'], 'dashicons-share', 58);
        add_submenu_page('social-relay', __('Overview', 'social-relay'), __('Overview', 'social-relay'), 'manage_social_relay', 'social-relay', [self::class, 'dashboard']);
        add_submenu_page('social-relay', __('Destinations', 'social-relay'), __('Destinations', 'social-relay'), 'manage_social_relay', 'social-relay-destinations', [self::class, 'destinations']);
        add_submenu_page('social-relay', __('Recipes', 'social-relay'), __('Recipes', 'social-relay'), 'manage_social_relay', 'social-relay-recipes', [self::class, 'recipes']);
        add_submenu_page('social-relay', __('Publications', 'social-relay'), __('Publications', 'social-relay'), 'manage_social_relay', 'social-relay-jobs', [self::class, 'jobs']);
        add_submenu_page('social-relay', __('Field mapping', 'social-relay'), __('Field mapping', 'social-relay'), 'manage_social_relay', 'social-relay-mapping', [self::class, 'mapping']);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_contains($hook, 'social-relay')) {
                wp_enqueue_style('social-relay-admin', plugins_url('assets/admin.css', SOCIAL_RELAY_PATH . 'social-relay.php'), [], SOCIAL_RELAY_VERSION);
            }
        });
    }

    private static function header(string $title, string $description = ''): void
    {
        echo '<div class="wrap sr-wrap"><h1>' . esc_html($title) . '</h1>';
        if ($description !== '') {
            echo '<p class="sr-lead">' . esc_html($description) . '</p>';
        }
        $notice = isset($_GET['sr_notice']) ? sanitize_key(wp_unslash($_GET['sr_notice'])) : '';
        $messages = [
            'saved' => __('Saved successfully.', 'social-relay'),
            'invalid' => __('Could not save. Check the form values and destination settings, then try again.', 'social-relay'),
            'conflict' => __('This recipe changed in another session. Reload it before saving.', 'social-relay'),
            'updated' => __('Publication updated.', 'social-relay'),
        ];
        if (isset($messages[$notice])) {
            $class = $notice === 'saved' || $notice === 'updated' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' inline" role="status"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        echo '<nav class="sr-nav" aria-label="' . esc_attr__('Social Relay sections', 'social-relay') . '">';
        foreach (['social-relay' => __('Overview', 'social-relay'), 'social-relay-destinations' => __('Destinations', 'social-relay'), 'social-relay-recipes' => __('Recipes', 'social-relay'), 'social-relay-jobs' => __('Publications', 'social-relay'), 'social-relay-mapping' => __('Field mapping', 'social-relay')] as $slug => $label) {
            $current = isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === $slug;
            echo '<a ' . ($current ? 'aria-current="page" ' : '') . 'href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function footer(): void
    {
        echo '</div>';
    }

    private static function form_start(string $action): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="social_relay_action">';
        echo '<input type="hidden" name="sr_action" value="' . esc_attr($action) . '">';
        wp_nonce_field('social_relay_action');
    }

    public static function dashboard(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'));
        }
        global $wpdb;
        self::header(__('Social Relay', 'social-relay'), __('Set up a destination, create a recipe, then review publication activity.', 'social-relay'));
        $safe = Security::can_send();
        echo '<section class="sr-card sr-safety"><h2>' . esc_html__('Publishing safety', 'social-relay') . '</h2>';
        echo '<p class="sr-state ' . ($safe ? 'sr-on' : 'sr-off') . '">' . esc_html($safe ? __('Automatic publishing is on', 'social-relay') : __('Automatic publishing is paused', 'social-relay')) . '</p>';
        echo '<p>' . esc_html__('Eligible posts can still enter the queue while paused. Enabling publishing will send the queued jobs.', 'social-relay') . '</p>';
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'production') {
            echo '<p>' . esc_html(sprintf(__('Environment: %s. Staging requires a separate opt-in.', 'social-relay'), wp_get_environment_type())) . '</p>';
        }
        if (get_option('social_relay_origin_url', '') !== home_url('/')) {
            echo '<p class="notice notice-warning inline" role="status">' . esc_html__('The site URL changed since activation. External publishing is blocked until an administrator trusts this site location.', 'social-relay') . '</p>';
            self::form_start('trust_site_url');
            submit_button(__('Trust this site location', 'social-relay'), 'secondary', 'submit', false);
            echo '</form>';
        }
        if (get_transient('social_relay_intake_capped')) {
            echo '<p class="notice notice-warning inline" role="status">' . esc_html__('The safety limit of 100 new destination jobs per hour was reached. Additional first-publication events were skipped. Review imports and publish those items manually after the limit clears.', 'social-relay') . '</p>';
        }
        self::form_start('save_settings');
        echo '<label class="sr-check"><input type="checkbox" name="enabled" value="1" ' . checked(get_option('social_relay_enabled', '0'), '1', false) . '> ' . esc_html__('Enable external publishing', 'social-relay') . '</label>';
        echo '<label class="sr-check"><input type="checkbox" name="allow_staging" value="1" ' . checked(get_option('social_relay_allow_staging', '0'), '1', false) . '> ' . esc_html__('Allow publishing from non-production environments', 'social-relay') . '</label>';
        echo '<label class="sr-check"><input type="checkbox" name="remove_on_uninstall" value="1" ' . checked(get_option('social_relay_remove_on_uninstall', '0'), '1', false) . '> ' . esc_html__('Remove this site’s Social Relay data when the plugin is uninstalled', 'social-relay') . '</label><p class="description">' . esc_html__('Off by default. This does not delete remote posts.', 'social-relay') . '</p>';
        submit_button(__('Save safety settings', 'social-relay'), 'primary', 'submit', false);
        echo '</form></section>';
        $counts = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . Database::table('jobs') . ' GROUP BY status', ARRAY_A);
        $totals = [];
        foreach ($counts ?: [] as $row) {
            $totals[$row['status']] = (int) $row['total'];
        }
        echo '<section class="sr-card"><h2>' . esc_html__('Publication activity', 'social-relay') . '</h2><div class="sr-stats">';
        foreach (['queued' => __('Queued', 'social-relay'), 'retry_wait' => __('Retrying', 'social-relay'), 'published' => __('Accepted', 'social-relay'), 'permanent_failure' => __('Failed', 'social-relay'), 'blocked' => __('Blocked', 'social-relay')] as $key => $label) {
            echo '<div class="sr-stat"><strong>' . esc_html((string) ($totals[$key] ?? 0)) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div><p><a class="button" href="' . esc_url(admin_url('admin.php?page=social-relay-jobs')) . '">' . esc_html__('Review publications', 'social-relay') . '</a></p></section>';
        echo '<section class="sr-card"><h2>' . esc_html__('Get started', 'social-relay') . '</h2><ol class="sr-steps"><li><a href="' . esc_url(admin_url('admin.php?page=social-relay-destinations')) . '">' . esc_html__('Add a destination', 'social-relay') . '</a></li><li><a href="' . esc_url(admin_url('admin.php?page=social-relay-recipes')) . '">' . esc_html__('Create a recipe', 'social-relay') . '</a></li><li>' . esc_html__('Open a post to preview its matching recipes and choose whether to exclude it.', 'social-relay') . '</li><li>' . esc_html__('Enable publishing when the preview looks right.', 'social-relay') . '</li></ol></section>';
        self::footer();
    }

    public static function destinations(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'));
        }
        global $wpdb;
        self::header(__('Destinations', 'social-relay'), __('A destination is one webhook receiver or Telegram chat. Secrets are encrypted and never displayed again.', 'social-relay'));
        $rows = $wpdb->get_results('SELECT id,name,provider,surface,active,created_at FROM ' . Database::table('connections') . ' ORDER BY id DESC LIMIT 100', ARRAY_A);
        echo '<section class="sr-card"><h2>' . esc_html__('Connected destinations', 'social-relay') . '</h2>';
        if (!$rows) {
            echo '<p>' . esc_html__('No destinations yet.', 'social-relay') . '</p>';
        } else {
            echo '<div class="sr-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Name', 'social-relay') . '</th><th scope="col">' . esc_html__('Provider', 'social-relay') . '</th><th scope="col">' . esc_html__('Surface', 'social-relay') . '</th><th scope="col">' . esc_html__('State', 'social-relay') . '</th><th scope="col">' . esc_html__('Action', 'social-relay') . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><th scope="row">' . esc_html($row['name']) . '</th><td>' . esc_html($row['provider']) . '</td><td>' . esc_html($row['surface']) . '</td><td>' . esc_html($row['active'] ? __('Active', 'social-relay') : __('Disabled', 'social-relay')) . '</td><td>';
                self::form_start('toggle_connection');
                echo '<input type="hidden" name="id" value="' . esc_attr($row['id']) . '"><button class="button button-small" type="submit">' . esc_html($row['active'] ? __('Disable', 'social-relay') : __('Enable', 'social-relay')) . '</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section><section class="sr-card"><h2>' . esc_html__('Add destination', 'social-relay') . '</h2>';
        self::form_start('save_connection');
        echo '<p class="sr-field"><label for="sr-name">' . esc_html__('Name', 'social-relay') . '</label><input required maxlength="190" id="sr-name" name="name" class="regular-text" placeholder="' . esc_attr__('Newsroom webhook', 'social-relay') . '"></p>';
        echo '<p class="sr-field"><label for="sr-provider">' . esc_html__('Provider', 'social-relay') . '</label><select id="sr-provider" name="provider"><option value="webhook">' . esc_html__('Signed HTTPS webhook', 'social-relay') . '</option><option value="telegram">' . esc_html__('Telegram bot', 'social-relay') . '</option></select></p>';
        echo '<p class="sr-field"><label for="sr-surface">' . esc_html__('Surface or channel ID', 'social-relay') . '</label><input required maxlength="190" id="sr-surface" name="surface" class="regular-text"><span class="description">' . esc_html__('For Telegram, use a negative group/channel ID or public @channel. Private recipients are not supported. For webhooks, use a descriptive surface name.', 'social-relay') . '</span></p>';
        echo '<p class="sr-field"><label for="sr-endpoint">' . esc_html__('HTTPS endpoint (webhook only)', 'social-relay') . '</label><input type="url" id="sr-endpoint" name="endpoint" class="large-text" placeholder="https://example.com/receive"><span class="description">' . esc_html__('Local, private, and non-HTTPS addresses are rejected.', 'social-relay') . '</span></p>';
        echo '<p class="sr-field"><label for="sr-secret">' . esc_html__('HMAC secret or Telegram bot token', 'social-relay') . '</label><input required type="password" autocomplete="new-password" id="sr-secret" name="secret" class="regular-text" minlength="20"></p>';
        submit_button(__('Add destination', 'social-relay'));
        echo '</form></section>';
        self::footer();
    }

    public static function recipes(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'));
        }
        global $wpdb;
        $table = Database::table('recipes');
        $rows = $wpdb->get_results("SELECT r.*, c.name AS destination_name FROM $table r LEFT JOIN " . Database::table('connections') . ' c ON c.id = r.connection_id ORDER BY r.priority DESC, r.id DESC LIMIT 100', ARRAY_A);
        $editId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $edit = $editId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editId), ARRAY_A) : null;
        self::header(__('Recipes', 'social-relay'), __('Choose which content qualifies, where it goes, and the message it sends.', 'social-relay'));
        echo '<section class="sr-card"><h2>' . esc_html__('Existing recipes', 'social-relay') . '</h2>';
        if (!$rows) {
            echo '<p>' . esc_html__('No recipes yet. Create one below.', 'social-relay') . '</p>';
        } else {
            echo '<div class="sr-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Recipe', 'social-relay') . '</th><th scope="col">' . esc_html__('Post type', 'social-relay') . '</th><th scope="col">' . esc_html__('Destination', 'social-relay') . '</th><th scope="col">' . esc_html__('Priority', 'social-relay') . '</th><th scope="col">' . esc_html__('State', 'social-relay') . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><th scope="row"><a href="' . esc_url(admin_url('admin.php?page=social-relay-recipes&edit=' . $row['id'])) . '">' . esc_html($row['name']) . '</a></th><td>' . esc_html($row['post_type']) . '</td><td>' . esc_html($row['destination_name'] ?: __('Missing destination', 'social-relay')) . '</td><td>' . esc_html($row['priority']) . '</td><td>' . esc_html($row['enabled'] ? __('Enabled', 'social-relay') : __('Disabled', 'social-relay')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
        $rule = $edit ? json_decode($edit['rule_json'], true) : [];
        $conditions = $rule['all'] ?? [];
        $taxonomy = $term = $field = $fieldValue = '';
        $fieldOperator = 'eq';
        foreach ($conditions as $condition) {
            if (str_starts_with($condition['field'] ?? '', 'taxonomy:')) {
                $taxonomy = substr($condition['field'], 9);
                $term = (string) $condition['value'];
            } elseif (str_starts_with($condition['field'] ?? '', 'field:')) {
                $field = substr($condition['field'], 6);
                $fieldValue = (string) $condition['value'];
                $fieldOperator = (string) $condition['operator'];
            }
        }
        echo '<section class="sr-card"><h2>' . esc_html($edit ? __('Edit recipe', 'social-relay') : __('Create recipe', 'social-relay')) . '</h2>';
        self::form_start('save_recipe');
        echo '<input type="hidden" name="id" value="' . esc_attr($edit['id'] ?? '0') . '"><input type="hidden" name="version" value="' . esc_attr($edit['version'] ?? '0') . '">';
        self::input('name', __('Recipe name', 'social-relay'), $edit['name'] ?? '', true);
        echo '<div class="sr-grid"><p class="sr-field"><label for="sr-post-type">' . esc_html__('Post type', 'social-relay') . '</label><select name="post_type" id="sr-post-type">';
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            echo '<option value="' . esc_attr($type->name) . '" ' . selected($edit['post_type'] ?? 'post', $type->name, false) . '>' . esc_html($type->labels->singular_name) . '</option>';
        }
        echo '</select></p>';
        self::input('profile', __('Content profile', 'social-relay'), $edit['profile'] ?? 'article', true);
        self::input('objective', __('Objective', 'social-relay'), $edit['objective'] ?? 'traffic', true);
        echo '<p class="sr-field"><label for="sr-destination">' . esc_html__('Destination', 'social-relay') . '</label><select required name="connection_id" id="sr-destination"><option value="">' . esc_html__('Select destination', 'social-relay') . '</option>';
        $connections = $wpdb->get_results('SELECT id,name,provider FROM ' . Database::table('connections') . ' WHERE active = 1 ORDER BY name ASC LIMIT 100', ARRAY_A);
        foreach ($connections ?: [] as $connection) {
            echo '<option value="' . esc_attr($connection['id']) . '" ' . selected($edit['connection_id'] ?? '', $connection['id'], false) . '>' . esc_html($connection['name'] . ' (' . $connection['provider'] . ')') . '</option>';
        }
        echo '</select></p></div>';
        echo '<h3>' . esc_html__('Eligibility', 'social-relay') . '</h3><p class="description">' . esc_html__('All filled conditions must match. Leave optional fields empty to match every published item of the selected post type.', 'social-relay') . '</p><div class="sr-grid">';
        self::input('taxonomy', __('Taxonomy slug', 'social-relay'), $taxonomy);
        self::input('term', __('Required term slug', 'social-relay'), $term);
        self::input('field', __('Mapped field name', 'social-relay'), $field);
        echo '<p class="sr-field"><label for="sr-field-operator">' . esc_html__('Field condition', 'social-relay') . '</label><select id="sr-field-operator" name="field_operator">';
        foreach (['eq' => __('Equals', 'social-relay'), 'contains' => __('Contains', 'social-relay'), 'exists' => __('Has a value', 'social-relay'), 'gt' => __('Greater than', 'social-relay'), 'gte' => __('At least', 'social-relay'), 'lt' => __('Less than', 'social-relay'), 'lte' => __('At most', 'social-relay')] as $operator => $label) {
            echo '<option value="' . esc_attr($operator) . '" ' . selected($fieldOperator, $operator, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        self::input('field_value', __('Field value (leave empty for “Has a value”)', 'social-relay'), $fieldValue);
        echo '</div><h3>' . esc_html__('Message', 'social-relay') . '</h3><p class="sr-field"><label for="sr-template">' . esc_html__('Template', 'social-relay') . '</label><textarea required id="sr-template" name="template" rows="5" class="large-text">' . esc_textarea($edit['template'] ?? "{title}\n{excerpt}\n{url}") . '</textarea><span class="description">' . esc_html__('Tokens: {title}, {excerpt}, {body}, {url}, {author}, {date}, {profile}, and mapped field names such as {price}. Preview on a post before enabling publishing.', 'social-relay') . '</span></p>';
        echo '<div class="sr-grid">';
        self::input('priority', __('Priority', 'social-relay'), $edit['priority'] ?? '0');
        echo '<label class="sr-check"><input type="checkbox" name="enabled" value="1" ' . checked($edit['enabled'] ?? '1', '1', false) . '> ' . esc_html__('Recipe enabled', 'social-relay') . '</label></div>';
        submit_button($edit ? __('Save recipe', 'social-relay') : __('Create recipe', 'social-relay'));
        echo '</form></section>';
        self::footer();
    }

    private static function input(string $name, string $label, string $value, bool $required = false): void
    {
        echo '<p class="sr-field"><label for="sr-' . esc_attr($name) . '">' . esc_html($label) . '</label><input ' . ($required ? 'required ' : '') . 'id="sr-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text"></p>';
    }

    public static function jobs(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'));
        }
        global $wpdb;
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $table = Database::table('jobs');
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT j.id,j.post_id,j.status,j.attempts,j.run_at,j.remote_id,j.error_code,c.name AS destination FROM $table j LEFT JOIN " . Database::table('connections') . ' c ON c.id = j.connection_id ORDER BY j.id DESC LIMIT %d OFFSET %d', $limit, $offset), ARRAY_A);
        self::header(__('Publications', 'social-relay'), __('Each row is one destination job. “Accepted” means the endpoint or provider accepted the request.', 'social-relay'));
        echo '<section class="sr-card"><h2>' . esc_html__('Recent activity', 'social-relay') . '</h2>';
        if (!$rows) {
            echo '<p>' . esc_html__('No publications yet.', 'social-relay') . '</p>';
        } else {
            echo '<div class="sr-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Source', 'social-relay') . '</th><th scope="col">' . esc_html__('Destination', 'social-relay') . '</th><th scope="col">' . esc_html__('State', 'social-relay') . '</th><th scope="col">' . esc_html__('Attempts', 'social-relay') . '</th><th scope="col">' . esc_html__('Next run (UTC)', 'social-relay') . '</th><th scope="col">' . esc_html__('Details', 'social-relay') . '</th><th scope="col">' . esc_html__('Action', 'social-relay') . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $editUrl = get_edit_post_link((int) $row['post_id']);
                echo '<tr><th scope="row">' . ($editUrl ? '<a href="' . esc_url($editUrl) . '">' . esc_html(get_the_title((int) $row['post_id']) ?: '#' . $row['post_id']) . '</a>' : esc_html('#' . $row['post_id'])) . '</th><td>' . esc_html($row['destination'] ?: __('Removed destination', 'social-relay')) . '</td><td><strong>' . esc_html(str_replace('_', ' ', $row['status'])) . '</strong></td><td>' . esc_html($row['attempts']) . '</td><td>' . esc_html($row['run_at']) . '</td><td>' . esc_html($row['error_code'] ? Security::safe_error($row['error_code']) : ($row['remote_id'] ? __('Remote ID: ', 'social-relay') . $row['remote_id'] : '—')) . '</td><td>';
                if (in_array($row['status'], ['permanent_failure', 'blocked'], true)) {
                    self::form_start('retry_job');
                    echo '<input type="hidden" name="id" value="' . esc_attr($row['id']) . '"><button type="submit" class="button button-small">' . esc_html__('Retry', 'social-relay') . '</button></form>';
                } elseif (in_array($row['status'], ['queued', 'retry_wait'], true)) {
                    self::form_start('cancel_job');
                    echo '<input type="hidden" name="id" value="' . esc_attr($row['id']) . '"><button type="submit" class="button button-small">' . esc_html__('Cancel', 'social-relay') . '</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links(['base' => add_query_arg(['page' => 'social-relay-jobs', 'paged' => '%#%'], admin_url('admin.php')), 'format' => '', 'current' => $page, 'total' => max(1, (int) ceil($total / $limit))]));
            echo '</div></div>';
        }
        echo '</section>';
        self::footer();
    }

    public static function mapping(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'));
        }
        $mapping = get_option('social_relay_field_mapping', []);
        self::header(__('Field mapping', 'social-relay'), __('Expose selected custom fields to recipe rules and message templates. Values are read when content first publishes.', 'social-relay'));
        echo '<section class="sr-card"><h2>' . esc_html__('Current mapping', 'social-relay') . '</h2>';
        if (!$mapping) {
            echo '<p>' . esc_html__('No custom fields mapped.', 'social-relay') . '</p>';
        } else {
            echo '<ul>';
            foreach ($mapping as $postType => $fields) {
                foreach ($fields as $target => $source) {
                    echo '<li>' . esc_html($postType . ': {' . $target . '} ← ' . $source) . '</li>';
                }
            }
            echo '</ul>';
        }
        echo '</section><section class="sr-card"><h2>' . esc_html__('Add or replace a mapping', 'social-relay') . '</h2>';
        self::form_start('save_mapping');
        echo '<p class="sr-field"><label for="sr-map-type">' . esc_html__('Post type', 'social-relay') . '</label><select id="sr-map-type" name="post_type">';
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name !== 'attachment') {
                echo '<option value="' . esc_attr($type->name) . '">' . esc_html($type->labels->singular_name) . '</option>';
            }
        }
        echo '</select></p>';
        self::input('target', __('Template field name', 'social-relay'), '', true);
        self::input('source', __('WordPress meta key or ACF field name', 'social-relay'), '', true);
        submit_button(__('Save mapping', 'social-relay'));
        echo '</form></section>';
        self::footer();
    }

    public static function meta_boxes(): void
    {
        foreach (get_post_types(['public' => true], 'names') as $postType) {
            if ($postType !== 'attachment') {
                add_meta_box('social-relay-preview', __('Social Relay', 'social-relay'), [self::class, 'meta_box'], $postType, 'side', 'default');
            }
        }
    }

    public static function meta_box(\WP_Post $post): void
    {
        wp_nonce_field('social_relay_post_' . $post->ID, 'social_relay_post_nonce');
        echo '<p><label><input type="checkbox" name="social_relay_exclude" value="1" ' . checked(get_post_meta($post->ID, '_social_relay_exclude', true), '1', false) . '> ' . esc_html__('Exclude this item from automatic publishing', 'social-relay') . '</label></p>';
        if ($post->post_status !== 'publish') {
            echo '<p class="description">' . esc_html__('Preview uses the saved version. Save a draft to refresh it.', 'social-relay') . '</p>';
        }
        if (!Security::can_send()) {
            echo '<p class="description">' . esc_html__('Outbound publishing is paused by the site safety settings.', 'social-relay') . '</p>';
        }
        $items = Plugin::preview($post);
        if (!$items) {
            echo '<p>' . esc_html__('No enabled recipes match this post type.', 'social-relay') . '</p>';
        }
        foreach ($items as $item) {
            echo '<details class="sr-preview"><summary>' . esc_html($item['recipe']['name'] . ' — ' . ($item['eligible'] ? __('Eligible', 'social-relay') : __('Not eligible', 'social-relay'))) . '</summary>';
            echo '<p><strong>' . esc_html__('Destination: ', 'social-relay') . '</strong>' . esc_html($item['connection']['name'] ?? __('Unavailable', 'social-relay')) . '</p>';
            if ($item['excluded']) {
                echo '<p>' . esc_html__('Excluded on this post.', 'social-relay') . '</p>';
            }
            if (!$item['compatibility']['compatible']) {
                echo '<p role="status">' . esc_html(Security::safe_error($item['compatibility']['reason'])) . '</p>';
            }
            self::trace($item['trace']);
            echo '<p><strong>' . esc_html__('Message preview', 'social-relay') . '</strong></p><pre>' . esc_html($item['text']) . '</pre></details>';
        }
        if ($post->post_status === 'publish' && current_user_can('manage_social_relay')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=social_relay_queue_post&post_id=' . $post->ID), 'social_relay_queue_post_' . $post->ID);
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Queue eligible recipes', 'social-relay') . '</a></p><p class="description">' . esc_html__('Existing identical publications are not queued again.', 'social-relay') . '</p>';
        }
    }

    private static function trace(array $trace): void
    {
        echo '<ul class="sr-trace"><li>' . esc_html(($trace['pass'] ? __('Pass: ', 'social-relay') : __('Fail: ', 'social-relay')) . ($trace['type'] === 'condition' ? $trace['field'] . ' ' . $trace['operator'] . ' ' . (is_array($trace['expected']) ? implode(', ', $trace['expected']) : $trace['expected']) : strtoupper($trace['type']))) . '</li>';
        foreach ($trace['children'] ?? [] as $child) {
            self::trace($child);
        }
        echo '</ul>';
    }

    public static function save_post_exclusion(int $postId): void
    {
        if (!isset($_POST['social_relay_post_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['social_relay_post_nonce'])), 'social_relay_post_' . $postId) || !current_user_can('edit_post', $postId) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        update_post_meta($postId, '_social_relay_exclude', isset($_POST['social_relay_exclude']) ? '1' : '0');
    }

    public static function manual_queue(): void
    {
        $postId = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$postId || !current_user_can('manage_social_relay') || !current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You cannot queue this post.', 'social-relay'), '', ['response' => 403]);
        }
        check_admin_referer('social_relay_queue_post_' . $postId);
        $post = get_post($postId);
        if ($post) {
            Plugin::queue_post($post);
            Database::audit('manual_queue_requested', 'post', $postId);
        }
        wp_safe_redirect(admin_url('admin.php?page=social-relay-jobs'));
        exit;
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_social_relay')) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'), '', ['response' => 403]);
        }
        check_admin_referer('social_relay_action');
        $action = isset($_POST['sr_action']) ? sanitize_key(wp_unslash($_POST['sr_action'])) : '';
        $page = 'social-relay';
        $notice = 'invalid';
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
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $provider = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
            $surface = sanitize_text_field(wp_unslash($_POST['surface'] ?? ''));
            $endpoint = esc_url_raw(wp_unslash($_POST['endpoint'] ?? ''));
            $secret = (string) wp_unslash($_POST['secret'] ?? '');
            $valid = $name !== '' && strlen($name) <= 190 && strlen($surface) <= 190 && strlen($endpoint) <= 2048 && strlen($secret) >= 20 && strlen($secret) <= 1000;
            if ($provider === 'webhook') {
                $valid = $valid && $surface !== '' && Security::validate_endpoint($endpoint);
            } elseif ($provider === 'telegram') {
                $valid = $valid && preg_match('/^(?:-\d+|@[A-Za-z0-9_]{5,32})$/D', $surface) && preg_match('/^\d{5,12}:[A-Za-z0-9_-]{20,}$/D', $secret);
                $endpoint = '';
            } else {
                $valid = false;
            }
            if ($valid) {
                try {
                    $encrypted = Security::encrypt($secret);
                    $saved = $wpdb->insert(Database::table('connections'), ['name' => $name, 'provider' => $provider, 'surface' => $surface, 'endpoint' => $endpoint, 'secret' => $encrypted, 'active' => 1, 'created_at' => $now]);
                    if ($saved) {
                        Database::audit('connection_created', 'connection', (int) $wpdb->insert_id);
                        $notice = 'saved';
                    }
                } catch (\RuntimeException $exception) {
                    $notice = 'invalid';
                }
            }
        } elseif ($action === 'toggle_connection') {
            $page = 'social-relay-destinations';
            $id = absint($_POST['id'] ?? 0);
            $row = $wpdb->get_row($wpdb->prepare('SELECT active FROM ' . Database::table('connections') . ' WHERE id = %d', $id), ARRAY_A);
            if ($row && $wpdb->update(Database::table('connections'), ['active' => $row['active'] ? 0 : 1], ['id' => $id]) !== false) {
                Database::audit($row['active'] ? 'connection_disabled' : 'connection_enabled', 'connection', $id);
                $notice = 'saved';
            }
        } elseif ($action === 'save_recipe') {
            $page = 'social-relay-recipes';
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $postType = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));
            $profile = sanitize_key(wp_unslash($_POST['profile'] ?? ''));
            $objective = sanitize_key(wp_unslash($_POST['objective'] ?? ''));
            $connectionId = absint($_POST['connection_id'] ?? 0);
            $priority = max(-1000, min(1000, (int) ($_POST['priority'] ?? 0)));
            $taxonomy = sanitize_key(wp_unslash($_POST['taxonomy'] ?? ''));
            $term = sanitize_title(wp_unslash($_POST['term'] ?? ''));
            $field = sanitize_key(wp_unslash($_POST['field'] ?? ''));
            $fieldOperator = sanitize_key(wp_unslash($_POST['field_operator'] ?? 'eq'));
            $fieldValue = sanitize_text_field(wp_unslash($_POST['field_value'] ?? ''));
            $template = sanitize_textarea_field(wp_unslash($_POST['template'] ?? ''));
            $type = get_post_type_object($postType);
            $connection = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Database::table('connections') . ' WHERE id = %d AND active = 1', $connectionId));
            $conditions = [];
            if ($taxonomy !== '' && $term !== '' && taxonomy_exists($taxonomy) && is_object_in_taxonomy($postType, $taxonomy)) {
                $conditions[] = ['field' => 'taxonomy:' . $taxonomy, 'operator' => 'contains', 'value' => $term];
            } elseif ($taxonomy !== '' || $term !== '') {
                $type = null;
            }
            if ($field !== '' && ($fieldValue !== '' || $fieldOperator === 'exists') && in_array($fieldOperator, ['eq', 'contains', 'exists', 'gt', 'gte', 'lt', 'lte'], true)) {
                $conditions[] = ['field' => 'field:' . $field, 'operator' => $fieldOperator, 'value' => $fieldValue];
            } elseif ($field !== '' || $fieldValue !== '') {
                $type = null;
            }
            $rule = $conditions ? ['all' => $conditions] : ['field' => 'post_type', 'operator' => 'eq', 'value' => $postType];
            $count = 0;
            if ($type && $type->public && $connection && $name !== '' && strlen($name) <= 190 && $profile !== '' && $objective !== '' && $template !== '' && strlen($template) <= 5000 && Rules::validate($rule, 0, $count)) {
                $data = ['name' => $name, 'post_type' => $postType, 'profile' => $profile, 'objective' => $objective, 'connection_id' => $connectionId, 'priority' => $priority, 'enabled' => isset($_POST['enabled']) ? 1 : 0, 'rule_json' => wp_json_encode($rule), 'template' => $template, 'updated_at' => $now];
                $id = absint($_POST['id'] ?? 0);
                if ($id) {
                    $version = absint($_POST['version'] ?? 0);
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
                }
            }
        } elseif ($action === 'save_mapping') {
            $page = 'social-relay-mapping';
            $postType = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));
            $target = sanitize_key(wp_unslash($_POST['target'] ?? ''));
            $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
            $type = get_post_type_object($postType);
            if ($type && $type->public && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $target) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/D', $source)) {
                $mapping = get_option('social_relay_field_mapping', []);
                $mapping[$postType][$target] = $source;
                update_option('social_relay_field_mapping', $mapping, false);
                Database::audit('field_mapping_saved', 'settings', 0);
                $notice = 'saved';
            }
        } elseif ($action === 'retry_job' || $action === 'cancel_job') {
            $page = 'social-relay-jobs';
            $id = absint($_POST['id'] ?? 0);
            $jobs = Database::table('jobs');
            $allowed = $action === 'retry_job' ? "('permanent_failure','blocked')" : "('queued','retry_wait')";
            $status = $action === 'retry_job' ? 'queued' : 'cancelled';
            $result = $wpdb->query($wpdb->prepare("UPDATE $jobs SET status = %s, run_at = %s, error_code = '', updated_at = %s WHERE id = %d AND status IN $allowed", $status, $now, $now, $id));
            if ($result === 1) {
                Database::audit($action, 'job', $id);
                $notice = 'updated';
            }
        }
        wp_safe_redirect(add_query_arg(['page' => $page, 'sr_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
