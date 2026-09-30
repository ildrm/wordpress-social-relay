# Implementation status — 2026-10-01

This document distinguishes implemented paths from the larger attached product vision. Syntax and focused tests alone do not establish production readiness.

## Implemented

| Area | State | Evidence |
| --- | --- | --- |
| Arbitrary public post types | Implemented for first publication | Generic normalization and `wp_after_insert_post`; post-type recipe selector |
| WooCommerce products | Partial | Price, sale price, SKU, stock state, currency enrichment when WooCommerce is active; first-publication intake deferred one minute for saved commerce data |
| Custom fields and ACF scalar values | Partial | Admin mapping reads selected post meta; ACF arrays/images are not transformed |
| Rule engine | Partial | Bounded `all`/`any`/`not`, equality, containment, list membership, numeric comparison, existence, actual trace |
| Recipes | Partial | Priority ordering, version-checked edits, template, post-type and simple condition builder; no inheritance/campaigns |
| Destinations | Implemented for signed webhook and Telegram text | Provider interface/capabilities, pure preview validation, encrypted connection secrets, webhook HMAC, Telegram `sendMessage` adapter and mocked tests |
| Queue | Partial | Per-destination snapshots, unique keys, atomic claim, 5-minute recovery, capped worker, retry with backoff, status list |
| Safety | Implemented for this slice | Pause default, staging opt-in and site-URL pin, per-post exclusion, cap of 100 new jobs/hour, nonce/capability checks, safe HTTP URL validation |
| Admin/editor UX | Partial | Overview, destination, recipe, mapping, paginated publications, classic meta box shown in Gutenberg; no dedicated sidebar/calendar |
| Uninstall | Implemented | Data retained by default; explicit removal setting |

## Important limitations

- Two local WordPress trees were found, but both failed bootstrap with a database connection error. The plugin was not activated in a running WordPress site, so WordPress integration, migration, visual accessibility, and live delivery remain unverified. No provider credentials were available.
- Telegram bot text sending is implemented against the official Bot API, but no live bot test was run. Only channels/groups are offered; private messaging, consent tracking, and media are unsupported.
- Signed webhooks are an interoperability destination, not a social network API. The receiver is responsible for translating and deduplicating the message. A crash after receiver acceptance but before local completion can cause another request with the same idempotency key.
- One first-publication event can enqueue at most 100 new destination jobs per hour per site. Later events in that hour are skipped and flagged on the overview screen; an administrator can queue the post after the limit clears. This is a safety stop, not a durable backlog.
- No account discovery, OAuth, campaigns, audience consent database, approvals, advanced scheduling, media processing, analytics, attribution, WP-CLI, REST API, Site Health integration, or provider integrations beyond the two listed above.
- Priority determines evaluation order. Identical text to the same connection collapses to one job; differing messages can both publish. Full inheritance and conflict semantics from the vision are not implemented.
- WP-Cron depends on site traffic unless a system scheduler runs `wp-cron.php`. Worker throughput is capped at ten due jobs per invocation.
- Site-level tables use each site's `$wpdb->prefix`; network-wide multisite controls are not implemented.

## Verification performed

- PHP syntax lint on plugin files.
- `php tests/run.php`: rule scenario, negative cases, template allowlist, URL gating with WordPress stubs, encryption round trip, signed webhook, 503/429 classification, Telegram response parsing.
- Read-only smoke attempts against two local WordPress trees; both were blocked by their unavailable database service.
- Package build and ZIP entry check.

## Gates before production release

Run WordPress integration tests for activation, migration, cron, permissions, editor flows, arbitrary CPTs, WooCommerce, and concurrency. Test real provider credentials in a controlled site. Review the admin UI with keyboard, screen reader, and RTL locales. Load-test 50k posts/5k jobs. Add provider-specific policy reviews. Complete these gates before calling this build production-ready.
