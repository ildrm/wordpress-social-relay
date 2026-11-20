<?php
namespace SocialRelay\Admin;

use SocialRelay\Admin;
use SocialRelay\Plugin;
use SocialRelay\ProviderCatalog;
use SocialRelay\Security;

final class Editor extends Page
{
    public static function register(): void
    {
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name !== 'attachment' && is_post_type_viewable($type)) {
                add_meta_box('social-relay-preview', __('Social Relay', 'social-relay'), [self::class, 'render'], $type->name, 'side', 'default');
            }
        }
    }

    public static function render(\WP_Post $post): void
    {
        wp_nonce_field('social_relay_post_' . $post->ID, 'social_relay_post_nonce', false);
        $items = Plugin::preview($post);
        ?>
        <div class="sr-post-panel">
            <label class="sr-check">
                <input type="checkbox" name="social_relay_exclude" value="1" <?php checked(get_post_meta($post->ID, '_social_relay_exclude', true), '1'); ?>>
                <span><?php esc_html_e('Exclude this post from Social Relay', 'social-relay'); ?></span>
            </label>
            <?php if ($post->post_status !== 'publish') : ?>
                <p class="sr-hint"><?php esc_html_e('Save this post as a draft to refresh the preview.', 'social-relay'); ?></p>
            <?php endif; ?>
            <?php if (!Security::can_send()) : ?>
                <p><?php echo self::badge(__('Automatic publishing paused', 'social-relay'), 'warning'); ?></p>
            <?php endif; ?>
            <?php if (!$items) : ?>
                <p class="sr-hint"><?php esc_html_e('There are no enabled recipes for this post type.', 'social-relay'); ?></p>
            <?php endif; ?>
            <?php foreach ($items as $item) :
                $provider = ProviderCatalog::get($item['connection']['provider'] ?? '');
                $matches = $item['matches'] && !$item['excluded'];
                $status = $item['eligible'] ? __('Eligible', 'social-relay') : __('Not eligible', 'social-relay');
                if ($matches && $post->post_status !== 'publish') {
                    $status = __('Conditions match', 'social-relay');
                }
                ?>
                <details class="sr-preview">
                    <summary><?php echo esc_html($item['recipe']['name']); ?> <?php echo self::badge($status, $item['eligible'] || ($matches && $post->post_status !== 'publish') ? 'success' : 'neutral'); ?></summary>
                    <div class="sr-preview-body">
                        <p><strong><?php esc_html_e('Destination', 'social-relay'); ?></strong><br><?php echo esc_html($item['connection']['name'] ?? __('Unavailable', 'social-relay')); ?></p>
                        <?php if (!empty($provider['manual'])) : ?>
                            <p><?php echo self::badge(__('Manual publication', 'social-relay'), 'warning'); ?></p>
                        <?php endif; ?>
                        <?php if ($item['excluded']) : ?>
                            <p class="sr-hint"><?php esc_html_e('This post is excluded.', 'social-relay'); ?></p>
                        <?php elseif (!$item['compatibility']['compatible']) : ?>
                            <p class="sr-hint"><?php echo esc_html(Security::safe_error($item['compatibility']['reason'])); ?></p>
                        <?php endif; ?>
                        <p><strong><?php esc_html_e('Message preview', 'social-relay'); ?></strong></p>
                        <pre class="sr-message" dir="auto"><?php echo esc_html($item['text']); ?></pre>
                        <details class="sr-trace-details">
                            <summary><?php esc_html_e('Condition details', 'social-relay'); ?></summary>
                            <ul class="sr-trace"><?php self::trace($item['trace']); ?></ul>
                        </details>
                    </div>
                </details>
            <?php endforeach; ?>
            <?php if (Plugin::can_publish($post) && current_user_can(Admin::CAPABILITY) && current_user_can('edit_post', $post->ID)) :
                $formId = 'sr-queue-post-' . $post->ID;
                add_action('admin_footer', static function () use ($post, $formId): void {
                    ?>
                    <form id="<?php echo esc_attr($formId); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="social_relay_queue_post">
                        <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post->ID); ?>">
                        <?php wp_nonce_field('social_relay_queue_post_' . $post->ID, '_wpnonce', false); ?>
                    </form>
                    <?php
                });
                ?>
                <p><button type="submit" class="button" form="<?php echo esc_attr($formId); ?>"><?php esc_html_e('Queue eligible recipes', 'social-relay'); ?></button></p>
                <p class="sr-hint"><?php esc_html_e('Existing identical publications stay in the history without creating duplicates.', 'social-relay'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function trace(array $trace): void
    {
        $summary = $trace['type'] === 'condition'
            ? $trace['field'] . ' ' . $trace['operator'] . ' ' . (is_array($trace['expected']) ? implode(', ', $trace['expected']) : $trace['expected'])
            : strtoupper($trace['type']);
        ?>
        <li>
            <strong><?php echo esc_html($trace['pass'] ? __('Pass', 'social-relay') : __('Fail', 'social-relay')); ?></strong>
            <bdi><?php echo esc_html($summary); ?></bdi>
            <?php if (!empty($trace['children'])) : ?>
                <ul><?php foreach ($trace['children'] as $child) {
                    self::trace($child);
                } ?></ul>
            <?php endif; ?>
        </li>
        <?php
    }
}
