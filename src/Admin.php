<?php
namespace SocialRelay;

final class Admin
{
    public const CAPABILITY = 'manage_social_relay';

    public static function pages(): array
    {
        return [
            'social-relay' => [__('Overview', 'social-relay'), 'dashicons-dashboard', [Admin\Overview::class, 'render']],
            'social-relay-destinations' => [__('Destinations', 'social-relay'), 'dashicons-admin-links', [Admin\Destinations::class, 'render']],
            'social-relay-recipes' => [__('Recipes', 'social-relay'), 'dashicons-randomize', [Admin\Recipes::class, 'render']],
            'social-relay-jobs' => [__('Publications', 'social-relay'), 'dashicons-list-view', [Admin\Publications::class, 'render']],
            'social-relay-mapping' => [__('Field mapping', 'social-relay'), 'dashicons-editor-code', [Admin\FieldMapping::class, 'render']],
        ];
    }

    public static function menu(): void
    {
        $pages = self::pages();
        $hooks = [
            add_menu_page(__('Social Relay', 'social-relay'), __('Social Relay', 'social-relay'), self::CAPABILITY, 'social-relay', $pages['social-relay'][2], 'dashicons-share', 58),
        ];
        foreach ($pages as $slug => [$title, , $render]) {
            $hooks[] = add_submenu_page('social-relay', $title, $title, self::CAPABILITY, $slug, $render);
        }

        add_action('admin_enqueue_scripts', static function (string $hook) use ($hooks): void {
            $assetUrl = plugins_url('assets/', SOCIAL_RELAY_PATH . 'social-relay.php');
            if (in_array($hook, $hooks, true)) {
                wp_enqueue_style('social-relay-admin', $assetUrl . 'admin.css', ['dashicons'], SOCIAL_RELAY_VERSION);
                wp_enqueue_script('social-relay-admin', $assetUrl . 'admin.js', [], SOCIAL_RELAY_VERSION, true);
            } elseif (in_array($hook, ['post.php', 'post-new.php'], true)) {
                $screen = get_current_screen();
                $type = $screen && $screen->post_type ? get_post_type_object($screen->post_type) : null;
                if ($type && $type->public && $type->name !== 'attachment' && is_post_type_viewable($type)) {
                    wp_enqueue_style('social-relay-admin', $assetUrl . 'admin.css', ['dashicons'], SOCIAL_RELAY_VERSION);
                }
            }
        });
    }

    public static function meta_boxes(): void
    {
        Admin\Editor::register();
    }
}
