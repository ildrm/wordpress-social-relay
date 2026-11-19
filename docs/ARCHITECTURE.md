# Architecture

```
WP_Post → Content::normalize → Rules::evaluate → recipe/template
        → immutable job snapshot → bounded WP-Cron worker
        → live connection lookup → Publisher adapter → destination
```

`Content` emits a consistent content array for any public post type. WooCommerce adds commerce fields if installed. Administrators can map selected scalar post meta values to template/rule fields. The provider adapter only sees a prepared snapshot and connection; it does not depend on WordPress post type.

Recipes are site-local rows with a version. The admin update includes the previous version in its SQL `WHERE` clause to prevent silent concurrent overwrite. Rule trees are validated for known fields/operators and bounded to depth eight and 100 nodes. Evaluation returns the displayed trace. The current UI builds a subset of the engine's conditions; future builders can use the same evaluator.

First publication is detected after post, terms, and meta have been saved. WooCommerce product intake is deferred by one minute so its commerce data can finish saving before normalization. A job snapshots source ID/type/revision, recipe ID/version, connection ID, objective/profile, title, URL, text, and rule trace. Credentials, live connection status, post published status, and exclusion are rechecked at send time. A database unique key collapses repeated first-publication events and identical effective messages to the same connection.

Jobs are persisted before processing. All workers share a database delivery budget that replenishes at one attempt every 36 seconds with a burst of up to ten attempts. Each worker claims at most ten jobs with conditional SQL updates and a random lock token. A job records whether the destination supports idempotency before sending. An expired preflight lease or idempotent delivery may be retried. An expired or ambiguous Telegram delivery is held for an administrator to verify with the destination.

Webhook retries use the original idempotency key. A webhook receiver must persist accepted keys before acknowledging a request. Database migration verifies each required table, column and index before advancing the schema version. Job result auditing requires a successful update of the current lease.

Tables: `sr_connections`, `sr_recipes`, `sr_jobs`, `sr_audit`, each with the site prefix. `sr_jobs` indexes due-state, source, connection, creation time, and unique logical key. Schema version is stored in one option. All high-volume records remain outside `wp_options`.

`ProviderInterface` and the `social_relay_providers` filter allow additional adapters. Provider capabilities and pure preview validation stay inside each adapter; the shared `HttpClient` handles WordPress HTTP calls and error classification. This is an early extension contract and is not yet a versioned SDK. `social_relay_normalized_content` can enrich normalized content.
