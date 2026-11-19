<?php
namespace SocialRelay;

final class Cli
{
    /**
     * Check for missing jobs for published posts in a UTC date range.
     *
     * ## OPTIONS
     *
     * --from=<date>
     * : First UTC date, formatted YYYY-MM-DD.
     *
     * --through=<date>
     * : Last UTC date, inclusive, formatted YYYY-MM-DD.
     *
     * [--apply]
     * : Queue missing publications using the current saved posts and recipes.
     */
    public function reconcile(array $args, array $options): void
    {
        $from = self::date($options['from'] ?? '');
        $through = self::date($options['through'] ?? '');
        if (!$from || !$through || $from > $through) {
            \WP_CLI::error('Provide a valid UTC date range with --from and --through.');
        }
        $apply = isset($options['apply']);
        if ($apply && get_option('social_relay_enabled', '0') === '1') {
            \WP_CLI::error('Pause external publishing before queuing reconciliation jobs.');
        }

        global $wpdb;
        $cursor = $scanned = $total = 0;
        do {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE ID > %d AND post_status = 'publish' AND post_date_gmt >= %s AND post_date_gmt < %s ORDER BY ID ASC LIMIT 200",
                $cursor, $from->format('Y-m-d'), $through->modify('+1 day')->format('Y-m-d')
            ));
            if ($wpdb->last_error) {
                \WP_CLI::error('Could not read the source posts.');
            }
            foreach ($ids as $id) {
                $cursor = (int) $id;
                $post = get_post($cursor);
                if (!$post) {
                    continue;
                }
                ++$scanned;
                $result = Plugin::reconcile_post($post, $apply);
                if ($result === false) {
                    \WP_CLI::error('Reconciliation could not record or verify all jobs. It is safe to repeat the same date range.');
                }
                $total += $result;
            }
        } while (count($ids) === 200);
        \WP_CLI::success(sprintf('Scanned %d posts. %s: %d.', $scanned, $apply ? 'Jobs queued' : 'Missing jobs', $total));
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Re-encrypt saved destination secrets with the configured active key.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Write the re-encrypted values. Without this flag, verify read access only.
     *
     * @subcommand rotate-secrets
     */
    public function rotate_secrets(array $args, array $options): void
    {
        try {
            $active = Security::active_key_id();
            if ($active === 'wp') {
                \WP_CLI::error('Configure an independent active key before rotating secrets.');
            }
            Security::encrypt('test');
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }
        global $wpdb;
        $table = Database::table('connections');
        $apply = isset($options['apply']);
        $cursor = $total = $failed = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, secret FROM $table WHERE id > %d ORDER BY id ASC LIMIT 200", $cursor), ARRAY_A);
            if ($wpdb->last_error) {
                \WP_CLI::error('Could not read the destinations.');
            }
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                try {
                    $secret = Security::decrypt($row['secret']);
                    if (str_starts_with($row['secret'], $active . ':')) {
                        continue;
                    }
                    if ($apply && $wpdb->query($wpdb->prepare(
                        "UPDATE $table SET secret = %s WHERE id = %d AND BINARY secret = %s",
                        Security::encrypt($secret), $cursor, $row['secret']
                    )) !== 1) {
                        throw new \RuntimeException('Could not update the saved secret.');
                    }
                    ++$total;
                } catch (\RuntimeException $e) {
                    ++$failed;
                    \WP_CLI::warning(sprintf('Could not %s destination #%d.', $apply ? 'rotate' : 'verify', $cursor));
                }
            }
        } while (count($rows) === 200);
        if ($failed) {
            \WP_CLI::error(sprintf('%d readable or rotated. %d destinations still need attention.', $total, $failed));
        }
        \WP_CLI::success(sprintf('%d destination secrets %s.', $total, $apply ? 'rotated' : 'ready to rotate'));
    }
}
