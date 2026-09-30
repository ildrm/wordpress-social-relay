<?php
namespace SocialRelay;

final class Template
{
    public static function render(string $template, array $content): string
    {
        $result = preg_replace_callback('/\{([a-z][a-z0-9_]{0,39})\}/', static function (array $match) use ($content): string {
            $token = $match[1];
            $allowed = ['title', 'excerpt', 'body', 'url', 'author', 'date', 'profile'];
            $value = in_array($token, $allowed, true) ? ($content[$token] ?? '') : ($content['fields'][$token] ?? '');
            return is_scalar($value) ? (string) $value : '';
        }, $template);
        return trim(preg_replace('/\h+\n/u', "\n", $result));
    }
}
