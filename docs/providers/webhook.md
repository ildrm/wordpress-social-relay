# Signed HTTPS webhook

The generic webhook is a first-party protocol defined by this plugin. Its WordPress HTTP behavior follows the official [`wp_safe_remote_post`](https://developer.wordpress.org/reference/functions/wp_safe_remote_post/) and [`wp_http_validate_url`](https://developer.wordpress.org/reference/functions/wp_http_validate_url/) documentation, checked 2026-10-01.

Configure a public HTTPS URL and an HMAC secret of at least 20 characters. The plugin sends `Content-Type: application/json`, `X-Social-Relay-Idempotency-Key`, and `X-Social-Relay-Signature: sha256=<hex>`. The signature covers the exact JSON bytes. A `2xx` response is considered accepted. Optionally return `X-Social-Relay-Remote-Id` to expose a receiver ID in publication history. `429`, `408`, `5xx`, and network failures retry; other statuses fail.

The receiver must validate the HMAC with a constant-time comparison, store idempotency keys, and return non-2xx only for genuinely unaccepted messages. A `2xx` reply does not prove that the receiver posted content to another platform. Endpoint safety validation rejects non-HTTPS and WordPress-unsafe URLs and runs again at send time.
