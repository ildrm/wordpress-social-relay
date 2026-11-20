<?php
namespace SocialRelay\Admin;

use SocialRelay\Database;
use SocialRelay\ProviderCatalog;

final class Destinations extends Page
{
    private const SLUG = 'social-relay-destinations';

    public static function render(): void
    {
        $catalog = ProviderCatalog::all();
        $provider = sanitize_key(self::query('provider'));
        $selection = $catalog[$provider] ?? null;
        global $wpdb;
        $table = Database::table('connections');
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $requestedView = self::query('view');
        $view = $selection || $requestedView === 'catalog' || ($requestedView !== 'connected' && !$total) ? 'catalog' : 'connected';
        self::start(
            self::SLUG, __('Destinations', 'social-relay'),
            __('Connect the accounts, channels, and boards where your recipes publish.', 'social-relay'),
            $view === 'connected' ? [self::url(self::SLUG, ['view' => 'catalog']) . '#sr-catalog', __('Add destination', 'social-relay')] : []
        );
        $page = min(max(1, absint(self::query('paged'))), max(1, (int) ceil($total / 24)));
        ?>
        <nav class="sr-view-tabs" aria-label="<?php esc_attr_e('Destination views', 'social-relay'); ?>">
            <a href="<?php echo esc_url(self::url(self::SLUG, ['view' => 'catalog'])); ?>" <?php if ($view === 'catalog') : ?>aria-current="page"<?php endif; ?>><?php echo self::icon('dashicons-grid-view'); ?><?php esc_html_e('Network catalog', 'social-relay'); ?></a>
            <a href="<?php echo esc_url(self::url(self::SLUG, ['view' => 'connected'])); ?>" <?php if ($view === 'connected') : ?>aria-current="page"<?php endif; ?>><?php echo self::icon('dashicons-admin-links'); ?><?php echo esc_html(sprintf(__('Connected (%s)', 'social-relay'), number_format_i18n($total))); ?></a>
        </nav>
        <?php
        if ($selection) {
            self::connection_form($provider, $selection);
        } elseif ($view === 'catalog') {
            self::catalog($catalog);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,name,provider,surface,active,created_at FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", 24, ($page - 1) * 24), ARRAY_A);
            self::connections($rows ?: [], $total, $page);
        }
        self::end();
    }

    private static function catalog(array $catalog): void
    {
        ?>
        <section id="sr-catalog" aria-labelledby="sr-catalog-title" data-sr-catalog>
            <div class="sr-section-heading">
                <div>
                    <h2 id="sr-catalog-title"><?php esc_html_e('Choose a network', 'social-relay'); ?></h2>
                    <p><?php esc_html_e('Select a network to see its connection requirements.', 'social-relay'); ?></p>
                </div>
                <label class="sr-search" hidden data-sr-search-wrapper>
                    <span class="screen-reader-text"><?php esc_html_e('Filter networks', 'social-relay'); ?></span>
                    <?php echo self::icon('dashicons-search'); ?>
                    <input type="search" data-sr-network-search placeholder="<?php esc_attr_e('Find a network', 'social-relay'); ?>">
                </label>
            </div>
            <div class="sr-provider-grid">
                <?php foreach ($catalog as $id => $provider) : ?>
                    <a class="sr-provider-card" data-sr-network="<?php echo esc_attr($provider['name']); ?>" data-provider="<?php echo esc_attr($id); ?>" href="<?php echo esc_url(self::url(self::SLUG, ['provider' => $id]) . '#sr-connect'); ?>">
                        <span class="sr-provider-top">
                            <span class="sr-provider-mark"><?php echo self::icon($provider['icon']); ?></span>
                            <?php echo self::icon('dashicons-arrow-right-alt2'); ?>
                        </span>
                        <strong><?php echo esc_html($provider['name']); ?></strong>
                        <span class="sr-provider-description"><?php echo esc_html($provider['description']); ?></span>
                        <?php if ($provider['manual']) : ?>
                            <?php echo self::badge(__('Manual publication', 'social-relay'), 'warning'); ?>
                        <?php elseif ($provider['image_required']) : ?>
                            <?php echo self::badge(__('Featured image required', 'social-relay'), 'info'); ?>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="sr-catalog-empty" role="status" hidden data-sr-network-empty><?php esc_html_e('No matching networks.', 'social-relay'); ?></p>
        </section>
        <?php
    }

