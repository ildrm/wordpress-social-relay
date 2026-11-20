<?php
namespace SocialRelay\Admin;

final class FieldMapping extends Page
{
    private const SLUG = 'social-relay-mapping';

    public static function render(): void
    {
        self::start(self::SLUG, __('Field mapping', 'social-relay'), __('Use values from WordPress custom fields in recipes and messages.', 'social-relay'));
        $mapping = get_option('social_relay_field_mapping', []);
        $mapping = is_array($mapping) ? $mapping : [];
        $types = get_post_types(['public' => true], 'objects');
        ?>
        <div class="sr-editor-grid">
            <section class="sr-card sr-form-card">
                <div class="sr-card-head"><h2><?php esc_html_e('Mapped fields', 'social-relay'); ?></h2></div>
                <?php if (!$mapping) :
                    self::empty_state('dashicons-editor-code', __('No fields mapped yet', 'social-relay'), __('Add a mapping to make a custom field available in a recipe.', 'social-relay'));
                else : ?>
                    <div class="sr-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Mapped fields table', 'social-relay'); ?>">
                        <table class="sr-table">
                            <thead><tr>
                                <th scope="col"><?php esc_html_e('Template variable', 'social-relay'); ?></th>
                                <th scope="col"><?php esc_html_e('Post type', 'social-relay'); ?></th>
                                <th scope="col"><?php esc_html_e('Meta key', 'social-relay'); ?></th>
                                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'social-relay'); ?></span></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($mapping as $postType => $fields) : ?>
                                    <?php foreach ((array) $fields as $target => $source) : ?>
                                        <tr>
                                            <th scope="row"><code><bdi><?php echo esc_html('{' . $target . '}'); ?></bdi></code></th>
                                            <td><?php echo esc_html($types[$postType]->labels->singular_name ?? $postType); ?></td>
                                            <td><code><bdi><?php echo esc_html($source); ?></bdi></code></td>
                                            <td>
                                                <?php self::form('remove_mapping'); ?>
                                                <input type="hidden" name="post_type" value="<?php echo esc_attr($postType); ?>">
                                                <input type="hidden" name="target" value="<?php echo esc_attr($target); ?>">
                                                <button class="sr-button sr-danger" type="submit"><?php esc_html_e('Remove', 'social-relay'); ?><span class="screen-reader-text"> <?php echo esc_html($target); ?></span></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <section class="sr-card sr-form-card">
                <div class="sr-card-head"><h2><?php esc_html_e('Add a mapping', 'social-relay'); ?></h2></div>
                <?php self::form('save_mapping'); ?>
                <div class="sr-form-body">
                    <div class="sr-field">
                        <label for="sr-map-type"><?php esc_html_e('Post type', 'social-relay'); ?></label>
                        <select name="post_type" id="sr-map-type" required>
                            <?php foreach ($types as $type) :
                                if ($type->name === 'attachment' || !is_post_type_viewable($type)) {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($type->name); ?>"><?php echo esc_html($type->labels->singular_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php self::field('target', __('Template variable name', 'social-relay'), '', [
                        'required' => true, 'maxlength' => 40, 'dir' => 'ltr', 'placeholder' => 'subtitle',
                        'hint' => __('Start with a lowercase letter. Use lowercase letters, numbers, or underscores. Saving the same name for this post type replaces its mapping.', 'social-relay'),
                    ]); ?>
                    <?php self::field('source', __('WordPress meta key or ACF field name', 'social-relay'), '', [
                        'required' => true, 'maxlength' => 100, 'dir' => 'ltr', 'placeholder' => 'article_subtitle',
                    ]); ?>
                    <div class="sr-form-actions"><?php self::button(__('Save mapping', 'social-relay'), 'sr-primary', 'dashicons-yes'); ?></div>
                </div>
                </form>
            </section>
        </div>
        <?php self::end();
    }
}
