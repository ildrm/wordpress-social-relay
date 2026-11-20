<?php
namespace SocialRelay\Providers;

use SocialRelay\Security;
use SocialRelay\Text;

final class Pinterest extends AuthenticatedProvider
{
    protected const ID = 'pinterest';
    protected const SURFACE = 'board';
    protected const TEXT_LIMIT = 500;
    protected const IMAGE = true;
    protected const API = 'Pinterest API v5';

    public function preview_validate(array $connection, array $snapshot): string
    {
        $reason = parent::preview_validate($connection, $snapshot);
        if ($reason !== '') {
            return $reason;
        }
        if (!Security::public_https_url($snapshot['featured_image'] ?? '')) {
            return 'image_required';
        }
        if (!Text::is_web_url($snapshot['url'] ?? '')) {
            return 'source_url';
        }
        return in_array($snapshot['featured_image_mime'] ?? '', ['', 'image/jpeg', 'image/png'], true) ? '' : 'image_format';
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $payload = [
            'board_id' => $connection['surface'],
            'title' => Text::truncate(($snapshot['title'] ?? '') ?: $snapshot['text'], 100),
            'description' => $snapshot['text'],
            'link' => $snapshot['url'],
            'media_source' => ['source_type' => 'image_url', 'url' => $snapshot['featured_image']],
        ];
        if (!empty($snapshot['featured_image_alt'])) {
            $payload['alt_text'] = Text::truncate($snapshot['featured_image_alt'], 500);
        }
        $response = self::post_json('https://api.pinterest.com/v5/pins', $payload, $token);
        return self::result($response, self::json($response)['id'] ?? null);
    }
}
