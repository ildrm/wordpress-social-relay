# Provider capability register

| Destination | State | Implemented surface | Live verified |
| --- | --- | --- | --- |
| Signed HTTPS webhook | Implemented and mocked | One configured HTTPS endpoint | No |
| Telegram Bot API | Implemented and mocked | Plain-text group/channel `sendMessage` | No |
| Facebook, Instagram, Threads, LinkedIn, X, Bluesky, Mastodon, Pinterest, Reddit, TikTok, YouTube | Unsupported | None | No |
| Discord, WhatsApp Business, LINE, Slack, Microsoft Teams | Unsupported | None | No |

The unsupported providers are architecture candidates from the supplied vision. They have no advertised connection or send path in this build. Before enabling any one, research its current official docs, authentication/scopes, account type, posting surface, format and rate limits, policy/app-review requirements, media rules, webhook and analytics support, and current API version. Record these in a dedicated provider guide and add mocked contract plus opt-in live tests.
