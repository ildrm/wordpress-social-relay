<?php
namespace SocialRelay\Providers;

use SocialRelay\Text;

final class X extends AuthenticatedProvider
{
    protected const ID = 'x';
    protected const TEXT_LIMIT = 280;
    protected const API = 'X API v2';

    protected function text_length(string $text): int
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        return Text::with_weighted_links($text, 23, static function (string $part): int {
            $length = 0;
            foreach (Text::graphemes($part) as $cluster) {
                $length += Text::is_emoji($cluster) ? 2 : 2 * Text::length($cluster)
                    - (preg_match_all('/[\x{0000}-\x{10FF}\x{2000}-\x{200D}\x{2010}-\x{201F}\x{2032}-\x{2037}]/u', $cluster) ?: 0);
            }
            return $length;
        });
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $response = self::post_json('https://api.x.com/2/tweets', ['text' => $snapshot['text']], $token);
        return self::result($response, self::json($response)['data']['id'] ?? null);
    }
}
