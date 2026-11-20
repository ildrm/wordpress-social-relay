<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\Security;
use SocialRelay\Text;

final class Bluesky extends AuthenticatedProvider
{
    protected const ID = 'bluesky';
    protected const TEXT_LIMIT = 300;
    protected const API = 'AT Protocol XRPC';
    private const COLLECTION = 'app.bsky.feed.post';

    protected function text_length(string $text): int
    {
        return count(Text::graphemes($text));
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        return strlen($snapshot['text'] ?? '') > 3000 ? 'message_too_long' : parent::preview_validate($connection, $snapshot);
    }

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        $host = rtrim(($connection['endpoint'] ?? '') ?: 'https://bsky.social', '/');
        $cacheKey = 'sr_bsky_' . hash('sha256', $host . '|' . $connection['surface'] . '|' . $connection['secret']);
        $cached = get_transient($cacheKey);
        $session = [];
        if (is_string($cached)) {
            try {
                $session = json_decode(Security::decrypt($cached), true) ?: [];
            } catch (\RuntimeException $exception) {
                delete_transient($cacheKey);
            }
        }
        if (!$session) {
            $response = self::post_json($host . '/xrpc/com.atproto.server.createSession', [
                'identifier' => $connection['surface'],
                'password' => $token,
            ]);
            $result = HttpClient::classify($response);
            if (!$result['ok']) {
                return $result;
            }
            $data = self::json($response);
            if (!is_string($data['did'] ?? null) || !preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/D', $data['did'])
                || !is_string($data['accessJwt'] ?? null) || !preg_match('/^[A-Za-z0-9._-]{8,4096}$/D', $data['accessJwt'])) {
                return self::failure('authentication');
            }
            $session = ['did' => $data['did'], 'token' => $data['accessJwt'], 'host' => $host];
            if ($host === 'https://bsky.social') {
                foreach ($data['didDoc']['service'] ?? [] as $service) {
                    $serviceUrl = $service['serviceEndpoint'] ?? '';
                    if (in_array($service['id'] ?? '', ['#atproto_pds', $data['did'] . '#atproto_pds'], true)
                        && is_string($serviceUrl)
                        && preg_match('~^https://[a-z0-9.-]+\.host\.bsky\.network$~D', $serviceUrl)
                        && Security::validate_endpoint($serviceUrl)) {
                        $session['host'] = $serviceUrl;
                        break;
                    }
                }
            }
            try {
                $json = wp_json_encode($session);
                if ($json !== false) {
                    set_transient($cacheKey, Security::encrypt($json), 1800);
                }
            } catch (\RuntimeException $exception) {
                // Publishing can proceed without the session cache.
            }
        }
        $record = [
            '$type' => self::COLLECTION, 'text' => $snapshot['text'],
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        foreach (Text::links($record['text']) as [$link, $offset]) {
            $record['facets'][] = [
                'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($link)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $link]],
            ];
        }
        $url = $session['host'] . '/xrpc/com.atproto.repo.createRecord';
        $response = self::post_json($url, ['repo' => $session['did'], 'collection' => self::COLLECTION, 'record' => $record], $session['token']);
        $result = self::result($response, self::json($response)['uri'] ?? null);
        if (($result['code'] ?? '') === 'authentication') {
            delete_transient($cacheKey);
            if (is_string($cached) && (int) wp_remote_retrieve_response_code($response) === 401) {
                return ['ok' => false, 'code' => 'authentication', 'retry' => true];
            }
        }
        return $result;
    }
}
