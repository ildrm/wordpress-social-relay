<?php
namespace SocialRelay;

final class Text
{
    public static function length(string $text): int
    {
        return preg_match_all('/./us', $text) ?: 0;
    }

    public static function truncate(string $text, int $limit): string
    {
        preg_match('/^.{0,' . $limit . '}/us', $text, $matches);
        return $matches[0] ?? '';
    }

    /** @return array<int,array{0:string,1:int}> URL and UTF-8 byte offset. */
    public static function links(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\x27]+~iu', $text, $matches, PREG_OFFSET_CAPTURE);
        $links = [];
        foreach ($matches[0] ?? [] as [$url, $offset]) {
            $url = preg_replace('/[.,!?:;،؛؟]+$/u', '', $url);
            foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
                while (str_ends_with($url, $close) && substr_count($url, $close) > substr_count($url, $open)) {
                    $url = substr($url, 0, -1);
                }
            }
            if (self::is_web_url($url)) {
                $links[] = [$url, $offset];
            }
        }
        return $links;
    }

    public static function is_web_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        return strlen($url) <= 2048 && !preg_match('/\s/u', $url) && is_array($parts)
            && in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function with_weighted_links(string $text, int $weight, callable $count): int
    {
        $total = $start = 0;
        foreach (self::links($text) as [$url, $offset]) {
            $total += $count(substr($text, $start, $offset - $start)) + $weight;
            $start = $offset + strlen($url);
        }
        return $total + $count(substr($text, $start));
    }

    public static function graphemes(string $text): array
    {
        preg_match_all('/\X/u', $text, $matches);
        return $matches[0] ?? [];
    }

    public static function is_emoji(string $cluster): bool
    {
        return (bool) preg_match('/[\p{Extended_Pictographic}\p{Regional_Indicator}\x{20E3}]/u', $cluster);
    }
}
