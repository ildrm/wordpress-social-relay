<?php
namespace SocialRelay;

final class Database
{
    public const SCHEMA_VERSION = 3;

    public static function ready(): bool
    {
        return (int) get_option('social_relay_schema', 0) === self::SCHEMA_VERSION
            && !get_option('social_relay_migration_error');
    }

    public static function storage_error(string $operation): void
    {
        update_option('social_relay_storage_error', $operation, false);
    }

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
        $rate = self::table('rate');
        $suppress = $wpdb->suppress_errors(true);
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
            dispatch_mode varchar(12) NOT NULL DEFAULT '',
            snapshot longtext NOT NULL,
            remote_id varchar(190) NOT NULL DEFAULT '',
            error_code varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idem_key (idem_key),
            KEY due_jobs (status,run_at,id),
            KEY expired_jobs (status,locked_until,id),
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
        dbDelta("CREATE TABLE $rate (
            id tinyint unsigned NOT NULL,
            next_slot bigint unsigned NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");
        $tables = [
            $connections => ['id name provider surface endpoint secret active created_at', ['primary' => 'id', 'provider_active' => 'provider active']],
            $recipes => ['id name post_type profile objective connection_id priority enabled version rule_json template created_at updated_at', ['primary' => 'id', 'type_enabled' => 'post_type enabled priority', 'connection_id' => 'connection_id']],
            $jobs => ['id idem_key post_id recipe_id connection_id status attempts run_at lock_token locked_until dispatch_mode snapshot remote_id error_code created_at updated_at', ['primary' => 'id', 'idem_key' => 'idem_key', 'due_jobs' => 'status run_at id', 'expired_jobs' => 'status locked_until id', 'created_at' => 'created_at', 'post_id' => 'post_id id', 'connection_id' => 'connection_id status']],
            $audit => ['id actor_id action object_type object_id result created_at', ['primary' => 'id', 'created_at' => 'created_at', 'object_ref' => 'object_type object_id']],
            $rate => ['id next_slot', ['primary' => 'id']],
        ];
        $valid = true;
        foreach ($tables as $table => [$columns, $indexes]) {
            if (!self::table_matches($table, explode(' ', $columns), $indexes)) {
                $valid = false;
                break;
            }
        }
        $valid = $valid && false !== $wpdb->query("INSERT INTO $rate (id, next_slot) VALUES (1, 0) ON DUPLICATE KEY UPDATE id = id");
        $wpdb->suppress_errors($suppress);
        if ($valid) {
            update_option('social_relay_schema', self::SCHEMA_VERSION, false);
            delete_option('social_relay_migration_error');
        } else {
            update_option('social_relay_migration_error', '1', false);
        }
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

    private static function table_matches(string $table, array $columns, array $indexes): bool
    {
        global $wpdb;
        $actual = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        if (array_diff($columns, $actual)) {
            return false;
        }
        $keys = [];
        foreach ($wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A) ?: [] as $row) {
            $name = strtolower($row['Key_name']);
            $unique = $name === 'primary' || $name === 'idem_key';
            if ($row['Sub_part'] !== null || ($unique && (int) $row['Non_unique'] !== 0)) {
                continue;
            }
            $keys[$name][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($indexes as $name => $fields) {
            $actual = $keys[$name] ?? [];
            ksort($actual);
            if (array_values($actual) !== explode(' ', $fields)) {
                return false;
            }
        }
        return true;
    }

    /** Reserve one of ten burst slots, replenished at one every 36 seconds across all workers. */
    public static function reserve_delivery(): bool
    {
        global $wpdb;
        $rate = self::table('rate');
        $updated = $wpdb->query("UPDATE $rate
            SET next_slot = GREATEST(next_slot, UNIX_TIMESTAMP()) + 36
            WHERE id = 1 AND next_slot <= UNIX_TIMESTAMP() + 9 * 36");
        if ($updated === false) {
            self::storage_error('delivery_rate');
        }
        return $updated === 1;
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('social_relay_worker');
    }

    public static function audit(string $action, string $type, int $id, string $result = 'ok'): void
    {
        global $wpdb;
        $written = $wpdb->insert(self::table('audit'), [
            'actor_id' => get_current_user_id(),
            'action' => $action,
            'object_type' => $type,
            'object_id' => $id,
            'result' => $result,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);
        if ($written !== 1) {
            self::storage_error('write_audit');
        }
    }
}
