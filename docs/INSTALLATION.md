# Installation

1. Build `dist/social-relay-0.1.0.zip` with `php tools/build.php`, or copy the plugin folder into `wp-content/plugins/social-relay`.
2. Install through **Plugins → Add New → Upload Plugin** and activate.
3. Open **Social Relay → Overview**. Publishing is paused by default.
4. Add a destination, create a recipe, and inspect the post preview before enabling publishing.
5. For predictable queue timing, run WordPress cron from a system scheduler. WP-Cron alone runs when the site receives traffic.

The plugin requires WordPress 6.4+, PHP 8.1+, OpenSSL, and a database user allowed to create/alter the plugin's site-prefixed tables. If a site is cloned, staging protection prevents outbound sends unless the administrator enables that environment explicitly. Changing WordPress auth salts makes encrypted connection secrets unreadable; save replacement connections after a salt change.

On deactivation, the scheduled worker is removed and data is preserved. On uninstall, data is preserved unless **Remove this site's Social Relay data when the plugin is uninstalled** was explicitly selected. External posts are never deleted by uninstall.
