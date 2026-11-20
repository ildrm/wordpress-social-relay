# Installation and upgrade

1. Build `dist/social-relay-1.0.0.zip` with `php tools/build.php` or use a built release ZIP.
2. In WordPress, go to **Plugins ? Add New Plugin ? Upload Plugin**, install the ZIP, and activate **Social Relay**. If you maintain the files directly, the plugin folder is `wp-content/plugins/social-relay`.
3. Open **Social Relay ? Destinations**. Use the [provider setup guide](providers/README.md) to select the correct account and permissions.
4. Create a recipe, preview it on a saved post, and inspect any queued work before turning on automatic publishing. Non-production sites require a separate opt-in. A copied site whose URL has changed requires approval in the publishing settings.
5. For predictable processing on low-traffic sites, configure a system scheduler to invoke WP-Cron regularly.

Back up the database and plugin directory before updating from 0.1.0. Upload the new ZIP through WordPress and choose **Replace current with uploaded**. Open an admin page to trigger schema verification. The migration adds a delivery-state column, and existing settings, connections, recipes, encrypted credentials, and publication history are read in place. Review **Overview** for database errors and confirm your existing recipes before resuming publication.
