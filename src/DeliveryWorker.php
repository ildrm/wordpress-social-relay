<?php
namespace SocialRelay;

final class DeliveryWorker
{
    public static function run(): void
    {
        if (!Database::ready() || !Security::can_send()) {
            return;
        }
        global $wpdb;
        $table = Database::table('jobs');
        $now = current_time('mysql', true);
        // Preflight jobs have never sent. An idempotent receiver can safely accept the same key again.
        $recovered = $wpdb->query($wpdb->prepare(
            "UPDATE $table
             SET status = IF(dispatch_mode IN ('preflight', 'idempotent'), 'retry_wait', 'delivery_unknown'),
                 error_code = IF(dispatch_mode IN ('preflight', 'idempotent'), '', 'delivery_unknown'),
                 run_at = %s, updated_at = %s, lock_token = '', locked_until = NULL
             WHERE status = 'publishing' AND locked_until <= %s LIMIT 10",
            $now, $now, $now
        ));
        if ($recovered === false) {
            Database::storage_error('recover_leases');
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status IN ('queued','retry_wait') AND run_at <= %s ORDER BY run_at ASC, id ASC LIMIT 10", $now));
        if ($wpdb->last_error) {
            Database::storage_error('read_jobs');
            return;
        }
        foreach ($ids as $id) {
            if (!Security::can_send()) {
                break;
            }
            $token = bin2hex(random_bytes(16));
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'publishing', dispatch_mode = 'preflight', lock_token = %s, locked_until = %s, updated_at = %s WHERE id = %d AND status IN ('queued','retry_wait') AND run_at <= %s", $token, gmdate('Y-m-d H:i:s', time() + 300), current_time('mysql', true), $id, $now));
            if ($claimed === false) {
                Database::storage_error('claim_job');
                break;
            }
            if ($claimed !== 1) {
                continue;
            }
            if (!self::process((int) $id, $token)) {
                break;
            }
        }
    }

    private static function process(int $id, string $token): bool
    {
        global $wpdb;
        $jobs = Database::table('jobs');
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id = %d AND lock_token = %s", $id, $token), ARRAY_A);
        if (!$job) {
            if ($wpdb->last_error) {
                Database::storage_error('read_job');
            }
            return true;
        }
        if (!Security::can_send()) {
            self::finish($job, $token, 'retry_wait', '', '');
            return true;
        }
        $post = get_post((int) $job['post_id']);
        if (!$post || !Plugin::can_publish($post)) {
            self::finish($job, $token, 'cancelled', '', '');
            return true;
        }
        $connection = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('connections') . ' WHERE id = %d AND active = 1', $job['connection_id']), ARRAY_A);
        if (!$connection) {
            if ($wpdb->last_error) {
                Database::storage_error('read_connection');
                return false;
            }
            self::finish($job, $token, 'blocked', '', 'connection');
            return true;
        }
        $snapshot = json_decode($job['snapshot'], true);
        if (!is_array($snapshot)) {
            self::finish($job, $token, 'permanent_failure', '', 'rejected');
            return true;
        }
        if (!Database::reserve_delivery()) {
            self::record($job, $token, ['status' => 'retry_wait', 'run_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
            return false;
        }
        $mode = !empty(Publisher::capabilities($connection['provider'])['idempotency_key']) ? 'idempotent' : 'single';
        $dispatch = $wpdb->query($wpdb->prepare(
            "UPDATE $jobs SET dispatch_mode = %s WHERE id = %d AND lock_token = %s AND locked_until > %s",
            $mode, $id, $token, current_time('mysql', true)
        ));
        if ($dispatch !== 1) {
            if ($dispatch === false) {
                Database::storage_error('start_delivery');
            }
            return false;
        }
        $result = Publisher::send($connection, $snapshot, $job['idem_key']);
        if ($result['ok']) {
            self::finish($job, $token, 'published', $result['remote_id'], '');
            return true;
        }
        $attempts = (int) $job['attempts'] + 1;
        if (!empty($result['unknown'])) {
            self::finish($job, $token, 'delivery_unknown', '', 'delivery_unknown', $attempts);
            return true;
        }
        if ($result['retry'] && $attempts < 5) {
            $delay = min(3600, (int) (60 * (2 ** ($attempts - 1)))) + random_int(0, 30);
            if (self::record($job, $token, [
                'status' => 'retry_wait', 'attempts' => $attempts,
                'run_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'error_code' => $result['code'],
            ])) {
                Database::audit('job_retry', 'job', $id);
            }
            return true;
        }
        self::finish($job, $token, 'permanent_failure', '', $result['code'], $attempts);
        return true;
    }

    private static function finish(array $job, string $token, string $status, string $remoteId, string $error, ?int $attempts = null): void
    {
        $data = ['status' => $status, 'remote_id' => $remoteId, 'error_code' => $error];
        if ($attempts !== null) {
            $data['attempts'] = $attempts;
        } elseif ($status === 'published') {
            $data['attempts'] = (int) $job['attempts'] + 1;
        }
        if (self::record($job, $token, $data)) {
            Database::audit('job_' . $status, 'job', (int) $job['id']);
        }
    }

    private static function record(array $job, string $token, array $data): bool
    {
        global $wpdb;
        $data += ['lock_token' => '', 'locked_until' => null, 'dispatch_mode' => '', 'updated_at' => current_time('mysql', true)];
        $updated = $wpdb->update(Database::table('jobs'), $data, ['id' => $job['id'], 'status' => 'publishing', 'lock_token' => $token]);
        if ($updated === false) {
            Database::storage_error('write_job_result');
        }
        return $updated === 1;
    }
}
