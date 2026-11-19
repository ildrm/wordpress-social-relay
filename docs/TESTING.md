# Testing

Run `php tests/run.php` for focused behavior checks and lint every PHP file with `php -l`. The GitHub workflow repeats these checks on PHP 8.1, 8.2, and 8.3. Build the distributable with `php tools/build.php`.

The focused test suite uses WordPress stubs. It covers nested rules, template fields, HTTPS endpoint validation, AES-GCM encryption, webhook signing, retryable HTTP responses and Telegram response parsing.

`php tests/wordpress.php /path/to/disposable-wordpress` exercises the publish hook, queue persistence, dispatch throttling, interrupted migration, legacy key support and ambiguous delivery using real WordPress and MariaDB. It requires a loopback WordPress installation with `WP_ENVIRONMENT_TYPE=local`, the plugin active and an empty queue. The suite creates posts and jobs and intercepts outbound HTTP responses.

`php tests/wp-smoke.php /path/to/wordpress` performs a read-only WordPress bootstrap and content normalization check.
