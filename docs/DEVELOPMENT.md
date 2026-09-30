# Development

PHP 8.1+ is required. The plugin intentionally has no runtime Composer dependencies. `composer.json` records the PHP requirement and development scripts; the distributed plugin uses its own PSR-4-compatible loader, so no `vendor/` tree is needed. `tests/run.php` can run without WordPress for domain and provider request checks. `tools/build.php` creates the installable ZIP from an explicit file list.

For real integration testing, install the plugin into a disposable WordPress site with WP-Cron enabled. Keep the publishing switch off until a mock HTTPS receiver or a controlled Telegram channel is configured. Use WordPress Site Health and debug tools to inspect cron availability and database installation, but never record secret-bearing URLs or payloads.
