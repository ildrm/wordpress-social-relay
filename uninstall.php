<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (get_option('social_relay_remove_on_uninstall', '0') !== '1') {
    return;
}

global $wpdb;
foreach (['connections', 'recipes', 'jobs', 'audit'] as $suffix) {
    $table = str_replace('`', '``', $wpdb->prefix . 'sr_' . $suffix);
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_social_relay_exclude'], ['%s']);
foreach (['social_relay_schema', 'social_relay_enabled', 'social_relay_allow_staging', 'social_relay_remove_on_uninstall', 'social_relay_origin_url', 'social_relay_field_mapping'] as $option) {
    delete_option($option);
}
delete_transient('social_relay_intake_capped');
wp_clear_scheduled_hook('social_relay_worker');
$role = get_role('administrator');
if ($role) {
    $role->remove_cap('manage_social_relay');
}
