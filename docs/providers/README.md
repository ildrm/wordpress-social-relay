# Provider setup

A destination stores one account, page, board, channel, or receiver. Create credentials in the relevant provider?s developer dashboard with the permissions below. Paste the credential into the destination form. When it expires, use **Destinations ? Manage ? Replace credential**.

| Network | What Social Relay publishes | Account and credential |
| --- | --- | --- |
| [Facebook](https://developers.facebook.com/docs/graph-api/reference/page/feed/) | Page feed message and a link to the source post | Numeric Page ID and Page access token with `pages_manage_posts` and `pages_read_engagement`. Page lookup can also require `pages_show_list`. |
| [Instagram](https://developers.facebook.com/documentation/instagram-platform/content-publishing) | One public JPEG featured image, caption, and optional alt text | Professional account ID and Instagram Login user token with `instagram_business_basic` and `instagram_business_content_publishing`. |
| [Telegram](telegram.md) | Text to a channel or group | BotFather token and negative numeric chat ID or public `@channel`. Grant the bot permission to post. |
| [LinkedIn](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-06) | Public feed post from a member or organization | `urn:li:person:?` or `urn:li:organization:?`, and a user token with `w_member_social` or `w_organization_social`. Organization publication also requires a suitable Page role and product access. |
| [Threads](https://developers.facebook.com/documentation/threads/posts) | Text post | Numeric Threads user ID and user token with `threads_basic` and `threads_content_publish`. |
| [Mastodon](https://docs.joinmastodon.org/methods/statuses/#create) | Public status with text | An account label, instance root HTTPS URL, and token for that instance with `write:statuses`. The recipe preview uses a 500-character limit. |
| [Bluesky](https://docs.bsky.app/docs/tutorials/creating-a-post) | Text post with clickable URL facets | Handle or DID and a dedicated app password. Specify the personal data server root HTTPS URL for self-hosted accounts. |
| [Pinterest](https://developers.pinterest.com/docs/api/v5/pins-create/) | Standard image Pin with featured JPEG or PNG, description, and source link | Numeric board ID and user token with `pins:write`. Grant the account publish access to the board. |
| [X](https://docs.x.com/x-api/posts/create-post) | Text post | Account label and OAuth 2.0 user-context token with `tweet.read`, `tweet.write`, and `users.read`. Posting requires API access for your developer project. |
| [Tumblr](https://github.com/tumblr/docs/blob/master/api.md#posts---createreblog-a-post-neue-post-format) | Text post with a source link block | Blog identifier and Tumblr OAuth 2.0 token with the `write` scope. |
| [Reddit](https://www.reddit.com/dev/api/#POST_api_submit) | Link submission with a one-line title | Subreddit name and an approved application?s user access token with `submit`. Check [Reddit?s approval policy](https://support.reddithelp.com/hc/en-us/articles/42728983564564-Responsible-Builder-Policy) and community rules. Use the `social_relay_reddit_user_agent` filter to identify your approved application and operator account. |
| [WhatsApp Channels](https://www.whatsapp.com/channels) | Prepared text and manual receipt | Public channel URL and access to post as a channel administrator in WhatsApp. No API credential is needed. |
| [Signed HTTPS webhook](webhook.md) | Signed JSON and idempotency key | Public HTTPS endpoint and shared HMAC secret. |

Instagram and Pinterest fetch the original featured image from its public HTTPS URL. Check image size and aspect ratio in each provider?s documentation. Templates control the rendered text. Facebook, Pinterest, Tumblr, and Reddit also use the post permalink in a dedicated payload field. For the other text integrations, include `{url}` when you want a source link in the message.

Credentials are provided by the operator. OAuth authorization, renewal, account discovery, and organization onboarding are handled in the provider?s own tools. Instagram media preparation can take several queue runs. Shortening a template or adding a featured image can make a previously skipped post eligible for manual queuing from its editor.

To publish a WhatsApp update, open its job under **Publications**, copy the prepared message, open the channel, post the update in WhatsApp, and select **Mark as published** in WordPress. The [WhatsApp Business Platform APIs](https://developers.facebook.com/documentation/business-messaging/whatsapp/overview/) document business messages and calls. The plugin uses the official channel app for the publishing step.
