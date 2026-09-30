# Social Relay: implementation brief

This brief refines the supplied 137-section prompt into testable release work. The original is a product vision, not evidence that every named external API permits every operation. GPT-6 Sol at effort=high should use this brief as the execution contract and the supplied prompt as the feature backlog. Never mark a feature complete without code, tests, documentation, and provider-specific evidence.

## Product contract

Build a WordPress plugin that routes any public `post_type` through **content normalization → eligibility → recipe → destination surface → transformation → asynchronous publication → history**. Source type and destination must remain independent. A product and an article can use the same destination with distinct recipes. A connection identifies a provider account; a surface identifies where it publishes; an audience identifies who may receive it. Public surfaces require no recipient database. Direct messaging requires consent and suppression at send time.

## Non-negotiable safety and UX

1. Global publishing pause defaults **on** until an administrator explicitly enables publishing. Non-production environments stay paused unless explicitly allowed. Creating a connection or recipe must never make an external request.
2. Only administrators may configure connections and recipes. Every mutation needs a capability check, nonce, input validation, and an audit entry. REST routes require explicit permission callbacks. Never expose secrets in HTML, logs, snapshots, errors, or REST responses.
3. Outbound arbitrary URLs require HTTPS, validation before saving and immediately before sending, safe WordPress HTTP requests with redirect validation, bounded timeouts, and response size limits. For generic webhooks, sign a canonical JSON body with HMAC and include an idempotency key. Document receiver responsibility for deduplication.
4. The admin experience must answer three questions in order: **Is publishing safe and enabled? What content matches? What will be sent and when?** Use native WordPress controls, clear labels, visible validation, keyboard-accessible forms, pagination, and status text that does not depend on color.
5. Every automated publication needs a per-destination job, immutable effective snapshot, unique logical key, bounded worker, atomic claim, retry classification, exponential backoff, and visible outcome. External APIs without idempotency cannot promise exactly-once delivery after a crash; state this plainly.
6. Unsupported capabilities must be explicit. No placeholder providers or invented API success. Provider research must link current official documentation and record authentication, permissions, account types, surface, media, limits, policy, and tested status.

## Domain and precedence

`NormalizedContent` has source identity, source type, profile, title, excerpt, plain text, canonical URL, date, locale, taxonomies, featured media, and mapped metadata. The generic adapter handles any public post type; commerce adapters enrich this model without changing provider code. Field mapping is allowlisted by administrators per post type. Never serialize all arbitrary post meta by default.

Rules use an explicit tree: `all`, `any`, `not`, or `{field, operator, value}`. Reject unknown fields/operators and unbounded depth. Evaluation produces a trace from the same traversal used to decide eligibility. Safety exclusion and per-post exclusion win over any recipe. Enabled recipes are evaluated by priority, then specificity, then stable ID. A post may match several recipes; identical effective publication keys must collapse to one job. Recipe configuration is versioned. Jobs snapshot effective text, destination, source revision, rule trace, and recipe version; only live connection credentials and safety/consent state are reread at send time.

## Release slices and acceptance

### Slice A: secure publishing foundation

Install and activate cleanly; create a destination; create a recipe for an arbitrary public post type; preview rule trace and rendered text; publish a post; queue a job; process through a mock receiver or official provider API; show status and safe error; retry 429/5xx; prevent duplicate first-publication events; pause and resume; respect staging protection. Unit and WordPress integration tests cover authorization, nonce, SSRF, rule logic, template escaping, concurrency, retries, and migrations. UI keyboard and screen-reader checks pass.

### Slice B: content and workflow depth

Add custom profile management, admin field mapping, WooCommerce normalization/events, inheritance and resets, campaigns, scheduling, approvals, media transformations, editor sidebars and Classic Editor, calendar, import protection, and audience consent. Each feature gets its own acceptance demonstration and migration path.

### Slice C: provider breadth

