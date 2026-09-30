# Testing

Run `php tests/run.php` for focused behavior checks and lint every PHP file with `php -l`. The GitHub workflow repeats these checks on PHP 8.1, 8.2, and 8.3. Build the distributable with `php tools/build.php`.

The focused test suite uses WordPress stubs. It covers nested rule evaluation and trace, the laptop-sale exclusions, template field allowlisting, URL scheme/local-host checks, AES-GCM round trip, signed webhook request shape, 503/429 classification, and Telegram response parsing. It is not a WordPress integration test. The local PHP CLI prints a duplicate OpenSSL module warning from its environment configuration; test assertions still pass.

Before a release, add tests in a running WordPress instance for migration, CPTs, taxonomy mapping, WooCommerce, nonce/capability enforcement, WP-Cron scheduling, concurrent workers, retry recovery, multisite separation, Gutenberg/Classic Editor behavior, accessibility, and high-volume queries. Real-provider tests must use opt-in credentials and never run in public CI.

`php tests/wp-smoke.php /path/to/wordpress` performs a read-only WordPress bootstrap and content normalization check. The two local WordPress installations in this workspace returned a database connection error, so this check could not complete here.
