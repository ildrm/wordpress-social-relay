# Social Relay

Social Relay is a WordPress plugin for rules-driven first-publication delivery to a signed HTTPS webhook or a Telegram channel/group. A recipe matches any public WordPress post type, including WooCommerce products, and renders a destination message from normalized content. Publication runs in a bounded background queue.

**Current release: 0.1.0, foundation build.** This repository implements the secure publishing path described below. It does not implement the full multi-provider, campaign, audience, media, analytics, and editor experience in the supplied vision. See [implementation status](docs/IMPLEMENTATION_STATUS.md) before using it in production.

## Workflow

1. Install and activate the plugin. Outbound publishing starts paused; eligible posts can still enter the queue and will send when publishing is enabled.
2. In **Social Relay → Destinations**, add an HTTPS webhook receiver or Telegram bot channel/group. The bot token or HMAC secret is encrypted at rest.
3. In **Recipes**, choose a public post type, destination, objective, optional taxonomy/field conditions, and message template.
4. Open a post. Its **Social Relay** panel shows actual rule results and the resulting message. You can exclude that post.
5. In **Overview**, explicitly enable external publishing. Non-production environments need a second opt-in.
6. On first publication, an eligible post queues a separate destination job. **Publications** shows acceptance, retries, blocks, and failures.

Webhooks receive a canonical JSON body with `X-Social-Relay-Idempotency-Key` and `X-Social-Relay-Signature: sha256=<hex HMAC>`. A `2xx` response means the receiver accepted it; the receiver should deduplicate by key. Telegram posts plain text through the official Bot API `sendMessage` method. No private-recipient messaging is supported.

## Documentation

- [Optimized implementation brief](docs/IMPLEMENTATION_BRIEF.md)
- [Review of the original prompt](docs/PROMPT_REVIEW.md)
- [Implementation status and verification](docs/IMPLEMENTATION_STATUS.md)
- [Implementation report](docs/IMPLEMENTATION_REPORT.md)
- [Installation](docs/INSTALLATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security](docs/SECURITY.md)
- [Privacy](docs/PRIVACY.md)
- [Testing](docs/TESTING.md)
- [Provider capabilities](docs/providers/README.md)

Requires WordPress 6.4+, PHP 8.1+, and OpenSSL. External publishing depends on WP-Cron; a system cron trigger is recommended for predictable timing.
