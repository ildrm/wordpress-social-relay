<?php
namespace SocialRelay\Providers;

use SocialRelay\Text;

final class Tumblr extends AuthenticatedProvider
{
    protected const ID = 'tumblr';
    protected const SURFACE = 'blog';
    protected const TEXT_LIMIT = 4096;
    protected const API = 'Tumblr API v2';

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $content = [['type' => 'text', 'text' => $snapshot['text']]];
        if (Text::is_web_url($snapshot['url'] ?? '')) {
            $content[] = ['type' => 'link', 'url' => $snapshot['url']];
        }
        $url = 'https://api.tumblr.com/v2/blog/' . rawurlencode($connection['surface']) . '/posts';
        $response = self::post_json($url, ['content' => $content, 'state' => 'published'], $token);
        $data = self::json($response)['response'] ?? [];
        return self::result($response, $data['id_string'] ?? $data['id'] ?? $data['post_id'] ?? null);
    }
}