For each provider, first write `docs/providers/<name>.md` from current official documentation. Then implement authentication, account and surface discovery, capabilities, payload validation, request/response parsing, error mapping, mocked contract tests, and a live opt-in verification procedure. A provider stays listed as **unsupported** until these gates pass. Never route private API calls or browser automation around restrictions.

### Slice D: release hardening

Add analytics only where official APIs expose it, privacy export/erase where personal data is held, retention, Site Health, WP-CLI, localization, multisite isolation, 50k-source/5k-job load checks, CI, and installable ZIP. Check PHP/WordPress compatibility in a real WordPress environment. Release only when all declared capabilities have passed their gates.

## Verification record

For each slice, record: code paths, actual tests and results, provider documentation date, manual UI/accessibility checks, known external prerequisites, and any unmet acceptance criteria. The final report must distinguish implemented, mocked, unverified live, and unsupported capabilities. Never call the plugin production-ready solely from syntax checks or unit tests.

## Full-system continuation contract for GPT-6 Sol (effort=high)

The following clarifies relationships in the supplied vision for subsequent slices. Preserve these invariants when extending the foundation build.

### Entities and ownership

| Entity | Identity and main fields | Ownership and life cycle |
| --- | --- | --- |
| Source object | `site_id`, `post_id`, `post_type`, revision/event ID | WordPress owns content and publication status. Never mutate source media while preparing remote assets. |
| Normalized content | Immutable snapshot of title, summary, body, canonical URL, locale, author, taxonomies, media references, commerce/event/job attributes and mapped fields | A source adapter builds it from one source revision. Explicit field mappings decide which custom metadata crosses the boundary. |
| Content profile | Stable ID, name, semantic defaults | A post type has a default; a rule may select a profile; an individual post may override. Profiles never own provider accounts. |
| Objective | Stable semantic ID | Selects CTA, template, media and measurement strategy. It is a hint, not a provider compatibility gate. |
| Rule | Validated AST and source version | Evaluator emits both boolean result and trace from one traversal. Null and missing values have defined behavior for every operator. |
| Recipe | ID, version, scope, priority, rule tree, objective, trigger, destinations, templates, timing, retry/fallback/approval policies | Versions are immutable for queued work; editor writes use optimistic concurrency. |
| Connection | Provider ID, account identity, encrypted credentials, permission status | Secrets are never embedded in jobs. Revocation disables future sends. |
| Surface | Provider-owned destination within a connection, capability/version metadata | A connection may have several surfaces, each with different formats and constraints. |
| Audience | Public or an explicit consented recipient/segment | Membership and suppression are live checks at send time. No messaging without required opt-in. |
| Publication | Logical `source × event × recipe × surface × audience` decision | Carries effective configuration, trace and deduplication fingerprint. |
| Job/attempt | Bounded scheduled work and each network attempt | A job is per destination. Attempts retain safe error category, timing and provider request ID, never credentials. |

### Deterministic decision algorithm

1. Read the source at a stable revision and reject non-public, autosave, revision, trashed, or system-excluded content before evaluating recipes.
2. Apply explicit per-post exclusion. A force override may bypass ordinary eligibility only if a policy expressly permits it; it cannot bypass system safety, consent, platform policy, or an invalid capability.
3. Resolve profile from per-post override → matching profile rule → post-type default → global default. Normalize once per source revision/profile pair.
4. Evaluate enabled recipes in this stable order: explicit campaign override, descending priority, descending rule specificity, scope rank (`post override → campaign → taxonomy → post type → profile → brand → global`), ascending ID. Record every matched and rejected condition.
5. Resolve inherited recipe fields field-by-field. Distinguish absent/inherit, explicit reset, and explicit override. Show the origin of each effective value in the UI.
6. Expand each matched recipe to `connection × surface × audience`. Validate destination capability, eligibility, consent, text/media limits, and fallback policy. Never silently shorten, drop media, or change surface.
7. Compute a logical fingerprint from site, source, event/revision, destination surface, audience, and effective publication. Collapse duplicate effective publications while allowing intentionally distinct messages/objectives.
8. Snapshot effective settings, source revision, rendered payload, transformations, rule trace, recipe version and destination identity. Keep credentials, revocation, suppression, global pause, and live provider health as send-time references.
9. Queue only after validation and approval. External HTTP never runs inside save hooks or editor rendering. If a queue capacity gate rejects intake, show an actionable record and requeue path.

