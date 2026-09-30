<?php
namespace SocialRelay;

final class Content
{
    public static function normalize(\WP_Post $post, string $profile = 'article'): array
    {
        $body = preg_replace('/<!--\s*\/?wp:[\s\S]*?-->/', '', $post->post_content);
        $body = wp_strip_all_tags(strip_shortcodes($body), true);
        $body = trim(preg_replace('/\s+/u', ' ', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8')));
        $excerpt = trim(wp_strip_all_tags(strip_shortcodes($post->post_excerpt), true));
        if ($excerpt === '') {
            $excerpt = wp_trim_words($body, 35, '…');
        }
        $content = [
            'source_id' => (int) $post->ID,
            'source_type' => $post->post_type,
            'source_revision' => (string) $post->post_modified_gmt,
            'profile' => $profile,
            'title' => html_entity_decode(wp_strip_all_tags(get_the_title($post), true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'excerpt' => $excerpt,
            'body' => $body,
            'url' => get_permalink($post),
            'author' => get_the_author_meta('display_name', (int) $post->post_author),
            'date' => $post->post_date_gmt,
            'locale' => get_locale(),
            'featured_image' => get_the_post_thumbnail_url($post, 'full') ?: '',
            'taxonomies' => [],
            'fields' => [],
        ];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
            if (!is_wp_error($terms)) {
                $content['taxonomies'][$taxonomy] = array_values($terms);
            }
        }
        $mapping = get_option('social_relay_field_mapping', []);
        foreach (($mapping[$post->post_type] ?? []) as $target => $source) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $target) || !preg_match('/^[A-Za-z0-9_\-]{1,100}$/', (string) $source)) {
                continue;
            }
            $value = get_post_meta($post->ID, $source, true);
            if (is_scalar($value)) {
                $content['fields'][$target] = (string) $value;
            }
        }
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $content['fields']['sku'] = (string) $product->get_sku();
                $content['fields']['price'] = (string) $product->get_price();
                $content['fields']['regular_price'] = (string) $product->get_regular_price();
                $content['fields']['sale_price'] = (string) $product->get_sale_price();
                $content['fields']['stock_status'] = (string) $product->get_stock_status();
                $content['fields']['currency'] = get_woocommerce_currency();
            }
        }
        return apply_filters('social_relay_normalized_content', $content, $post);
    }
}
