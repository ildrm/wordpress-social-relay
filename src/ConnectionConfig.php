<?php
namespace SocialRelay;

final class ConnectionConfig
{
    public static function valid_destination(string $provider, string $surface, string $endpoint): bool
    {
        if (!self::valid_surface($provider, $surface)) {
            return false;
        }
        return match ($provider) {
            'webhook' => Security::validate_endpoint($endpoint),
            'mastodon' => self::valid_origin($endpoint) && Security::validate_endpoint($endpoint),
            'bluesky' => $endpoint === '' || (self::valid_origin($endpoint) && Security::validate_endpoint($endpoint)),
            default => $endpoint === '',
        };
    }

    public static function valid_surface(string $provider, string $surface): bool
    {
        if ($surface === '' || Text::length($surface) > 190) {
            return false;
        }
        return match ($provider) {
            'facebook', 'instagram', 'threads', 'pinterest' => (bool) preg_match('/^[1-9][0-9]{0,31}$/D', $surface),
            'telegram' => (bool) preg_match('/^(?:-\d+|@[A-Za-z0-9_]{5,32})$/D', $surface),
            'whatsapp' => (bool) preg_match('~^https://(?:www\.)?whatsapp\.com/channel/[A-Za-z0-9_-]{10,80}/?$~D', $surface),
            'linkedin' => (bool) preg_match('/^urn:li:(?:person|organization):[A-Za-z0-9_-]+$/D', $surface),
            'bluesky' => (bool) preg_match('/^(?:did:[a-z0-9]+:[A-Za-z0-9._:%-]+|(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*)$/Di', $surface),
            'tumblr' => (bool) preg_match('/^(?:t:[A-Za-z0-9_-]+|[A-Za-z0-9][A-Za-z0-9.-]*)$/D', $surface),
            'reddit' => (bool) preg_match('/^[A-Za-z0-9_]{2,21}$/D', $surface),
            'mastodon', 'x', 'webhook' => true,
            default => false,
        };
    }

    public static function valid_secret(string $provider, string $secret): bool
    {
        return match ($provider) {
            'whatsapp' => $secret === '',
            'webhook' => strlen($secret) >= 20 && strlen($secret) <= 1000,
            'telegram' => strlen($secret) <= 1000 && (bool) preg_match('/^\d{5,12}:[A-Za-z0-9_-]{20,}$/D', $secret),
            'bluesky' => (bool) preg_match('/^[a-z0-9]{4}(?:-[a-z0-9]{4}){3}$/Di', $secret),
            'facebook', 'instagram', 'linkedin', 'threads', 'mastodon', 'pinterest', 'x', 'tumblr', 'reddit'
                => (bool) preg_match('/^[\x21-\x7e]{8,4096}$/D', $secret),
            default => false,
        };
    }

    private static function valid_origin(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && in_array($parts['path'] ?? '', ['', '/'], true)
            && !isset($parts['query']) && (!isset($parts['port']) || $parts['port'] === 443);
    }
}
