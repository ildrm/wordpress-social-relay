<?php
namespace SocialRelay;

final class Security
{
    public static function can_send(): bool
    {
        if (get_option('social_relay_enabled', '0') !== '1') {
            return false;
        }
        if (get_option('social_relay_origin_url', '') !== home_url('/')) {
            return false;
        }
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'production' && get_option('social_relay_allow_staging', '0') !== '1') {
            return false;
        }
        return true;
    }

    public static function validate_endpoint(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = rtrim(trim((string) $parts['host'], '[]'), '.');
        if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9.-]+$/iD', $host) || !str_contains($host, '.') || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/iD', $host)) {
            return false;
        }
        return wp_http_validate_url($url) !== false;
    }

    private static function key(string $version): string
    {
        if ($version === 'wp') {
            return hash_hkdf('sha256', wp_salt('auth') . wp_salt('secure_auth'), 32, 'social-relay-connection-v1');
        }
        $keys = defined('SOCIAL_RELAY_ENCRYPTION_KEYS') ? SOCIAL_RELAY_ENCRYPTION_KEYS : [];
        $key = is_array($keys) ? ($keys[$version] ?? null) : null;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/iD', $key)) {
            throw new \RuntimeException('The configured Social Relay encryption key is unavailable.');
        }
        return hex2bin($key);
    }

    public static function active_key_id(): string
    {
        $version = defined('SOCIAL_RELAY_ENCRYPTION_KEY_ID') ? SOCIAL_RELAY_ENCRYPTION_KEY_ID : 'wp';
        if (!is_string($version) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $version)) {
            throw new \RuntimeException('The configured Social Relay encryption key ID is invalid.');
        }
        return $version;
    }

    public static function encrypt(string $value): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL is required for connection secrets.');
        }
        $version = self::active_key_id();
        $key = self::key($version);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'social-relay:' . $version);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt connection secret.');
        }
        return $version . ':' . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required for connection secrets.');
        }
        $versioned = str_contains($encoded, ':');
        [$version, $ciphertext] = $versioned ? explode(':', $encoded, 2) : ['wp', $encoded];
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Connection secret is unreadable.');
        }
        $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($version), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $versioned ? 'social-relay:' . $version : '');
        if ($value === false) {
            throw new \RuntimeException('Connection secret cannot be decrypted.');
        }
        return $value;
    }

    public static function safe_error(string $code): string
    {
        $messages = [
            'unsafe_endpoint' => __('The destination URL is unsafe or unavailable.', 'social-relay'),
            'network' => __('The destination could not be reached. It will be retried.', 'social-relay'),
            'rate_limit' => __('The destination requested a slower rate. It will be retried.', 'social-relay'),
            'temporary' => __('The destination is temporarily unavailable. It will be retried.', 'social-relay'),
            'rejected' => __('The destination rejected the publication. Check the connection and payload.', 'social-relay'),
            'connection' => __('The connection is missing or disabled.', 'social-relay'),
            'secret' => __('The connection secret could not be read. Replace it on the destination.', 'social-relay'),
            'delivery_unknown' => __('The destination may have accepted the message. Check the destination before retrying.', 'social-relay'),
        ];
        return $messages[$code] ?? __('Publication failed. Review the destination configuration.', 'social-relay');
    }
}
