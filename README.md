# Social Relay

Social Relay publishes public WordPress content to social destinations. A recipe selects a post type, a destination, a message template, and optional conditions. When a post first publishes, its matching recipes create background jobs with a saved snapshot of the content.

**Version 1.0.0** includes a redesigned WordPress admin workspace and 11 automatic social integrations.

- **Automatic publishing** to Facebook Pages, Instagram professional accounts, Telegram channels and groups, LinkedIn, Threads, Mastodon, Bluesky, Pinterest, X, Tumblr, and Reddit.
- **WhatsApp Channels** use a manual workflow. The plugin prepares the message, an administrator publishes it in WhatsApp, and the operator records the result in WordPress.
- **Signed HTTPS webhooks** send a JSON payload to your own receiver.
- **Publishing controls** include an initially paused queue, a per-post exclusion, destination-specific previews, conditional rules, custom field mapping, retry scheduling, and publication receipts.

## Set up

1. Use the installation ZIP or build it locally with `php tools/build.php`.
2. Open **Social Relay ? Destinations** and choose a network. Add the account, channel, or board and the credential shown in the setup form.
3. Create a recipe and open a saved WordPress post to check its message and conditions.
4. Enable automatic publication in **Overview ? Publishing settings** when your queue is ready. Eligible automatic jobs can accumulate while publication is paused.
5. Use **Publications** to review accepted jobs, failures, and WhatsApp items awaiting your action.

Requires WordPress 6.4 or later, PHP 8.1 or later, OpenSSL, and working WP-Cron. Automatic publishing requires credentials and permissions for each network you enable.

- [Installation and upgrades](docs/INSTALLATION.md)
- [Provider setup and capabilities](docs/providers/README.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Privacy](docs/PRIVACY.md)
- [Security](docs/SECURITY.md)
- [Verification](docs/TESTING.md)
