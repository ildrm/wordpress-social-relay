<?php
declare(strict_types=1);

if (!isset($argv[1]) || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/wp-smoke.php /path/to/wordpress\n");
    exit(2);
}
$complete = false;
ob_start();
register_shutdown_function(static function () use (&$complete): void {
    if (!$complete) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        fwrite(STDERR, "WordPress bootstrap did not complete (check database service).\n");
        exit(1);
    }
});
require_once $argv[1] . '/wp-load.php';
require_once dirname(__DIR__) . '/social-relay.php';
SocialRelay\Plugin::boot();

$types = get_post_types(['public' => true], 'names');
if (!in_array('post', $types, true)) {
    throw new RuntimeException('WordPress post type unavailable.');
}
$post = new WP_Post((object) [
    'ID' => 0, 'post_type' => 'post', 'post_title' => 'Smoke <b>test</b>',
    'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
    'post_excerpt' => '', 'post_author' => 0, 'post_date_gmt' => gmdate('Y-m-d H:i:s'),
    'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
]);
$content = SocialRelay\Content::normalize($post);
if (str_contains($content['body'], '<!--') || str_contains($content['body'], '<p>')) {
    throw new RuntimeException('Block markup leaked into normalized content.');
}
$complete = true;
ob_end_clean();
echo 'WordPress ' . get_bloginfo('version') . ' bootstrap and content normalization passed.' . PHP_EOL;
