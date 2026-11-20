<?php
namespace SocialRelay\Admin;

use SocialRelay\Database;
use SocialRelay\ProviderCatalog;
use SocialRelay\Rules;

final class Recipes extends Page
{
    private const SLUG = 'social-relay-recipes';

    public static function render(): void
    {
        global $wpdb;
        $id = absint(self::query('edit'));
        $create = self::query('create') === '1';
        $table = Database::table('recipes');
        $destinations = Database::table('connections');
        $edit = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A) : null;
        $editing = $create || $edit;
        $createUrl = self::url(self::SLUG, ['create' => '1']) . '#sr-recipe-editor';
        self::start(
            self::SLUG,
            __('Recipes', 'social-relay'),
            __('Choose which posts to share, where they go, and what each message says.', 'social-relay'),
            $editing ? [] : [$createUrl, __('Create recipe', 'social-relay')]
        );
        if ($id && !$edit) {
            echo '<div class="sr-notice sr-notice-error" role="alert"><p>' . esc_html__('The selected recipe could not be found.', 'social-relay') . '</p></div>';
        }
        if ($editing) {
            $connections = $wpdb->get_results($wpdb->prepare("SELECT id,name,provider,active FROM $destinations WHERE active = 1 OR id = %d ORDER BY name ASC, id ASC", (int) ($edit['connection_id'] ?? 0)), ARRAY_A);
            self::editor($edit ?: [], $connections ?: []);
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $page = min(max(1, absint(self::query('paged'))), max(1, (int) ceil($total / 20)));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT r.id,r.name,r.post_type,r.priority,r.enabled,c.name AS destination,c.active,c.provider FROM $table r LEFT JOIN $destinations c ON c.id = r.connection_id ORDER BY r.priority DESC, r.id DESC LIMIT %d OFFSET %d", 20, ($page - 1) * 20), ARRAY_A);
            self::listing($rows ?: [], $total, $page, $createUrl);
        }
        self::end();
    }

    private static function listing(array $rows, int $total, int $page, string $createUrl): void
    {
        ?>
        <section class="sr-card">
            <div class="sr-card-head"><h2><?php esc_html_e('Your recipes', 'social-relay'); ?></h2></div>
            <?php if (!$rows) :
                self::empty_state('dashicons-randomize', __('Create your first recipe', 'social-relay'), __('Start with a post type and a connected destination. Add conditions when you need more control.', 'social-relay'), [$createUrl, __('Create recipe', 'social-relay')]);
            else : ?>
                <div class="sr-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Recipes table', 'social-relay'); ?>">
                    <table class="sr-table">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e('Recipe', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Post type', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Destination', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Priority', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'social-relay'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($rows as $row) :
                                $type = get_post_type_object($row['post_type']);
                                $provider = ProviderCatalog::get($row['provider'] ?? '');
                                ?>
                                <tr>
                                    <th scope="row"><a class="sr-table-link" href="<?php echo esc_url(self::url(self::SLUG, ['edit' => $row['id']]) . '#sr-recipe-editor'); ?>"><?php echo esc_html($row['name']); ?></a></th>
                                    <td><?php echo esc_html($type->labels->singular_name ?? $row['post_type']); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($row['destination'] ?: __('Missing destination', 'social-relay')); ?></strong>
                                        <?php if ($provider) : ?><small><?php echo esc_html($provider['name']); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($row['priority']); ?></td>
                                    <td><?php
                                        if (!$row['enabled']) {
                                            echo self::badge(__('Disabled', 'social-relay'));
                                        } elseif (!$row['active']) {
                                            echo self::badge(__('Destination unavailable', 'social-relay'), 'warning');
                                        } else {
                                            echo self::badge(__('Enabled', 'social-relay'), 'success');
                                        }
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php self::pages(self::SLUG, $page, $total, 20); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function editor(array $edit, array $connections): void
    {
        ?>
        <div class="sr-back"><a href="<?php echo esc_url(self::url(self::SLUG)); ?>"><?php echo self::icon('dashicons-arrow-left-alt2'); ?><?php esc_html_e('Back to recipes', 'social-relay'); ?></a></div>
        <div class="sr-editor-grid" id="sr-recipe-editor">
            <section class="sr-card sr-form-card">
                <div class="sr-card-head"><h2><?php echo esc_html($edit ? __('Edit recipe', 'social-relay') : __('Create a recipe', 'social-relay')); ?></h2></div>
                <?php if (!$connections) :
                    self::empty_state('dashicons-admin-links', __('Connect a destination first', 'social-relay'), __('Every recipe needs a destination for its messages.', 'social-relay'), [self::url('social-relay-destinations', ['view' => 'catalog']) . '#sr-catalog', __('Browse networks', 'social-relay')]);
                else :
                    self::recipe_form($edit, $connections);
                endif; ?>
            </section>
            <aside class="sr-card sr-aside">
                <span class="sr-icon-box"><?php echo self::icon('dashicons-visibility'); ?></span>
                <h2><?php esc_html_e('Preview before publishing', 'social-relay'); ?></h2>
                <p><?php esc_html_e('After saving, open a WordPress post and find the Social Relay panel. It shows the rendered message, whether conditions match, and any issues to fix.', 'social-relay'); ?></p>
                <h3><?php esc_html_e('Message variables', 'social-relay'); ?></h3>
                <p><?php esc_html_e('Use variables such as {title} and {url} to pull values from each post. Custom fields become available through Field mapping.', 'social-relay'); ?></p>
                <a class="sr-text-link" href="<?php echo esc_url(self::url('social-relay-mapping')); ?>"><?php esc_html_e('Open field mapping', 'social-relay'); ?><?php echo self::icon('dashicons-arrow-right-alt2'); ?></a>
            </aside>
        </div>
        <?php
    }

    private static function recipe_form(array $edit, array $connections): void
    {
        [$taxonomy, $term, $field, $operator, $value, $advanced] = self::condition_values($edit);
        $template = "{title}\n{url}";
        $connectionId = (string) ($edit['connection_id'] ?? $connections[0]['id']);
        foreach ($connections as $connection) {
            if ((string) $connection['id'] === $connectionId) {
                $template = ProviderCatalog::get($connection['provider'])['template'] ?? $template;
                break;
            }
        }
        self::form('save_recipe', (int) ($edit['id'] ?? 0), 'sr-recipe-form');
        ?>
            <input type="hidden" name="version" value="<?php echo esc_attr((string) ($edit['version'] ?? 0)); ?>">
            <div class="sr-form-body">
                <?php self::field('name', __('Recipe name', 'social-relay'), $edit['name'] ?? '', ['required' => true, 'maxlength' => 190]); ?>
                <div class="sr-form-grid">
                    <div class="sr-field">
                        <label for="sr-post-type"><?php esc_html_e('Post type', 'social-relay'); ?></label>
                        <select id="sr-post-type" name="post_type" required>
                            <?php foreach (get_post_types(['public' => true], 'objects') as $type) :
                                if ($type->name === 'attachment' || !is_post_type_viewable($type)) {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($type->name); ?>" <?php selected($edit['post_type'] ?? 'post', $type->name); ?>><?php echo esc_html($type->labels->singular_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sr-field">
                        <label for="sr-destination"><?php esc_html_e('Destination', 'social-relay'); ?></label>
                        <select id="sr-destination" name="connection_id" required data-sr-destination>
                            <?php foreach ($connections as $connection) :
                                $provider = ProviderCatalog::get($connection['provider']);
                                $label = $connection['name'] . ' · ' . ($provider['name'] ?? $connection['provider']);
                                if (!$connection['active']) {
                                    $label .= ' · ' . __('Disabled', 'social-relay');
                                }
                                ?>
                                <option value="<?php echo esc_attr($connection['id']); ?>" <?php selected($connectionId, $connection['id']); ?>
                                    data-template="<?php echo esc_attr($provider['template'] ?? "{title}\n{url}"); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sr-field">
                    <label for="sr-template"><?php esc_html_e('Message template', 'social-relay'); ?></label>
                    <textarea id="sr-template" name="template" rows="6" maxlength="5000" required dir="auto" aria-describedby="sr-template-help" data-sr-template><?php echo esc_textarea($edit['template'] ?? $template); ?></textarea>
                    <p class="sr-hint" id="sr-template-help"><?php esc_html_e('Variables are filled from the WordPress post when the job is created.', 'social-relay'); ?></p>
                    <div class="sr-tokens" role="group" hidden data-sr-tokens aria-label="<?php esc_attr_e('Insert a message variable', 'social-relay'); ?>">
                        <?php foreach (['title', 'excerpt', 'url', 'body', 'author', 'date', 'profile'] as $token) : ?>
                            <button type="button" data-sr-token="<?php echo esc_attr('{' . $token . '}'); ?>"><code><?php echo esc_html('{' . $token . '}'); ?></code></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="sr-text-link sr-use-template" hidden data-sr-use-template><?php echo self::icon('dashicons-media-text'); ?><?php esc_html_e('Use destination’s starter template', 'social-relay'); ?></button>
                </div>
                <details class="sr-disclosure" <?php if ($taxonomy !== '' || $field !== '' || $advanced !== '') : ?>open<?php endif; ?>>
                    <summary><?php esc_html_e('Filter which posts qualify', 'social-relay'); ?></summary>
                    <div class="sr-disclosure-body">
                        <p class="sr-hint"><?php esc_html_e('Both conditions must match when supplied. Leave the conditions empty to match every published item of the selected post type.', 'social-relay'); ?></p>
                        <div class="sr-form-grid">
                            <?php self::field('taxonomy', __('Taxonomy slug', 'social-relay'), $taxonomy, ['placeholder' => 'category']); ?>
                            <?php self::field('term', __('Required term slug', 'social-relay'), $term, ['placeholder' => 'news']); ?>
                            <?php self::field('field', __('Mapped field name', 'social-relay'), $field); ?>
                            <div class="sr-field">
                                <label for="sr-field-operator"><?php esc_html_e('Field condition', 'social-relay'); ?></label>
                                <select id="sr-field-operator" name="field_operator">
                                    <?php foreach (self::operators() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($operator, $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php self::field('field_value', __('Field value', 'social-relay'), $value, ['hint' => __('Leave this empty for “Has a value”.', 'social-relay')]); ?>
                        </div>
                        <details class="sr-disclosure sr-disclosure-nested" <?php if ($advanced !== '') : ?>open<?php endif; ?>>
                            <summary><?php esc_html_e('Advanced rule JSON', 'social-relay'); ?></summary>
                            <div class="sr-disclosure-body sr-field">
                                <label for="sr-rule-json"><?php esc_html_e('Rule JSON', 'social-relay'); ?></label>
                                <textarea id="sr-rule-json" name="rule_json" rows="6" dir="ltr" aria-describedby="sr-rule-json-help"><?php echo esc_textarea($advanced); ?></textarea>
                                <p id="sr-rule-json-help" class="sr-hint"><?php esc_html_e('When supplied, this rule takes precedence over the filters above. Combine conditions with all, any, or not.', 'social-relay'); ?></p>
                            </div>
                        </details>
                    </div>
                </details>
                <details class="sr-disclosure">
                    <summary><?php esc_html_e('Advanced recipe settings', 'social-relay'); ?></summary>
                    <div class="sr-disclosure-body sr-form-grid">
                        <?php self::field('profile', __('Content profile', 'social-relay'), $edit['profile'] ?? 'article', ['required' => true, 'maxlength' => 40]); ?>
                        <?php self::field('objective', __('Objective', 'social-relay'), $edit['objective'] ?? 'traffic', ['required' => true, 'maxlength' => 40]); ?>
                        <?php self::field('priority', __('Priority', 'social-relay'), (string) ($edit['priority'] ?? 0), [
                            'type' => 'number', 'min' => -1000, 'max' => 1000,
                            'hint' => __('Recipes with higher priority are evaluated first.', 'social-relay'),
                        ]); ?>
                    </div>
                </details>
                <label class="sr-check">
                    <input type="checkbox" name="enabled" value="1" <?php checked($edit['enabled'] ?? '1', '1'); ?>>
                    <span><strong><?php esc_html_e('Enable this recipe', 'social-relay'); ?></strong><small><?php esc_html_e('Evaluate new posts of the selected type on first publication.', 'social-relay'); ?></small></span>
                </label>
                <div class="sr-form-actions">
                    <?php self::button($edit ? __('Save recipe', 'social-relay') : __('Create recipe', 'social-relay'), 'sr-primary', 'dashicons-yes'); ?>
                    <a class="sr-button sr-secondary" href="<?php echo esc_url(self::url(self::SLUG)); ?>"><?php esc_html_e('Cancel', 'social-relay'); ?></a>
                </div>
            </div>
        </form>
        <?php
    }

    private static function operators(): array
    {
        return [
            'eq' => __('Equals', 'social-relay'), 'contains' => __('Contains', 'social-relay'), 'exists' => __('Has a value', 'social-relay'),
            'gt' => __('Greater than', 'social-relay'), 'gte' => __('At least', 'social-relay'),
            'lt' => __('Less than', 'social-relay'), 'lte' => __('At most', 'social-relay'),
        ];
    }

    private static function condition_values(array $edit): array
    {
        $empty = ['', '', '', 'eq', '', ''];
        if (!$edit) {
            return $empty;
        }
        $raw = $edit['rule_json'];
        $rule = json_decode($raw, true);
        if (!Rules::validate($rule)) {
            return ['', '', '', 'eq', '', $raw];
        }
        if (is_array($rule) && count($rule) === 3 && ($rule['field'] ?? '') === 'post_type' && ($rule['operator'] ?? '') === 'eq' && ($rule['value'] ?? '') === $edit['post_type']) {
            return $empty;
        }
        $fallback = ['', '', '', 'eq', '', $raw];
        if (!isset($rule['all']) || count($rule) !== 1 || !is_array($rule['all']) || count($rule['all']) < 1 || count($rule['all']) > 2) {
            return $fallback;
        }
        $taxonomy = $term = $field = $value = '';
        $operator = 'eq';
        foreach ($rule['all'] as $condition) {
            if (!is_array($condition) || count($condition) !== 3 || !is_string($condition['field'] ?? null) || !is_string($condition['value'] ?? null)) {
                return $fallback;
            }
            if ($taxonomy === '' && str_starts_with($condition['field'], 'taxonomy:') && ($condition['operator'] ?? '') === 'contains') {
                $taxonomy = substr($condition['field'], 9);
                $term = $condition['value'];
            } elseif ($field === '' && str_starts_with($condition['field'], 'field:') && isset(self::operators()[$condition['operator'] ?? ''])) {
                $field = substr($condition['field'], 6);
                $operator = $condition['operator'];
                $value = $condition['value'];
            } else {
                return $fallback;
            }
        }
        return [$taxonomy, $term, $field, $operator, $value, ''];
    }
}
