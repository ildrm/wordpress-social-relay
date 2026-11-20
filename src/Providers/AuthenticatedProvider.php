<?php
namespace SocialRelay\Providers;

use SocialRelay\ConnectionConfig;
use SocialRelay\HttpClient;
use SocialRelay\ProviderInterface;
use SocialRelay\Security;
use SocialRelay\Text;

abstract class AuthenticatedProvider implements ProviderInterface
{
    protected const ID = '';
    protected const SURFACE = 'account';
    protected const TEXT_LIMIT = 0;
    protected const IMAGE = false;
    protected const LINK = true;
    protected const API = '';

    public function id(): string
    {
        return static::ID;
    }

    public function capabilities(): array
    {
        return [
            'supported' => true, 'surface' => static::SURFACE, 'text' => true, 'link' => static::LINK,
            'image' => static::IMAGE, 'video' => false, 'max_characters' => static::TEXT_LIMIT,
            'idempotency_key' => false, 'api_version' => static::API,
        ];
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        if (!ConnectionConfig::valid_surface($this->id(), $connection['surface'] ?? '')) {
            return 'connection';
        }
        $text = $snapshot['text'] ?? '';
        if ($text === '') {
            return 'message_empty';
        }
        if (strlen($text) > 60000 || $this->text_length($text) > static::TEXT_LIMIT) {
            return 'message_too_long';
        }
        return '';
    }

    protected function text_length(string $text): int
    {
        return Text::length($text);
    }

    public function validate(array $connection, array $snapshot): string
    {
        $reason = $this->preview_validate($connection, $snapshot);
        if ($reason !== '') {
            return $reason;
        }
        return ConnectionConfig::valid_destination($this->id(), $connection['surface'], $connection['endpoint'] ?? '') ? '' : 'unsafe_endpoint';
    }

    final public function send(array $connection, array $snapshot, string $idempotencyKey): array
    {
        try {
            $token = Security::decrypt($connection['secret'] ?? '');
        } catch (\RuntimeException $exception) {
            return self::failure('secret');
        }
        if (!ConnectionConfig::valid_secret($this->id(), $token)) {
            return self::failure('authentication');
        }
        return $this->publish($connection, $snapshot, $token, $idempotencyKey);
    }

    abstract protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array;

    protected static function post_json(string $url, array $payload, string $token = '', array $headers = [])
    {
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return new \WP_Error('invalid_json');
        }
        return HttpClient::post($url, $headers + self::headers($token) + ['Content-Type' => 'application/json'], $body);
    }

    protected static function post_form(string $url, array $payload, string $token, array $headers = [])
    {
        return HttpClient::post($url, $headers + self::headers($token) + [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($payload, '', '&', PHP_QUERY_RFC3986));
    }

    protected static function headers(string $token): array
    {
        return ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]) + ['Accept' => 'application/json'];
    }

    protected static function json($response): array
    {
        $data = json_decode(wp_remote_retrieve_body($response), true, 512, JSON_BIGINT_AS_STRING);
        return is_array($data) ? $data : [];
    }

    protected static function failure(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'retry' => false];
    }

    protected static function unknown(): array
    {
        return self::failure('delivery_unknown') + ['unknown' => true];
    }

    protected static function result($response, mixed $id): array
    {
        $result = HttpClient::classify($response);
        if (!$result['ok']) {
            // Only retry when the server explicitly refused the publication at its rate limit.
            return $result['retry'] && $result['code'] !== 'rate_limit' ? self::unknown() : $result;
        }
        return self::accepted($id);
    }

    protected static function accepted(mixed $id): array
    {
        return (is_string($id) || is_int($id)) && (string) $id !== ''
            ? ['ok' => true, 'remote_id' => Text::truncate((string) $id, 190)]
            : self::unknown();
    }
}