### Provider adapter contract

Define `id()`, `capabilities(connection, surface)`, `validate(payload)`, `publish(payload, idempotency_key)`, `map_error(response)`, and optional `poll_status`, `delete`, `update`, `analytics`, `discover_surfaces`, `refresh_credentials`. Absence of an optional method must resolve to `unsupported`, not success. Capability metadata includes the checked documentation date, API version, account eligibility, scopes, app-review requirements, media constraints, supported surfaces, and policy notes. The admin UI and backend validator consume the same capability record. An adapter reaches **implemented** only when documented, mocked, and exercised through the actual queue path; label live verification separately.

### Queue and failure semantics

Allowed transitions must be explicit: `queued → publishing → published|processing|retry_wait|permanent_failure|blocked|cancelled`; `processing → published|retry_wait|permanent_failure`; `retry_wait → publishing`; `blocked → queued` only after a human or connection repair. A conditional database update claims a job and records a lease token. Worker recovery may cause redelivery; use provider idempotency when available, otherwise surface that risk. Classify 429/network/5xx as retryable, 400/401/403 and invalid media as non-retryable unless provider documentation says otherwise. Backoff must be bounded, jittered, and respect `Retry-After` when safe. Rate limits and circuit breakers are per connection, not global. Delayed cron should change timing, not authorization or audience eligibility.

### UX information architecture

The overview displays safety state first, then connection health, pending/failed work, and the next setup action. The connection flow explains account/surface differences and shows required permissions before credentials are saved. The recipe editor has a plain-language condition builder, live example post selector, destination capability warnings, message preview, and an effective-settings inspector. The post editor shows **why** it qualifies, what will be sent to each destination, and the per-post exclusion/override. Publication history links source, recipe version, destination, state, attempts, and remote ID/URL when actually returned. Every queued/blocked/failed state offers an authorized next action. Provide visible labels, focus states, error summaries, keyboard operation, RTL layout, and no color-only meaning. Previews are approximate and must say that remote platforms control final rendering.

### Security threat model and tests

Treat all posts, custom fields, recipe data, provider responses, URLs, headers, and webhook payloads as untrusted. Test CSRF on each mutation; REST authorization per route; output escaping and malicious template text; SQL parameterization; SSRF against loopback, RFC1918, link-local, metadata services, redirects, DNS rebinding, and alternate IP encodings; OAuth state/PKCE where OAuth is used; webhook signatures/timestamps/replay protection where inbound webhooks are used; token redaction through logs, errors, jobs, backups, and diagnostics; site-level tenant isolation; and concurrent worker claims. Where a platform cannot meet a security or policy gate, disable that capability and offer assisted publishing only when useful.

### Demonstrations required before calling the full vision complete

- One sale laptop product matches the `(product AND laptop category AND in stock AND sale price exists) AND NOT internal tag` rule; four negative cases and the displayed trace agree with the evaluator.
- That product reaches several surfaces for different objectives without a source-specific provider class. An article reaches several of the same surfaces with different templates and CTAs.
- A 503 causes `retry_wait`, then a successful retry or remote-processing poll reaches `published`. A repeated WordPress event creates no second logical publication.
- Unauthorized/invalid-nonce calls, forged inbound webhooks, malicious templates, local and metadata URLs, and token-leak attempts fail safely.
- On 50k sources, 10k history rows, and 5k due jobs, admin views remain paginated and worker queries stay indexed/bounded. Report actual query timings and environment.
