<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\Text;

final class Threads extends AuthenticatedProvider
{
    protected const ID = 'threads';
    protected const TEXT_LIMIT = 500;
    protected const API = 'Threads API v1.0';

    protected function text_length(string $text): int
    {
        $length = 0;
        foreach (Text::graphemes($text) as $cluster) {
            $length += Text::is_emoji($cluster) ? strlen($cluster) : Text::length($cluster);
        }
        return $length;
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $url = 'https://graph.threads.net/v1.0/' . $connection['surface'];
        $response = self::post_form($url . '/threads', ['media_type' => 'TEXT', 'text' => $snapshot['text']], $token);
        $result = HttpClient::classify($response);
        if (!$result['ok']) {
            return $result;
        }
        $container = (string) (self::json($response)['id'] ?? '');
        if (!ctype_digit($container)) {
            return self::failure('rejected');
        }
        $response = self::post_form($url . '/threads_publish', ['creation_id' => $container], $token);
        return self::result($response, self::json($response)['id'] ?? null);
    }
}
