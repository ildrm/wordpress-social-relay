<?php
namespace SocialRelay;

final class HttpClient
{
    public static function post(string $url, array $headers, string $body)
    {
        return wp_safe_remote_post($url, self::options($headers) + ['body' => $body]);
    }

    public static function get(string $url, array $headers)
    {
        return wp_safe_remote_get($url, self::options($headers));
    }

    private static function options(array $headers): array
    {
        return [
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'headers' => $headers,
        ];
    }

    public static function classify($response): array
    {
        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 'network', 'retry' => true];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
            $seconds = is_scalar($retryAfter) && ctype_digit((string) $retryAfter) ? (int) $retryAfter : 0;
            if ($seconds === 0 && is_string($retryAfter) && $retryAfter !== '') {
                $timestamp = strtotime($retryAfter);
                $seconds = $timestamp === false ? 0 : max(0, $timestamp - time());
            }
            return ['ok' => false, 'code' => 'rate_limit', 'retry' => true, 'retry_after' => min(86400, $seconds)];
        }
        if ($status >= 500 || $status === 408) {
            return ['ok' => false, 'code' => 'temporary', 'retry' => true];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'code' => in_array($status, [401, 403], true) ? 'authentication' : 'rejected', 'retry' => false];
        }
        return ['ok' => true];
    }
}
