# Architecture

- `social-relay.php` registers the loader and lifecycle hooks. `Plugin` registers ingestion, admin, and worker hooks.
- `Content`, `Rules`, and `Template` normalize the WordPress source, evaluate versioned recipes, and render plain text.
- `Admin` registers the screens. Classes in `src/Admin/` render the publishing workspace. `AdminActions` authorizes and handles write requests.
- `ProviderCatalog` describes setup inputs and template defaults. `ConnectionConfig` validates them. `Publisher` dispatches by provider through `ProviderInterface`, with an extension filter for custom adapters.
- `Database` manages site-prefixed connections, recipes, jobs, audit, and rate tables. `DeliveryWorker` claims due jobs and records their outcomes.

The publish hook stores an immutable content snapshot for each eligible recipe and destination. The unique publication key prevents inserting the same message twice. Reconciliation also checks previously queued recipe/destination pairs for the post. The worker rechecks that the source is still public, unprotected, and included before a send. Manual WhatsApp items enter `awaiting_manual` immediately and are completed in the admin workspace.

Automatic workers use atomic claims, renewable capacity governed by the database, time-limited leases, bounded retries, and an opt-in pause gate. Jobs reaching a rate limit honor the provider?s `Retry-After` when available. Ambiguous sends go to review unless the receiver contract makes repeating the same key safe. Instagram stores its media container in `provider_state` before attempting to publish it, so polling or an explicit recovery uses the same container. Background delivery depends on WP-Cron. A separate administrator action can request a run for due jobs.
