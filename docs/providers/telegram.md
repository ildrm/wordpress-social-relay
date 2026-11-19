# Telegram Bot API — text to group or channel

Research checked 2026-10-01 against the [official Telegram Bot API](https://core.telegram.org/bots/api) and [Telegram bot features](https://core.telegram.org/bots/features).

The current adapter uses HTTPS `sendMessage` with a manually supplied bot token and negative numeric group/channel ID or public `@channel` username. It sends plain text only, up to 4096 Unicode characters where `mbstring` is available. The API returns a `Message`; this adapter stores `message_id`. It does not discover chats, refresh tokens, send media, edit/delete messages, use Business Connections, or manage private recipients. The bot must be able to post in the selected destination; channel permissions must be configured in Telegram. No live credentials were available for verification.

The token is stored encrypted at rest. Telegram's Bot API embeds it in the request URL, which HTTP debugging systems may capture. The plugin itself does not persist it in logs or job snapshots. The worker retries 429 responses. A timeout, ambiguous server response or expired in-flight lease requires review in **Publications**. Check the destination first, then record confirmed delivery or explicitly retry. Telegram's Bot API does not provide a native idempotency key for `sendMessage`.
