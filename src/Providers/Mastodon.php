<?php
namespace SocialRelay\Providers;

use SocialRelay\Text;

final class Mastodon extends AuthenticatedProvider
{
    protected const ID = 'mastodon';
    protected const TEXT_LIMIT = 500;
    protected const API = 'Mastodon REST API v1';

    protected function text_length(string $text): int
    {
        return Text::with_weighted_links($text, 23, static fn(string $part): int => count(Text::graphemes($part)));
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $url = rtrim($connection['endpoint'], '/') . '/api/v1/statuses';
        $response = self::post_json($url, ['status' => $snapshot['text'], 'visibility' => 'public'],
            $token, ['Idempotency-Key' => $idempotencyKey]);
        // The server only retains keys briefly; lost worker leases still require review.
        return self::result($response, self::json($response)['id'] ?? null);
    }
}
