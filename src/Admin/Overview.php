<?php
namespace SocialRelay\Admin;

use SocialRelay\Database;
use SocialRelay\Security;

final class Overview extends Page
{
    public static function render(): void
    {
        self::start(
            'social-relay',
            __('Overview', 'social-relay'),
            __('Manage where WordPress content goes and track each publication.', 'social-relay'),
            [self::url('social-relay-destinations', ['view' => 'catalog']) . '#sr-catalog', __('Add destination', 'social-relay')]
        );
        global $wpdb;
        $connections = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::table('connections') . ' WHERE active = 1');
        $recipes = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::table('recipes') . ' r INNER JOIN ' . Database::table('connections') . ' c ON r.connection_id = c.id WHERE r.enabled = 1 AND c.active = 1');
        $statuses = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . Database::table('jobs') . ' GROUP BY status', ARRAY_A);
        $totals = array_column($statuses ?: [], 'total', 'status');
        $pending = ($totals['queued'] ?? 0) + ($totals['retry_wait'] ?? 0) + ($totals['publishing'] ?? 0);
        $attention = ($totals['awaiting_manual'] ?? 0) + ($totals['delivery_unknown'] ?? 0) + ($totals['blocked'] ?? 0) + ($totals['permanent_failure'] ?? 0);
        $safe = Security::can_send();
        $settingsUrl = self::url('social-relay', ['settings' => '1']) . '#sr-publishing-settings';
        $recent = $wpdb->get_results('SELECT j.id,j.post_id,j.status,j.updated_at,c.name AS destination FROM ' . Database::table('jobs') . ' j LEFT JOIN ' . Database::table('connections') . ' c ON c.id = j.connection_id ORDER BY j.id DESC LIMIT 5', ARRAY_A);
        $metrics = [
            ['dashicons-admin-links', __('Active destinations', 'social-relay'), $connections, self::url('social-relay-destinations')],
            ['dashicons-clock', __('In queue', 'social-relay'), $pending, self::url('social-relay-jobs', ['status' => 'pending'])],
            ['dashicons-yes-alt', __('Published', 'social-relay'), (int) ($totals['published'] ?? 0), self::url('social-relay-jobs', ['status' => 'published'])],
            ['dashicons-flag', __('Needs attention', 'social-relay'), $attention, self::url('social-relay-jobs', ['status' => 'attention'])],
        ];
        ?>
        <section class="sr-broadcast" aria-labelledby="sr-state-title">
            <div class="sr-broadcast-content">
                <?php echo self::badge($safe ? __('Publishing enabled', 'social-relay') : __('Publishing paused', 'social-relay'), $safe ? 'success' : 'warning'); ?>
                <h2 id="sr-state-title"><?php echo esc_html($safe ? __('New posts can go out automatically.', 'social-relay') : __('Set up your publishing workflow.', 'social-relay')); ?></h2>
                <p><?php echo esc_html($safe ? __('Enabled recipes send eligible WordPress posts to their destinations on first publication.', 'social-relay') : __('Connect a destination and create a recipe. Eligible posts can queue while publishing is paused.', 'social-relay')); ?></p>
                <a class="sr-button sr-on-dark" href="<?php echo esc_url($settingsUrl); ?>"><?php echo self::icon('dashicons-admin-generic'); ?><?php esc_html_e('Publishing settings', 'social-relay'); ?></a>
            </div>
            <div class="sr-signal" aria-hidden="true">
                <svg viewBox="0 0 396 190" xmlns="http://www.w3.org/2000/svg" fill="none" focusable="false">
                    <defs><pattern id="sr-signal-grid" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="1" fill="#8595e4" fill-opacity=".35"/></pattern></defs>
                    <rect width="396" height="190" fill="url(#sr-signal-grid)"/>
                    <path d="M97 95h96c19 0 30-12 30-28V49c0-12 9-20 22-20h87M97 95h235M97 95h96c19 0 30 12 30 28v18c0 12 9 20 22 20h87" stroke="#7f98ff" stroke-width="2"/>
                    <path d="M124 95h25M258 29h22M258 161h22M263 95h19" stroke="#c4d1ff" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="224" cy="95" r="5" fill="#e2eaff"/>
                    <circle cx="224" cy="95" r="11" stroke="#a2b6ff" stroke-opacity=".5"/>
                </svg>
                <span class="sr-signal-node sr-signal-source"><?php echo self::icon('dashicons-wordpress'); ?></span>
                <span class="sr-signal-node sr-signal-first"><?php echo self::icon('dashicons-format-chat'); ?></span>
                <span class="sr-signal-node sr-signal-second"><?php echo self::icon('dashicons-format-image'); ?></span>
                <span class="sr-signal-node sr-signal-third"><?php echo self::icon('dashicons-format-status'); ?></span>
            </div>
        </section>
        <section class="sr-metrics" aria-label="<?php esc_attr_e('Publishing metrics', 'social-relay'); ?>">
            <?php foreach ($metrics as [$icon, $label, $value, $href]) : ?>
                <a class="sr-metric" href="<?php echo esc_url($href); ?>">
                    <span class="sr-metric-top"><span class="sr-icon-box"><?php echo self::icon($icon); ?></span><?php echo self::icon('dashicons-arrow-right-alt2'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($value)); ?></strong>
                    <span><?php echo esc_html($label); ?></span>
                </a>
            <?php endforeach; ?>
        </section>
        <div class="sr-overview-grid">
            <section class="sr-card">
                <div class="sr-card-head">
                    <h2><?php esc_html_e('Recent publications', 'social-relay'); ?></h2>
                    <a class="sr-text-link" href="<?php echo esc_url(self::url('social-relay-jobs')); ?>"><?php esc_html_e('View all', 'social-relay'); ?> <?php echo self::icon('dashicons-arrow-right-alt2'); ?></a>
                </div>
                <?php if ($recent) : ?>
                    <ul class="sr-list sr-activity">
                        <?php foreach ($recent as $row) : ?>
                            <?php $title = current_user_can('edit_post', (int) $row['post_id']) ? get_the_title((int) $row['post_id']) : ''; ?>
                            <li>
                                <span class="sr-icon-box"><?php echo self::icon('dashicons-media-document'); ?></span>
                                <div class="sr-list-body">
                                    <a href="<?php echo esc_url(self::url('social-relay-jobs', ['job' => $row['id']]) . '#sr-job-' . $row['id']); ?>"><?php echo esc_html($title ?: sprintf(__('Post #%s', 'social-relay'), $row['post_id'])); ?></a>
                                    <small><?php echo esc_html($row['destination'] ?: __('Unavailable destination', 'social-relay')); ?></small>
                                </div>
                                <?php echo self::status($row['status']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else :
                    self::empty_state('dashicons-list-view', __('Your activity starts here', 'social-relay'), __('When a post matches an enabled recipe, its destination job appears here.', 'social-relay'));
                endif; ?>
            </section>
            <section class="sr-card">
                <div class="sr-card-head"><h2><?php esc_html_e('Publishing checklist', 'social-relay'); ?></h2></div>
                <ol class="sr-list sr-checklist">
                    <?php
                    $steps = [
                        [$connections > 0, self::url('social-relay-destinations', ['view' => 'catalog']) . '#sr-catalog', __('Connect a destination', 'social-relay'), __('Choose a network and add credentials.', 'social-relay')],
                        [$recipes > 0, self::url('social-relay-recipes', ['create' => '1']) . '#sr-recipe-editor', __('Create a recipe', 'social-relay'), __('Choose the content, destination, and message.', 'social-relay')],
                        [$safe, $settingsUrl, __('Enable publishing', 'social-relay'), __('Preview a post first, then turn on delivery.', 'social-relay')],
                    ];
                    foreach ($steps as [$done, $href, $title, $help]) : ?>
                        <li>
                            <span class="sr-icon-box <?php echo $done ? 'sr-step-done' : ''; ?>"><?php echo self::icon($done ? 'dashicons-yes' : 'dashicons-marker'); ?></span>
                            <div class="sr-list-body">
                                <a href="<?php echo esc_url($href); ?>"><?php echo esc_html($title); ?><span class="screen-reader-text"><?php echo esc_html($done ? __(' (complete)', 'social-relay') : __(' (pending)', 'social-relay')); ?></span></a>
                                <small><?php echo esc_html($help); ?></small>
                            </div>
                            <?php echo self::icon('dashicons-arrow-right-alt2'); ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>
        <?php self::settings(); ?>
        <?php self::end();
    }

    private static function settings(): void
    {
        $trusted = get_option('social_relay_origin_url', '') === home_url('/');
        $environment = wp_get_environment_type();
        ?>
        <details class="sr-card sr-settings" id="sr-publishing-settings" <?php if (!$trusted || self::query('settings') === '1') : ?>open<?php endif; ?>>
            <summary><?php echo self::icon('dashicons-admin-generic'); ?><?php esc_html_e('Publishing settings', 'social-relay'); ?></summary>
            <div class="sr-settings-body">
                <?php if (!$trusted) : ?>
                    <div class="sr-notice sr-notice-error" role="alert"><p><?php esc_html_e('The site URL changed. Trust this location before resuming external publication.', 'social-relay'); ?></p></div>
                    <?php self::form('trust_site_url'); ?>
                    <?php self::button(__('Trust this site location', 'social-relay')); ?>
                    </form>
                <?php endif; ?>
                <?php if ($environment !== 'production') : ?>
                    <p><?php echo esc_html(sprintf(__('WordPress environment: %s.', 'social-relay'), $environment)); ?></p>
                <?php endif; ?>
                <?php self::form('save_settings'); ?>
                <label class="sr-check">
                    <input type="checkbox" name="enabled" value="1" <?php checked(get_option('social_relay_enabled', '0'), '1'); ?>>
                    <span><strong><?php esc_html_e('Enable external publishing', 'social-relay'); ?></strong><small><?php esc_html_e('Send queued and future jobs when recipes match.', 'social-relay'); ?></small></span>
                </label>
                <label class="sr-check">
                    <input type="checkbox" name="allow_staging" value="1" <?php checked(get_option('social_relay_allow_staging', '0'), '1'); ?>>
                    <span><strong><?php esc_html_e('Allow sending from non-production sites', 'social-relay'); ?></strong><small><?php esc_html_e('Enable outbound delivery for staging, local, and development environments.', 'social-relay'); ?></small></span>
                </label>
                <label class="sr-check">
                    <input type="checkbox" name="remove_on_uninstall" value="1" <?php checked(get_option('social_relay_remove_on_uninstall', '0'), '1'); ?>>
                    <span><strong><?php esc_html_e('Remove local Social Relay data on uninstall', 'social-relay'); ?></strong><small><?php esc_html_e('Delete this site’s destinations, recipes, and history when the plugin is uninstalled. Published posts stay on their networks.', 'social-relay'); ?></small></span>
                </label>
                <div class="sr-form-actions"><?php self::button(__('Save settings', 'social-relay'), 'sr-primary'); ?></div>
                </form>
            </div>
        </details>
        <?php
    }
}
