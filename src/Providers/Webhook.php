<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\ProviderInterface;
use SocialRelay\Security;

final class Webhook implements ProviderInterface
{
    public function id(): string { return 'webhook'; }

    public function capabilities(): array
    {
        return ['supported' => true, 'surface' => 'https_endpoint', 'text' => true,
            'link' => true, 'image' => false, 'video' => false, 'max_payload_bytes' => 65536,
            'idempotency_key' => true, 'api_version' => 'social-relay-1', 'checked_at' => '2026-10-01'];
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        return strlen($snapshot['text'] ?? '') > 60000 ? 'rejected' : '';
    }

    public function validate(array $connection, array $snapshot): string
    {
        if (!Security::validate_endpoint($connection['endpoint'] ?? '')) {
            return 'unsafe_endpoint';
        }
        return $this->preview_validate($connection, $snapshot);
    }

    public function send(array $connection, array $snapshot, string $idempotencyKey): array
    {
        try {
            $secret = Security::decrypt($connection['secret']);
        } catch (\RuntimeException $exception) {
            return ['ok' => false, 'code' => 'secret', 'retry' => false];
        }
        $body = wp_json_encode([
            'idempotency_key' => $idempotencyKey,
            'source_id' => $snapshot['source_id'],
            'source_type' => $snapshot['source_type'],
            'source_url' => $snapshot['url'],
            'title' => $snapshot['title'],
            'text' => $snapshot['text'],
            'profile' => $snapshot['profile'],
            'objective' => $snapshot['objective'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$body || strlen($body) > 65536) {
            return ['ok' => false, 'code' => 'rejected', 'retry' => false];
        }
        $response = HttpClient::post($connection['endpoint'], [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Social-Relay-Idempotency-Key' => $idempotencyKey,
            'X-Social-Relay-Signature' => 'sha256=' . hash_hmac('sha256', $body, $secret),
        ], $body);
        $result = HttpClient::classify($response);
        if (!$result['ok']) {
            return $result;
        }
        $remoteId = wp_remote_retrieve_header($response, 'x-social-relay-remote-id');
        return ['ok' => true, 'remote_id' => is_string($remoteId) ? substr($remoteId, 0, 190) : ''];
    }
}
