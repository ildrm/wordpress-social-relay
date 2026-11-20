<?php
namespace SocialRelay\Admin;

use SocialRelay\Database;
use SocialRelay\Plugin;
use SocialRelay\ProviderCatalog;
use SocialRelay\Security;

final class Publications extends Page
{
    private const SLUG = 'social-relay-jobs';
    private const PER_PAGE = 25;

    public static function render(): void
    {
        self::start(self::SLUG, __('Publications', 'social-relay'), __('Follow each WordPress post from the queue to its destination.', 'social-relay'));
        global $wpdb;
        $jobs = Database::table('jobs');
        $connections = Database::table('connections');
        $job = absint(self::query('job'));
        $status = sanitize_key(self::query('status'));
        $destination = absint(self::query('destination'));
        $requestedPage = max(1, absint(self::query('paged')));
        $options = [
            '' => __('All statuses', 'social-relay'), 'pending' => __('In queue', 'social-relay'), 'attention' => __('Needs attention', 'social-relay'),
        ];
        foreach (self::statuses() as $key => [$label]) {
            $options[$key] = $label;
        }
        $status = isset($options[$status]) ? $status : '';
        $context = array_filter(['status' => $status, 'destination' => $destination, 'paged' => $requestedPage > 1 ? $requestedPage : 0]);
        if ($job) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT j.*, c.name AS destination,c.surface,c.provider,c.active FROM $jobs j LEFT JOIN $connections c ON c.id = j.connection_id WHERE j.id = %d", $job), ARRAY_A);
            self::detail($row ?: [], $job, self::url(self::SLUG, $context));
            self::end();
            return;
        }

        $where = [];
        $values = [];
        if ($status === 'pending') {
            $where[] = "j.status IN ('queued','retry_wait','publishing')";
        } elseif ($status === 'attention') {
            $where[] = "j.status IN ('awaiting_manual','delivery_unknown','blocked','permanent_failure')";
        } elseif ($status !== '') {
            $where[] = 'j.status = %s';
            $values[] = $status;
        }
        if ($destination) {
            $where[] = 'j.connection_id = %d';
            $values[] = $destination;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countSql = "SELECT COUNT(*) FROM $jobs j" . $whereSql;
        $total = (int) $wpdb->get_var($values ? $wpdb->prepare($countSql, ...$values) : $countSql);
        $page = min($requestedPage, max(1, (int) ceil($total / self::PER_PAGE)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT j.id,j.post_id,j.status,j.attempts,j.error_code,j.updated_at,c.name AS destination,c.provider FROM $jobs j LEFT JOIN $connections c ON c.id = j.connection_id $whereSql ORDER BY j.id DESC LIMIT %d OFFSET %d",
            ...[...$values, self::PER_PAGE, ($page - 1) * self::PER_PAGE]
        ), ARRAY_A);
        $destinations = $wpdb->get_results("SELECT id,name FROM $connections ORDER BY name ASC, id ASC", ARRAY_A);
        self::listing($rows ?: [], $options, $destinations ?: [], $total, $page, $status, $destination);
        self::end();
    }

