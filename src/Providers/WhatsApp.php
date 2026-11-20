<?php
namespace SocialRelay\Providers;

use SocialRelay\ConnectionConfig;
use SocialRelay\ProviderInterface;
use SocialRelay\Text;

final class WhatsApp implements ProviderInterface
{
    public function id(): string
    {
        return 'whatsapp';
    }

    public function capabilities(): array
    {
        return ['supported' => true, 'manual' => true, 'surface' => 'channel', 'text' => true,
            'link' => true, 'image' => false, 'video' => false, 'max_characters' => 4096, 'idempotency_key' => false];
    }

    public function preview_validate(array $connection, array $snapshot): string
    {
        if (!ConnectionConfig::valid_surface($this->id(), $connection['surface'] ?? '')) {
            return 'connection';
        }
        return Text::length($snapshot['text'] ?? '') > 4096 ? 'message_too_long' : '';
    }

    public function validate(array $connection, array $snapshot): string
    {
        return $this->preview_validate($connection, $snapshot);
    }

    public function send(array $connection, array $snapshot, string $idempotencyKey): array
    {
        return ['ok' => false, 'code' => 'manual_required', 'retry' => false];
    }
}
