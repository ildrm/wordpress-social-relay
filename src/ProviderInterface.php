<?php
namespace SocialRelay;

interface ProviderInterface
{
    public function id(): string;
    public function capabilities(): array;
    /** Pure preview validation; must not make network calls. */
    public function preview_validate(array $connection, array $snapshot): string;
    /** Empty string means compatible; otherwise a safe error code. */
    public function validate(array $connection, array $snapshot): string;
    /** @return array{ok:bool,code?:string,retry?:bool,remote_id?:string} */
    public function send(array $connection, array $snapshot, string $idempotencyKey): array;
}
