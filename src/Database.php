<?php
namespace SocialRelay;

final class Database
{
    public const SCHEMA_VERSION = 2;

    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sr_' . $suffix;
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $connections = self::table('connections');
        $recipes = self::table('recipes');
        $jobs = self::table('jobs');
        $audit = self::table('audit');
        dbDelta("CREATE TABLE $connections (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            provider varchar(40) NOT NULL,
            surface varchar(190) NOT NULL,
            endpoint text NOT NULL,
            secret text NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY provider_active (provider,active)
        ) $charset;");
        dbDelta("CREATE TABLE $recipes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            post_type varchar(40) NOT NULL,
            profile varchar(40) NOT NULL DEFAULT 'article',
            objective varchar(40) NOT NULL DEFAULT 'traffic',
            connection_id bigint(20) unsigned NOT NULL,
            priority int NOT NULL DEFAULT 0,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            version int unsigned NOT NULL DEFAULT 1,
            rule_json longtext NOT NULL,
            template text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY type_enabled (post_type,enabled,priority),
            KEY connection_id (connection_id)
        ) $charset;");
        dbDelta("CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            idem_key char(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned NOT NULL,
            connection_id bigint(20) unsigned NOT NULL,
            status varchar(24) NOT NULL,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            run_at datetime NOT NULL,
            lock_token char(32) NOT NULL DEFAULT '',
            locked_until datetime DEFAULT NULL,
            snapshot longtext NOT NULL,
            remote_id varchar(190) NOT NULL DEFAULT '',
            error_code varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idem_key (idem_key),
            KEY due_jobs (status,run_at,id),
            KEY created_at (created_at),
            KEY post_id (post_id,id),
            KEY connection_id (connection_id,status)
        ) $charset;");
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(60) NOT NULL,
            object_type varchar(40) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            result varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY object_ref (object_type,object_id)
        ) $charset;");
        update_option('social_relay_schema', self::SCHEMA_VERSION, false);
        add_option('social_relay_enabled', '0', '', false);
        add_option('social_relay_allow_staging', '0', '', false);
        add_option('social_relay_remove_on_uninstall', '0', '', false);
        add_option('social_relay_origin_url', home_url('/'), '', false);
        if (!wp_next_scheduled('social_relay_worker')) {
            wp_schedule_event(time() + 60, 'social_relay_minute', 'social_relay_worker');
        }
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_social_relay');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('social_relay_worker');
    }

    public static function audit(string $action, string $type, int $id, string $result = 'ok'): void
    {
        global $wpdb;
        $wpdb->insert(self::table('audit'), [
            'actor_id' => get_current_user_id(),
            'action' => $action,
            'object_type' => $type,
            'object_id' => $id,
            'result' => $result,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);
    }
}
