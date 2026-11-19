# Installation

1. Build `dist/social-relay-0.1.0.zip` with `php tools/build.php`, or copy the plugin folder into `wp-content/plugins/social-relay`.
2. Install through **Plugins → Add New → Upload Plugin** and activate.
3. Open **Social Relay → Overview**. Publishing is paused by default.
4. Add a destination, create a recipe, and inspect the post preview before enabling publishing.
5. For predictable queue timing, run WordPress cron from a system scheduler. WP-Cron alone runs when the site receives traffic.

The plugin requires WordPress 6.4+, PHP 8.1+, OpenSSL, and a database user allowed to create and alter the plugin's site-prefixed tables. A cloned site needs an explicit staging opt-in before publishing.

## Reconcile imports

Run `wp social-relay reconcile --from=YYYY-MM-DD --through=YYYY-MM-DD` for a UTC date range to count missing jobs using the current posts and recipes. Pause publishing and add `--apply` to queue missing jobs. Review those jobs before resuming delivery. Reconciliation preserves existing jobs for a post, recipe, and destination, even if the post content has changed.

## Encryption key rotation

By default Social Relay can read and write credentials derived from WordPress auth salts. To store credentials independently, configure `SOCIAL_RELAY_ENCRYPTION_KEYS` in `wp-config.php` as an array of key IDs mapped to independent, random 32-byte keys encoded as 64 hexadecimal characters. Set `SOCIAL_RELAY_ENCRYPTION_KEY_ID` to the active ID. Keep keys outside database backups and preserve them with other deployment secrets.

Run `wp social-relay rotate-secrets` to confirm each saved credential can be read. Add `--apply` to re-encrypt with the active key. Keep old keys and salts available until every credential is rotated. If an old key is unavailable, use **Replace secret** on that destination to preserve its connections to recipes and jobs.

On deactivation, the scheduled worker is removed and data is preserved. On uninstall, data is preserved unless **Remove this site's Social Relay data when the plugin is uninstalled** was explicitly selected. External posts are never deleted by uninstall.
