<?php
namespace SocialRelay;

final class Publisher
{
    /** @return array<string,ProviderInterface> */
    private static function providers(): array
    {
        $providers = [
            'webhook' => new Providers\Webhook(),
            'telegram' => new Providers\Telegram(),
        ];
        if (function_exists('apply_filters')) {
            $providers = apply_filters('social_relay_providers', $providers);
        }
        return is_array($providers) ? $providers : [];
    }

    public static function capabilities(string $provider): array
    {
        $adapter = self::providers()[$provider] ?? null;
        return $adapter instanceof ProviderInterface ? $adapter->capabilities() : ['supported' => false];
    }

    public static function compatible(array $connection, array $snapshot): array
    {
        $adapter = self::providers()[$connection['provider']] ?? null;
        if (!$adapter instanceof ProviderInterface) {
            return ['compatible' => false, 'reason' => 'unsupported'];
        }
        $reason = $adapter->preview_validate($connection, $snapshot);
        return ['compatible' => $reason === '', 'reason' => $reason];
    }

    public static function send(array $connection, array $snapshot, string $idempotencyKey): array
    {
        $adapter = self::providers()[$connection['provider']] ?? null;
        if (!$adapter instanceof ProviderInterface) {
            return ['ok' => false, 'code' => 'rejected', 'retry' => false];
        }
        $reason = $adapter->validate($connection, $snapshot);
        if ($reason !== '') {
            return ['ok' => false, 'code' => $reason, 'retry' => false];
        }
        return $adapter->send($connection, $snapshot, $idempotencyKey);
    }
}
