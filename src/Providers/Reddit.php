<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\Text;

final class Reddit extends AuthenticatedProvider
{
    protected const ID = 'reddit';
    protected const SURFACE = 'subreddit';
    protected const TEXT_LIMIT = 300;
    protected const API = 'Reddit Data API';

    public function preview_validate(array $connection, array $snapshot): string
    {
        $reason = parent::preview_validate($connection, $snapshot);
        if ($reason !== '') {
            return $reason;
        }
        if (!Text::is_web_url($snapshot['url'] ?? '')) {
            return 'source_url';
        }
        return preg_match('/[\r\n]/', $snapshot['text']) ? 'single_line_required' : '';
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $response = self::post_form('https://oauth.reddit.com/api/submit', [
            'api_type' => 'json', 'kind' => 'link', 'sr' => $connection['surface'],
            'title' => $snapshot['text'], 'url' => $snapshot['url'], 'sendreplies' => 'false',
        ], $token, ['User-Agent' => (string) apply_filters('social_relay_reddit_user_agent',
            'wordpress:com.ildrm.social-relay:v' . SOCIAL_RELAY_VERSION . ' (contact: https://github.com/ildrm/wordpress-social-relay)')]);
        $data = self::json($response)['json'] ?? [];
        $http = HttpClient::classify($response);
        if ($http['ok'] && !empty($data['errors'])) {
            return self::failure('rejected');
        }
        return self::result($response, $data['data']['name'] ?? $data['data']['id'] ?? null);
    }
}
