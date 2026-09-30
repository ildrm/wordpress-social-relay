<?php
namespace SocialRelay\Providers;

use SocialRelay\HttpClient;
use SocialRelay\ProviderInterface;
use SocialRelay\Security;

final class Telegram implements ProviderInterface
{
    public function id(): string { return 'telegram'; }

    public function capabilities(): array
    {
        return ['supported' => true, 'surface' => 'group_or_channel', 'text' => true,
            'link' => true, 'image' => false, 'video' => false, 'max_characters' => 4096,
            'idempotency_key' => false, 'api_version' => 'Bot API sendMessage', 'checked_at' => '2026-10-01'];
    }

    public function validate(array $connection, array $snapshot): string
    {
        if (!preg_match('/^(?:-\d+|@[A-Za-z0-9_]{5,32})$/D', $connection['surface'] ?? '')) {
            return 'rejected';
        }
        $text = $snapshot['text'] ?? '';
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return $text === '' || $length > 4096 ? 'rejected' : '';
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        return $this->validate($connection, $snapshot);
    }

    public function send(array $connection, array $snapshot, string $idempotencyKey): array
    {
        try {
            $token = Security::decrypt($connection['secret']);
        } catch (\RuntimeException $exception) {
            return ['ok' => false, 'code' => 'secret', 'retry' => false];
        }
        if (!preg_match('/^\d{5,12}:[A-Za-z0-9_-]{20,}$/D', $token)) {
            return ['ok' => false, 'code' => 'rejected', 'retry' => false];
        }
        $body = wp_json_encode(['chat_id' => $connection['surface'], 'text' => $snapshot['text']], JSON_UNESCAPED_UNICODE);
        $response = HttpClient::post('https://api.telegram.org/bot' . $token . '/sendMessage', ['Content-Type' => 'application/json; charset=utf-8'], $body);
        $result = HttpClient::classify($response);
        if (!$result['ok']) {
            return $result;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['ok']) || empty($data['result']['message_id'])) {
            return ['ok' => false, 'code' => 'rejected', 'retry' => false];
        }
        return ['ok' => true, 'remote_id' => (string) $data['result']['message_id']];
    }
}
