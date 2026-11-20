<?php
namespace SocialRelay\Admin;

use SocialRelay\Admin;

abstract class Page
{
    protected static function query(string $name): string
    {
        return is_string($_GET[$name] ?? null) ? sanitize_text_field(wp_unslash($_GET[$name])) : '';
    }

    protected static function url(string $page = 'social-relay', array $args = []): string
    {
        return add_query_arg(['page' => $page] + $args, admin_url('admin.php'));
    }

    protected static function icon(string $icon): string
    {
        return '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
    }

    protected static function start(string $page, string $title, string $description, array $action = []): void
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You cannot manage Social Relay.', 'social-relay'), '', ['response' => 403]);
        }
        ?>
        <div class="wrap sr-wrap">
            <header class="sr-shell">
                <div class="sr-shell-top">
                    <a class="sr-brand" href="<?php echo esc_url(self::url()); ?>">
                        <span class="sr-brand-mark"><?php echo self::icon('dashicons-share'); ?></span>
                        <span><?php esc_html_e('Social Relay', 'social-relay'); ?></span>
                    </a>
                    <?php if ($action) : ?>
                        <a class="sr-button sr-primary" href="<?php echo esc_url($action[0]); ?>"><?php echo self::icon($action[2] ?? 'dashicons-plus-alt2'); ?><?php echo esc_html($action[1]); ?></a>
                    <?php endif; ?>
                </div>
                <nav class="sr-nav" aria-label="<?php esc_attr_e('Social Relay sections', 'social-relay'); ?>">
                    <?php foreach (Admin::pages() as $slug => [$label, $icon]) : ?>
                        <a href="<?php echo esc_url(self::url($slug)); ?>" <?php if ($page === $slug) : ?>aria-current="page"<?php endif; ?>>
                            <?php echo self::icon($icon); ?><span><?php echo esc_html($label); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </header>
            <div class="sr-content">
                <div class="sr-heading">
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php echo esc_html($description); ?></p>
                </div>
                <?php self::notices(); ?>
        <?php
    }

    protected static function end(): void
    {
        echo '</div></div>';
    }

    protected static function form(string $action, ?int $id = null, string $class = ''): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="<?php echo esc_attr($class); ?>">
            <input type="hidden" name="action" value="social_relay_action">
            <input type="hidden" name="sr_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('social_relay_action')); ?>">
            <?php if ($id !== null) : ?>
                <input type="hidden" name="id" value="<?php echo esc_attr((string) $id); ?>">
            <?php endif;
    }

    protected static function button(string $label, string $variant = 'sr-secondary', string $icon = ''): void
    {
        echo '<button type="submit" class="sr-button ' . esc_attr($variant) . '">';
        if ($icon !== '') {
            echo self::icon($icon);
        }
        echo esc_html($label) . '</button>';
    }

    protected static function field(string $name, string $label, string $value = '', array $options = []): void
    {
        $id = $options['id'] ?? 'sr-' . $name;
        $hint = $options['hint'] ?? '';
        ?>
        <div class="sr-field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($options['type'] ?? 'text'); ?>"
                value="<?php echo esc_attr($value); ?>"
                <?php foreach (['maxlength', 'minlength', 'min', 'max', 'placeholder', 'autocomplete', 'dir'] as $attribute) :
                    if (isset($options[$attribute])) {
                        echo esc_attr($attribute) . '="' . esc_attr((string) $options[$attribute]) . '" ';
                    }
                endforeach; ?>
                <?php if ($options['required'] ?? false) : ?>required<?php endif; ?>
                <?php if ($hint !== '') : ?>aria-describedby="<?php echo esc_attr($id . '-hint'); ?>"<?php endif; ?>>
            <?php if ($hint !== '') : ?><p id="<?php echo esc_attr($id . '-hint'); ?>" class="sr-hint"><?php echo esc_html($hint); ?></p><?php endif; ?>
        </div>
        <?php
    }

    protected static function empty_state(string $icon, string $title, string $description, array $action = []): void
    {
        ?>
        <div class="sr-empty">
            <span class="sr-icon-box"><?php echo self::icon($icon); ?></span>
            <h3><?php echo esc_html($title); ?></h3>
            <p><?php echo esc_html($description); ?></p>
            <?php if ($action) : ?>
                <a class="sr-button sr-secondary" href="<?php echo esc_url($action[0]); ?>"><?php echo esc_html($action[1]); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    protected static function statuses(): array
    {
        return [
            'queued' => [__('Queued', 'social-relay'), 'info'],
            'retry_wait' => [__('Waiting to retry', 'social-relay'), 'info'],
            'publishing' => [__('Sending', 'social-relay'), 'info'],
            'published' => [__('Published', 'social-relay'), 'success'],
            'awaiting_manual' => [__('Awaiting manual publication', 'social-relay'), 'warning'],
            'delivery_unknown' => [__('Needs review', 'social-relay'), 'warning'],
            'blocked' => [__('Blocked', 'social-relay'), 'danger'],
            'permanent_failure' => [__('Failed', 'social-relay'), 'danger'],
            'cancelled' => [__('Cancelled', 'social-relay'), 'neutral'],
        ];
    }

    protected static function status(string $status): string
    {
        [$label, $tone] = self::statuses()[$status] ?? [$status, 'neutral'];
        return self::badge($label, $tone);
    }

    protected static function badge(string $label, string $tone = 'neutral'): string
    {
        return '<span class="sr-badge sr-tone-' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    protected static function pages(string $page, int $current, int $total, int $perPage, array $args = []): void
    {
        if ($total <= $perPage) {
            return;
        }
        $base = str_replace('%25%23%25', '%#%', self::url($page, $args + ['paged' => '%#%']));
        $links = paginate_links([
            'base' => $base, 'format' => '', 'current' => $current,
            'total' => (int) ceil($total / $perPage),
            'prev_text' => __('Previous', 'social-relay'), 'next_text' => __('Next', 'social-relay'),
        ]);
        if ($links) {
            echo '<nav class="sr-pagination" aria-label="' . esc_attr__('Results pages', 'social-relay') . '">' . wp_kses_post($links) . '</nav>';
        }
    }

    private static function notices(): void
    {
        $messages = [
            'saved' => [__('Changes saved.', 'social-relay'), 'success'],
            'updated' => [__('Publication updated.', 'social-relay'), 'success'],
            'manual_confirmed' => [__('Manual publication recorded.', 'social-relay'), 'success'],
            'worker_ran' => [__('Queue processing was requested. Refresh to see updated statuses.', 'social-relay'), 'success'],
            'worker_paused' => [__('Publishing is paused. Enable it in Overview before running the queue.', 'social-relay'), 'error'],
            'nothing_queued' => [__('There are no new eligible publications for this post.', 'social-relay'), 'success'],
            'removed' => [__('Field mapping removed.', 'social-relay'), 'success'],
            'invalid' => [__('Changes could not be saved. Check the form and try again.', 'social-relay'), 'error'],
            'conflict' => [__('This recipe was edited in another session. Review the latest values before saving again.', 'social-relay'), 'error'],
        ];
        $notice = sanitize_key(self::query('sr_notice'));
        if (isset($messages[$notice])) {
            [$message, $type] = $messages[$notice];
            echo '<div class="sr-notice sr-notice-' . esc_attr($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '"><p>' . esc_html($message) . '</p></div>';
        }
        if (get_option('social_relay_migration_error')) {
            echo '<div class="sr-notice sr-notice-error" role="alert"><p>' . esc_html__('Social Relay could not verify its database migration. Check database permissions and indexes, then reload an admin page.', 'social-relay') . '</p></div>';
        }
        if (get_option('social_relay_storage_error')) {
            echo '<div class="sr-notice sr-notice-error" role="alert"><p>' . esc_html__('Social Relay encountered a database error. Check the database and review recent publications.', 'social-relay') . '</p></div>';
        }
    }
}