    private static function listing(array $rows, array $options, array $destinations, int $total, int $page, string $status, int $destination): void
    {
        $context = array_filter(['status' => $status, 'destination' => $destination, 'paged' => $page > 1 ? $page : 0]);
        ?>
        <section class="sr-card">
            <div class="sr-card-head">
                <div><h2><?php esc_html_e('Publication history', 'social-relay'); ?></h2><p><?php echo esc_html(sprintf(_n('%s publication', '%s publications', $total, 'social-relay'), number_format_i18n($total))); ?></p></div>
                <?php if (Security::can_send()) : ?>
                    <?php self::form('run_worker'); ?>
                    <?php self::button(__('Process due jobs', 'social-relay'), 'sr-secondary', 'dashicons-update'); ?>
                    </form>
                <?php else : ?>
                    <a class="sr-text-link" href="<?php echo esc_url(self::url('social-relay', ['settings' => '1']) . '#sr-publishing-settings'); ?>"><?php echo self::badge(__('Publishing paused', 'social-relay'), 'warning'); ?></a>
                <?php endif; ?>
            </div>
            <form class="sr-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" aria-label="<?php esc_attr_e('Filter publications', 'social-relay'); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <div class="sr-field">
                    <label for="sr-job-status"><?php esc_html_e('Status', 'social-relay'); ?></label>
                    <select id="sr-job-status" name="status">
                        <?php foreach ($options as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sr-field">
                    <label for="sr-job-destination"><?php esc_html_e('Destination', 'social-relay'); ?></label>
                    <select id="sr-job-destination" name="destination">
                        <option value="0"><?php esc_html_e('All destinations', 'social-relay'); ?></option>
                        <?php foreach ($destinations as $connection) : ?>
                            <option value="<?php echo esc_attr($connection['id']); ?>" <?php selected($destination, $connection['id']); ?>><?php echo esc_html($connection['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php self::button(__('Apply filters', 'social-relay'), 'sr-secondary', 'dashicons-filter'); ?>
                <?php if ($status !== '' || $destination) : ?>
                    <a class="sr-text-link" href="<?php echo esc_url(self::url(self::SLUG)); ?>"><?php esc_html_e('Clear filters', 'social-relay'); ?></a>
                <?php endif; ?>
            </form>
            <?php if (!$rows) :
                if ($status !== '' || $destination) {
                    self::empty_state('dashicons-filter', __('No matching publications', 'social-relay'), __('Try a different status or destination.', 'social-relay'));
                } else {
                    self::empty_state('dashicons-list-view', __('No publications yet', 'social-relay'), __('The first publication for an enabled recipe will appear here.', 'social-relay'));
                }
            else : ?>
                <div class="sr-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Publication history table', 'social-relay'); ?>">
                    <table class="sr-table">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e('WordPress post', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Destination', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Attempts', 'social-relay'); ?></th>
                            <th scope="col"><?php esc_html_e('Last updated', 'social-relay'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row) :
                            $provider = ProviderCatalog::get($row['provider'] ?? '');
                            ?>
                            <tr>
                                <th scope="row">
                                    <a class="sr-table-link" href="<?php echo esc_url(self::url(self::SLUG, ['job' => $row['id']] + $context) . '#sr-job-' . $row['id']); ?>"><?php echo esc_html(self::post_title((int) $row['post_id'])); ?></a>
                                    <small><?php echo esc_html(sprintf(__('Job #%s', 'social-relay'), $row['id'])); ?></small>
                                </th>
                                <td>
                                    <strong><?php echo esc_html($row['destination'] ?: __('Unavailable destination', 'social-relay')); ?></strong>
                                    <?php if ($provider) : ?><small><?php echo esc_html($provider['name']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo self::status($row['status']); ?></td>
                                <td><?php echo esc_html($row['attempts']); ?></td>
                                <td><?php echo self::datetime($row['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php self::pages(self::SLUG, $page, $total, self::PER_PAGE, ['status' => $status, 'destination' => $destination]); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function detail(array $row, int $id, string $back): void
    {
        ?>
        <div class="sr-back"><a href="<?php echo esc_url($back); ?>"><?php echo self::icon('dashicons-arrow-left-alt2'); ?><?php esc_html_e('Back to publications', 'social-relay'); ?></a></div>
        <section class="sr-card" id="<?php echo esc_attr('sr-job-' . $id); ?>">
        <?php if (!$row) :
            self::empty_state('dashicons-search', __('Publication not found', 'social-relay'), __('The requested job is unavailable.', 'social-relay'));
        else :
            $post = get_post((int) $row['post_id']);
            $provider = ProviderCatalog::get($row['provider'] ?? '');
            $snapshot = json_decode($row['snapshot'], true);
            ?>
            <div class="sr-card-head">
                <div><h2><?php echo esc_html(self::post_title((int) $row['post_id'])); ?></h2><p><?php echo esc_html(sprintf(__('Publication #%s', 'social-relay'), $row['id'])); ?></p></div>
                <?php echo self::status($row['status']); ?>
            </div>
            <div class="sr-form-body">
                <dl class="sr-detail-grid">
                    <div><dt><?php esc_html_e('Destination', 'social-relay'); ?></dt><dd><?php echo esc_html($row['destination'] ?: __('Unavailable destination', 'social-relay')); ?></dd><?php if ($provider) : ?><dd><?php echo esc_html($provider['name']); ?></dd><?php endif; ?></div>
                    <div><dt><?php esc_html_e('Attempts', 'social-relay'); ?></dt><dd><?php echo esc_html($row['attempts']); ?></dd></div>
                    <div><dt><?php esc_html_e('Last updated', 'social-relay'); ?></dt><dd><?php echo self::datetime($row['updated_at']); ?></dd></div>
                    <?php if (in_array($row['status'], ['queued', 'retry_wait'], true)) : ?>
                        <div><dt><?php esc_html_e('Eligible to run after', 'social-relay'); ?></dt><dd><?php echo self::datetime($row['run_at']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($row['remote_id'] !== '') : ?>
                        <div><dt><?php esc_html_e('Remote ID', 'social-relay'); ?></dt><dd><code><bdi><?php echo esc_html($row['remote_id']); ?></bdi></code></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if ($row['error_code'] !== '') : ?>
                    <div class="sr-notice sr-notice-error" role="status"><p><?php echo esc_html(Security::safe_error($row['error_code'])); ?></p></div>
                <?php endif; ?>
                <?php if ($row['status'] === 'awaiting_manual') :
                    self::manual($row, $post, $provider, is_array($snapshot) ? $snapshot : []);
                else :
                    if ($post && Plugin::can_publish($post) && is_string($snapshot['text'] ?? null)) : ?>
                        <details class="sr-disclosure"><summary><?php esc_html_e('View queued message', 'social-relay'); ?></summary>
                            <div class="sr-disclosure-body"><pre class="sr-message" dir="auto"><?php echo esc_html($snapshot['text']); ?></pre></div>
                        </details>
                    <?php endif;
                    self::operations($row);
                endif; ?>
                <?php $edit = get_edit_post_link((int) $row['post_id'], 'raw'); ?>
                <?php if ($edit) : ?>
                    <a class="sr-text-link" href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Open WordPress post', 'social-relay'); ?><?php echo self::icon('dashicons-arrow-right-alt2'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </section>
        <?php
    }

    private static function manual(array $row, ?\WP_Post $post, ?array $provider, array $snapshot): void
    {
        $id = (int) $row['id'];
        $channel = (string) ($row['surface'] ?? '');
        $manualReady = $post && Plugin::can_publish($post) && !empty($row['active']) && !empty($provider['manual'])
            && preg_match('~^https://(?:www\.)?whatsapp\.com/channel/[A-Za-z0-9_-]+/?$~D', $channel) && is_string($snapshot['text'] ?? null);
        ?>
        <div class="sr-manual">
            <h3><?php esc_html_e('Publish to WhatsApp Channel', 'social-relay'); ?></h3>
            <?php if ($manualReady) : ?>
                <p><?php esc_html_e('Copy the message, open the channel as an administrator, and publish the update. Return here to record that it was published.', 'social-relay'); ?></p>
                <div class="sr-field">
                    <label for="sr-manual-text"><?php esc_html_e('Prepared message', 'social-relay'); ?></label>
                    <textarea id="sr-manual-text" rows="6" readonly dir="auto"><?php echo esc_textarea($snapshot['text']); ?></textarea>
                </div>
                <div class="sr-actions">
                    <button type="button" class="sr-button sr-secondary" hidden data-sr-copy="sr-manual-text" data-copy-success="<?php esc_attr_e('Message copied.', 'social-relay'); ?>" data-copy-failure="<?php esc_attr_e('Select the message and copy it manually.', 'social-relay'); ?>"><?php echo self::icon('dashicons-admin-page'); ?><?php esc_html_e('Copy message', 'social-relay'); ?></button>
                    <a class="sr-button sr-primary" href="<?php echo esc_url($channel); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open channel', 'social-relay'); ?><?php echo self::icon('dashicons-external'); ?><span class="screen-reader-text"><?php esc_html_e(' (opens in a new tab)', 'social-relay'); ?></span></a>
                </div>
                <p class="sr-hint" aria-live="polite" data-sr-copy-status></p>
                <?php self::form('confirm_manual', $id, 'sr-operation'); ?>
                <?php self::field('remote_id', __('Published update link or ID (optional)', 'social-relay'), '', ['id' => 'sr-manual-remote', 'maxlength' => 190, 'dir' => 'auto']); ?>
                <?php self::button(__('Mark as published', 'social-relay'), 'sr-secondary', 'dashicons-yes'); ?>
                </form>
            <?php else : ?>
                <p><?php esc_html_e('The post or destination is unavailable for manual publication.', 'social-relay'); ?></p>
            <?php endif; ?>
            <?php self::form('cancel_job', $id, 'sr-operation'); ?>
            <?php self::button(__('Cancel job', 'social-relay'), 'sr-danger'); ?>
            </form>
        </div>
        <?php
    }

    private static function operations(array $row): void
    {
        $id = (int) $row['id'];
        if ($row['status'] === 'delivery_unknown') {
            echo '<div class="sr-actions sr-operation">';
            self::form('confirm_delivery', $id);
            self::field('remote_id', __('Remote ID (optional)', 'social-relay'), '', ['maxlength' => 190]);
            self::button(__('Record as delivered', 'social-relay'));
            echo '</form>';
            self::form('retry_unknown', $id);
            self::button(__('Retry after checking destination', 'social-relay'));
            echo '</form></div>';
        } elseif (in_array($row['status'], ['permanent_failure', 'blocked'], true)) {
            self::form('retry_job', $id, 'sr-operation');
            self::button(__('Retry publication', 'social-relay'), 'sr-secondary', 'dashicons-update');
            echo '</form>';
        } elseif (in_array($row['status'], ['queued', 'retry_wait'], true)) {
            self::form('cancel_job', $id, 'sr-operation');
            self::button(__('Cancel job', 'social-relay'), 'sr-danger');
            echo '</form>';
        }
    }

    private static function post_title(int $id): string
    {
        $title = current_user_can('edit_post', $id) ? get_the_title($id) : '';
        return $title ?: sprintf(__('Post #%s', 'social-relay'), $id);
    }

    private static function datetime(string $value): string
    {
        $timestamp = strtotime($value . ' UTC');
        if (!$timestamp) {
            return esc_html__('Unavailable', 'social-relay');
        }
        $display = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        return '<time datetime="' . esc_attr(gmdate('c', $timestamp)) . '">' . esc_html($display) . '</time>';
    }
}
