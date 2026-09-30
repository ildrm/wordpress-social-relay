<?php
namespace SocialRelay;

final class HttpClient
{
    public static function post(string $url, array $headers, string $body)
    {
        return wp_safe_remote_post($url, [
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    public static function classify($response): array
    {
        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 'network', 'retry' => true];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            return ['ok' => false, 'code' => 'rate_limit', 'retry' => true];
        }
        if ($status >= 500 || $status === 408) {
            return ['ok' => false, 'code' => 'temporary', 'retry' => true];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'code' => 'rejected', 'retry' => false];
        }
        return ['ok' => true];
    }
}
