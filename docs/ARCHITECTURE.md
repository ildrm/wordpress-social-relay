# Architecture

```
WP_Post → Content::normalize → Rules::evaluate → recipe/template
        → immutable job snapshot → bounded WP-Cron worker
        → live connection lookup → Publisher adapter → destination
```

`Content` emits a consistent content array for any public post type. WooCommerce adds commerce fields if installed. Administrators can map selected scalar post meta values to template/rule fields. The provider adapter only sees a prepared snapshot and connection; it does not depend on WordPress post type.

Recipes are site-local rows with a version. The admin update includes the previous version in its SQL `WHERE` clause to prevent silent concurrent overwrite. Rule trees are validated for known fields/operators and bounded to depth eight and 100 nodes. Evaluation returns the displayed trace. The current UI builds a subset of the engine's conditions; future builders can use the same evaluator.

First publication is detected after post, terms, and meta have been saved. WooCommerce product intake is deferred by one minute so its commerce data can finish saving before normalization. A job snapshots source ID/type/revision, recipe ID/version, connection ID, objective/profile, title, URL, text, and rule trace. Credentials, live connection status, post published status, and exclusion are rechecked at send time. A database unique key collapses repeated first-publication events and identical effective messages to the same connection.

The worker claims at most ten jobs with conditional SQL updates and a random lock token. It uses bounded timeout and response size, classifies retryable network/429/5xx responses, and backs off up to five attempts. An expired claim is retried. The design gives at-least-once delivery; generic webhook receivers should implement idempotency. Telegram has no native idempotency key for `sendMessage`, so a crash at the wrong moment can duplicate a Telegram message.

Tables: `sr_connections`, `sr_recipes`, `sr_jobs`, `sr_audit`, each with the site prefix. `sr_jobs` indexes due-state, source, connection, creation time, and unique logical key. Schema version is stored in one option. All high-volume records remain outside `wp_options`.

`ProviderInterface` and the `social_relay_providers` filter allow additional adapters. Provider capabilities and pure preview validation stay inside each adapter; the shared `HttpClient` handles WordPress HTTP calls and error classification. This is an early extension contract and is not yet a versioned SDK. `social_relay_normalized_content` can enrich normalized content.
