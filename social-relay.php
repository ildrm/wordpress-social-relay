<?php
/**
 * Plugin Name: Social Relay
 * Plugin URI: https://github.com/ildrm/wordpress-social-relay
 * Description: Rules-driven, asynchronous publishing from WordPress to verified destinations.
 * Version: 0.1.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: social-relay
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SOCIAL_RELAY_VERSION', '0.1.0');
define('SOCIAL_RELAY_PATH', plugin_dir_path(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SocialRelay\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) {
        return;
    }
    $path = SOCIAL_RELAY_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [SocialRelay\Database::class, 'activate']);
register_deactivation_hook(__FILE__, [SocialRelay\Database::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    SocialRelay\Plugin::boot();
});
