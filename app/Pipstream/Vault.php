<?php

namespace App\Pipstream;

use RuntimeException;

/**
 * Seal secrets to an engine worker's PUBLIC key (libsodium sealed box). Laravel can write
 * them but never read them back: only the worker holding the private key can open the box.
 * Format matches the engine's pipstream.common.vault: "sb1:<base64>" of JSON.
 */
final class Vault
{
    public static function sealForExecutor(array $value): string
    {
        return self::seal($value, (string) config('pipstream.executor_public_key'));
    }

    public static function seal(mixed $value, string $publicKeyB64): string
    {
        $pk = base64_decode($publicKeyB64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException('PipStream public key is missing or invalid (PS_EXECUTOR_PUBLIC_KEY)');
        }
        $ct = sodium_crypto_box_seal(json_encode($value, JSON_UNESCAPED_SLASHES), $pk);

        return 'sb1:'.base64_encode($ct);
    }
}
