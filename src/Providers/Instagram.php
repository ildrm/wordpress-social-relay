<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\Security;
use SocialRelay\Text;

final class Instagram extends AuthenticatedProvider
{
    protected const ID = 'instagram';
    protected const SURFACE = 'professional_account';
    protected const TEXT_LIMIT = 2200;
    protected const IMAGE = true;
    protected const LINK = false;
    protected const API = 'Instagram API v27.0';
    private const BASE = 'https://graph.instagram.com/v27.0/';

    public function capabilities(): array
    {
        return parent::capabilities() + ['staged' => true];
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        $reason = parent::preview_validate($connection, $snapshot);
        if ($reason !== '') {
            return $reason;
        }
        if (!Security::public_https_url($snapshot['featured_image'] ?? '')) {
            return 'image_required';
        }
        $mime = $snapshot['featured_image_mime'] ?? '';
        return $mime === '' || $mime === 'image/jpeg' ? '' : 'image_format';
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $accountUrl = self::BASE . $connection['surface'];
        $container = $snapshot['_delivery_state'] ?? '';
        if ($container === '') {
            $payload = ['image_url' => $snapshot['featured_image'], 'caption' => $snapshot['text']];
            if (!empty($snapshot['featured_image_alt'])) {
                $payload['alt_text'] = Text::truncate($snapshot['featured_image_alt'], 1000);
            }
            $response = self::post_form($accountUrl . '/media', $payload, $token);
            $result = HttpClient::classify($response);
            if (!$result['ok']) {
                return $result;
            }
            $container = (string) (self::json($response)['id'] ?? '');
            if (!ctype_digit($container)) {
                return self::failure('media_failed');
            }
            // Persist the container before the first attempt that can publish media.
            return ['ok' => false, 'code' => 'media_processing', 'retry' => true, 'retry_after' => 60, 'state' => $container, 'prepared' => true];
        }
        if (!ctype_digit($container)) {
            return self::failure('media_failed');
        }
        $response = HttpClient::get(self::BASE . $container . '?fields=status_code', self::headers($token));
        $result = HttpClient::classify($response);
        if (!$result['ok']) {
            return $result;
        }
        switch (self::json($response)['status_code'] ?? '') {
            case 'PUBLISHED':
                // Instagram confirmed this container was published without returning the media ID.
                return ['ok' => true, 'remote_id' => ''];
            case 'IN_PROGRESS':
                return ['ok' => false, 'code' => 'media_processing', 'retry' => true, 'retry_after' => 60];
            case 'FINISHED':
                $response = self::post_form($accountUrl . '/media_publish', ['creation_id' => $container], $token);
                return self::result($response, self::json($response)['id'] ?? null);
            default:
                return self::failure('media_failed');
        }
    }
}
