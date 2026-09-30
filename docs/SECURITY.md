# Security model

The external publishing switch defaults off. Non-production environments require an additional opt-in. The site URL is pinned at activation; a changed URL blocks sends until an administrator explicitly trusts the new location. A per-post exclusion is enforced at enqueue and send time. The hourly intake cap prevents a large import from immediately creating unbounded jobs.

Only the custom `manage_social_relay` capability can manage connections, recipes, jobs, and safety settings. It is given to administrators on activation. Post exclusions require `edit_post`. All admin mutations check a WordPress nonce and capability; the manual queue action checks a post-specific nonce. User-facing values are escaped in HTML. Recipe templates are plain text; the editor strips markup at save time. Audit rows contain action/actor/object/timestamp, not credentials or payloads.

Connection secrets are encrypted with AES-256-GCM using key material derived from WordPress auth salts. They are never redisplayed. A salt change requires replacement connections. The plugin does not log outbound URLs, tokens, bodies, or provider error bodies. Other HTTP instrumentation installed on a site may observe Telegram's token-bearing API URL, so site operators must keep HTTP debug logs and monitoring access controlled.

Webhook URLs must use HTTPS. The plugin validates the URL when saved and immediately before send, then uses [`wp_safe_remote_post`](https://developer.wordpress.org/reference/functions/wp_safe_remote_post/) with redirects disabled, a 15-second timeout, and a 64 KiB response limit. WordPress rejects unsafe/local URLs by default through [`wp_http_validate_url`](https://developer.wordpress.org/reference/functions/wp_http_validate_url/). Site-wide filters that weaken WordPress URL validation can weaken this boundary. A security review should include DNS rebinding tests in the deployment environment.

The webhook body is signed using `HMAC-SHA256(secret, exact raw JSON body)`. Verify the full body before parsing and compare signatures with a constant-time function. The `X-Social-Relay-Idempotency-Key` header identifies the logical publication. The receiver must store accepted keys to avoid duplicate effects after retries. Do not treat a 2xx webhook acceptance as proof of a downstream social post.

No inbound webhook, OAuth callback, public REST endpoint, private audience list, or AI data flow is implemented in this release.
