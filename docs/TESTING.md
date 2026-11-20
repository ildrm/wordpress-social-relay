# Verification

Run `composer test` for rule, template, credential, HTTP, and provider contract tests. The contract tests use stubbed responses and check payloads, response IDs, rate limits, media staging, manual WhatsApp handling, and ambiguous deliveries. Run `php -l` on shipped PHP files and `node --check assets/admin.js` for asset syntax.

Use a disposable loopback WordPress site with `WP_ENVIRONMENT_TYPE=local`, Social Relay active, and an empty queue:

```sh
php tests/wordpress.php /path/to/disposable-wordpress
php tests/wp-smoke.php /path/to/disposable-wordpress
```

The database suite tests schema recovery, existing encrypted credentials, the actual publish hook, scheduled posts, deduplication, review on uncertainty, media staging, and the WhatsApp pending status. It creates test posts, recipes, and jobs and intercepts outbound HTTP responses.

Browser verification covers connection inputs and credential changes, recipe creation and editing, field mapping, post previews, publication filters and job actions, mobile layout, and keyboard and accessibility checks. Live account verification requires your own approved credentials and a controlled destination for each enabled network.
