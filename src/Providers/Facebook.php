<?php
namespace SocialRelay\Providers;

use SocialRelay\Text;

final class Facebook extends AuthenticatedProvider
{
    protected const ID = 'facebook';
    protected const SURFACE = 'page';
    protected const TEXT_LIMIT = 60000;
    protected const API = 'Graph API v27.0';

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $payload = ['message' => $snapshot['text']];
        if (Text::is_web_url($snapshot['url'] ?? '')) {
            $payload['link'] = $snapshot['url'];
        }
        $url = 'https://graph.facebook.com/v27.0/' . $connection['surface'] . '/feed';
        $response = self::post_form($url, $payload, $token);
        return self::result($response, self::json($response)['id'] ?? null);
    }
}
