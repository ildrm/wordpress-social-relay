<?php
namespace SocialRelay;

final class PublicationKey
{
    public static function first(int $siteId, int $postId, int $connectionId, string $text): string
    {
        return hash('sha256', $siteId . ':first_publish:' . $postId . ':' . $connectionId . ':' . $text);
    }
}
