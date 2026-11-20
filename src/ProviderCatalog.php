<?php
namespace SocialRelay;

final class ProviderCatalog
{
    public static function all(): array
    {
        static $catalogs = [];
        $locale = function_exists('determine_locale') ? determine_locale() : '';
        return $catalogs[$locale] ??= [
            'facebook' => self::entry(
                'Facebook', __('Publish a post to a Facebook Page.', 'social-relay'), 'dashicons-facebook-alt',
                __('Page ID', 'social-relay'), __('The numeric ID of a Page you can publish to.', 'social-relay'),
                __('Page access token', 'social-relay'), __('Use a Page token with pages_manage_posts and pages_read_engagement.', 'social-relay'),
                'https://developers.facebook.com/docs/graph-api/reference/page/feed/', "{title}\n{excerpt}"
            ),
            'instagram' => self::entry(
                'Instagram', __('Publish the featured image and a caption to a professional account.', 'social-relay'), 'dashicons-instagram',
                __('Instagram account ID', 'social-relay'), __('Use the numeric professional account ID from Instagram Login.', 'social-relay'),
                __('Instagram access token', 'social-relay'), __('Use an Instagram Login token with instagram_business_basic and instagram_business_content_publishing.', 'social-relay'),
                'https://developers.facebook.com/documentation/instagram-platform/content-publishing', "{title}\n{excerpt}", ['image_required' => true]
            ),
            'whatsapp' => self::entry(
                __('WhatsApp Channel', 'social-relay'), __('Prepare a channel update to post and confirm manually.', 'social-relay'), 'dashicons-whatsapp',
                __('WhatsApp channel link', 'social-relay'), __('Paste your channel link, such as https://whatsapp.com/channel/…', 'social-relay'),
                '', '', 'https://www.whatsapp.com/channels', "{title}\n{url}", ['manual' => true]
            ),
            'telegram' => self::entry(
                'Telegram', __('Send text and links to a channel or group through your bot.', 'social-relay'), 'dashicons-megaphone',
                __('Channel or group ID', 'social-relay'), __('Use a negative chat ID or a public @channel. Give your bot posting permissions.', 'social-relay'),
                __('Bot token', 'social-relay'), __('Copy the token from BotFather.', 'social-relay'),
                'https://core.telegram.org/bots/api#sendmessage', "{title}\n{excerpt}\n{url}"
            ),
            'linkedin' => self::entry(
                'LinkedIn', __('Share a post as a member or organization.', 'social-relay'), 'dashicons-businessperson',
                __('Author URN', 'social-relay'), __('Use urn:li:person:… or urn:li:organization:…', 'social-relay'),
                __('LinkedIn access token', 'social-relay'), __('Use a member token with w_member_social or w_organization_social and the required Page role.', 'social-relay'),
                'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-06', "{title}\n{excerpt}\n{url}"
            ),
            'threads' => self::entry(
                'Threads', __('Share a short text post on Threads.', 'social-relay'), 'dashicons-format-status',
                __('Threads user ID', 'social-relay'), __('Use the numeric user ID from the Threads API.', 'social-relay'),
                __('Threads access token', 'social-relay'), __('Use a Threads user token with threads_basic and threads_content_publish.', 'social-relay'),
                'https://developers.facebook.com/documentation/threads/posts', "{title}\n{url}"
            ),
            'mastodon' => self::entry(
                'Mastodon', __('Publish a public status on your Mastodon server.', 'social-relay'), 'dashicons-admin-site-alt3',
                __('Account label', 'social-relay'), __('Enter the account or handle for your reference.', 'social-relay'),
                __('Mastodon access token', 'social-relay'), __('Use a user token with write:statuses on this instance.', 'social-relay'),
                'https://docs.joinmastodon.org/methods/statuses/#create', "{title}\n{url}", [
                    'endpoint_label' => __('Instance URL', 'social-relay'),
                    'endpoint_hint' => __('Use the root HTTPS URL, such as https://mastodon.social.', 'social-relay'),
                ]
            ),
            'bluesky' => self::entry(
                'Bluesky', __('Publish short posts with clickable links.', 'social-relay'), 'dashicons-cloud',
                __('Account handle or DID', 'social-relay'), __('Enter your handle without @, or your account DID.', 'social-relay'),
                __('Bluesky app password', 'social-relay'), __('Create a dedicated app password in Bluesky settings.', 'social-relay'),
                'https://docs.bsky.app/docs/tutorials/creating-a-post', "{title}\n{url}", [
                    'endpoint_label' => __('Personal data server URL (optional)', 'social-relay'),
                    'endpoint_hint' => __('Leave blank for bsky.social. For self-hosted accounts, enter the root HTTPS URL of your PDS.', 'social-relay'),
                ]
            ),
            'pinterest' => self::entry(
                'Pinterest', __('Create an image Pin with a link to your post.', 'social-relay'), 'dashicons-location-alt',
                __('Board ID', 'social-relay'), __('Use the numeric ID of a board this account can publish to.', 'social-relay'),
                __('Pinterest access token', 'social-relay'), __('Use a Pinterest API user token with pins:write.', 'social-relay'),
                'https://developers.pinterest.com/docs/api/v5/pins-create/', '{excerpt}', ['image_required' => true]
            ),
            'x' => self::entry(
                'X', __('Publish a short post on X.', 'social-relay'), 'dashicons-format-chat',
                __('Account label', 'social-relay'), __('Enter the account or handle for your reference.', 'social-relay'),
                __('X user access token', 'social-relay'), __('Use an OAuth 2.0 user-context token with tweet.read, tweet.write and users.read.', 'social-relay'),
                'https://docs.x.com/x-api/posts/create-post', "{title}\n{url}"
            ),
            'tumblr' => self::entry(
                'Tumblr', __('Publish a text post with a link block on a Tumblr blog.', 'social-relay'), 'dashicons-welcome-write-blog',
                __('Blog identifier', 'social-relay'), __('Use the blog name, its host name, or its t: UUID.', 'social-relay'),
                __('Tumblr access token', 'social-relay'), __('Use a Tumblr OAuth 2.0 token with write access.', 'social-relay'),
                'https://github.com/tumblr/docs/blob/master/api.md#posts---createreblog-a-post-neue-post-format', "{title}\n{excerpt}"
            ),
            'reddit' => self::entry(
                'Reddit', __('Submit a link post to a subreddit that permits it.', 'social-relay'), 'dashicons-groups',
                __('Subreddit', 'social-relay'), __('Enter the subreddit name without r/. Check its rules and posting requirements.', 'social-relay'),
                __('Reddit access token', 'social-relay'), __('Use an approved Reddit API application and a user token with the submit scope.', 'social-relay'),
                'https://www.reddit.com/dev/api/#POST_api_submit', '{title}'
            ),
            'webhook' => self::entry(
                __('Signed webhook', 'social-relay'), __('Deliver signed JSON to an HTTPS receiver.', 'social-relay'), 'dashicons-rest-api',
                __('Receiver label', 'social-relay'), __('Use a short label that identifies this receiver.', 'social-relay'),
                __('HMAC secret', 'social-relay'), __('Use a secret of at least 20 characters shared with the receiver.', 'social-relay'),
                '', "{title}\n{excerpt}\n{url}", [
                    'endpoint_label' => __('Receiver URL', 'social-relay'),
                    'endpoint_hint' => __('Use a public HTTPS endpoint. Redirects are disabled.', 'social-relay'),
                ]
            ),
        ];
    }

    public static function get(string $provider): ?array
    {
        return self::all()[$provider] ?? null;
    }

    private static function entry(
        string $name,
        string $description,
        string $icon,
        string $surface_label,
        string $surface_hint,
        string $secret_label,
        string $secret_hint,
        string $docs,
        string $template,
        array $options = []
    ): array {
        return compact('name', 'description', 'icon', 'surface_label', 'surface_hint', 'secret_label', 'secret_hint', 'docs', 'template')
            + $options + ['manual' => false, 'image_required' => false, 'endpoint_label' => '', 'endpoint_hint' => ''];
    }
}