    private static function connection_form(string $provider, array $details): void
    {
        ?>
        <div class="sr-back"><a href="<?php echo esc_url(self::url(self::SLUG, ['view' => 'catalog']) . '#sr-catalog'); ?>"><?php echo self::icon('dashicons-arrow-left-alt2'); ?><?php esc_html_e('Back to network catalog', 'social-relay'); ?></a></div>
        <div class="sr-editor-grid" id="sr-connect">
            <section class="sr-card sr-form-card">
                <div class="sr-card-head">
                    <div class="sr-card-identity" data-provider="<?php echo esc_attr($provider); ?>">
                        <span class="sr-provider-mark"><?php echo self::icon($details['icon']); ?></span>
                        <div><h2><?php echo esc_html(sprintf(__('Connect %s', 'social-relay'), $details['name'])); ?></h2><p><?php echo esc_html($details['description']); ?></p></div>
                    </div>
                </div>
                <?php self::form('save_connection'); ?>
                <div class="sr-form-body">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($provider); ?>">
                    <?php
                    self::field('name', __('Destination name', 'social-relay'), '', [
                        'required' => true, 'maxlength' => 190,
                        'hint' => __('A label you will recognize when setting up recipes.', 'social-relay'),
                    ]);
                    self::field('surface', $details['surface_label'], '', [
                        'required' => true, 'maxlength' => 190, 'dir' => 'auto', 'hint' => $details['surface_hint'],
                        'type' => $details['manual'] ? 'url' : 'text',
                    ]);
                    if ($details['endpoint_label'] !== '') {
                        self::field('endpoint', $details['endpoint_label'], '', [
                            'type' => 'url', 'required' => $provider !== 'bluesky', 'maxlength' => 2048,
                            'hint' => $details['endpoint_hint'], 'dir' => 'ltr',
                        ]);
                    }
                    if ($details['secret_label'] !== '') {
                        self::field('secret', $details['secret_label'], '', [
                            'type' => 'password', 'required' => true, 'maxlength' => 4096,
                            'autocomplete' => 'new-password', 'dir' => 'ltr', 'hint' => $details['secret_hint'],
                        ]);
                    }
                    ?>
                    <div class="sr-form-actions">
                        <?php self::button(__('Save destination', 'social-relay'), 'sr-primary', 'dashicons-yes'); ?>
                        <a class="sr-button sr-secondary" href="<?php echo esc_url(self::url(self::SLUG, ['view' => 'catalog'])); ?>"><?php esc_html_e('Cancel', 'social-relay'); ?></a>
                    </div>
                </div>
                </form>
            </section>
            <aside class="sr-card sr-aside">
                <span class="sr-icon-box"><?php echo self::icon($details['manual'] ? 'dashicons-format-chat' : 'dashicons-lock'); ?></span>
                <h2><?php esc_html_e('Before connecting', 'social-relay'); ?></h2>
                <?php if ($details['manual']) : ?>
                    <p><?php esc_html_e('Social Relay prepares a channel update. A channel administrator copies the message, publishes it in WhatsApp, and records the publication here.', 'social-relay'); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('Use credentials that can publish to the selected account. Credentials are encrypted and can be replaced after setup.', 'social-relay'); ?></p>
                <?php endif; ?>
                <?php if ($details['image_required']) : ?>
                    <p><?php esc_html_e('WordPress posts sent here need a publicly reachable featured image.', 'social-relay'); ?></p>
                <?php endif; ?>
                <?php if ($details['docs'] !== '') : ?>
                    <a class="sr-text-link" href="<?php echo esc_url($details['docs']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open network documentation', 'social-relay'); ?><?php echo self::icon('dashicons-external'); ?><span class="screen-reader-text"><?php esc_html_e(' (opens in a new tab)', 'social-relay'); ?></span></a>
                <?php endif; ?>
            </aside>
        </div>
        <?php
    }

    private static function connections(array $rows, int $total, int $page): void
    {
        ?>
        <section class="sr-card">
            <div class="sr-card-head"><h2><?php esc_html_e('Connected destinations', 'social-relay'); ?></h2></div>
            <?php if (!$rows) :
                self::empty_state('dashicons-admin-links', __('No destinations connected', 'social-relay'), __('Choose a network to connect your first publication destination.', 'social-relay'), [self::url(self::SLUG, ['view' => 'catalog']) . '#sr-catalog', __('Browse networks', 'social-relay')]);
            else : ?>
                <ul class="sr-list sr-connections">
                    <?php foreach ($rows as $row) :
                        $details = ProviderCatalog::get($row['provider']);
                        $active = (bool) $row['active'];
                        ?>
                        <li data-provider="<?php echo esc_attr($row['provider']); ?>">
                            <span class="sr-provider-mark"><?php echo self::icon($details['icon'] ?? 'dashicons-admin-links'); ?></span>
                            <div class="sr-list-body">
                                <strong><?php echo esc_html($row['name']); ?></strong>
                                <small><?php echo esc_html($details['name'] ?? $row['provider']); ?> <span aria-hidden="true">·</span> <bdi><?php echo esc_html($row['surface']); ?></bdi></small>
                            </div>
                            <?php echo self::badge($active ? __('Active', 'social-relay') : __('Disabled', 'social-relay'), $active ? 'success' : 'neutral'); ?>
                            <details class="sr-destination-actions">
                                <summary class="sr-button sr-secondary"><?php esc_html_e('Manage', 'social-relay'); ?><span class="screen-reader-text"> <?php echo esc_html($row['name']); ?></span><?php echo self::icon('dashicons-arrow-down-alt2'); ?></summary>
                                <div class="sr-destination-actions-body">
                                    <?php self::form('toggle_connection', (int) $row['id']); ?>
                                    <input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>">
                                    <?php self::button($active ? __('Disable destination', 'social-relay') : __('Enable destination', 'social-relay')); ?>
                                    </form>
                                    <?php if (!empty($details['secret_label'])) : ?>
                                        <?php self::form('replace_secret', (int) $row['id']); ?>
                                        <?php self::field('secret', $details['secret_label'], '', [
                                            'id' => 'sr-secret-' . $row['id'], 'type' => 'password', 'required' => true,
                                            'maxlength' => 4096, 'autocomplete' => 'new-password', 'dir' => 'ltr',
                                        ]); ?>
                                        <?php self::button(__('Replace credential', 'social-relay')); ?>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php self::pages(self::SLUG, $page, $total, 24, ['view' => 'connected']); ?>
            <?php endif; ?>
        </section>
        <?php
    }
}
