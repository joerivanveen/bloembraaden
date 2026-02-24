<?php

declare( strict_types=1 );

namespace Bloembraaden;

class Crypt
{
    public static function getKey(): string
    {
        $key = Setup::$HASHKEY;

        while (strlen($key) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $key .= $key;
        }

        return substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Encrypt a message
     *
     * @param string $message - message to encrypt
     *
     * @return string
     * @throws \RangeException|\SodiumException|\Exception
     */
    public static function encrypt(string $message): string
    {
        $key = self::getKey();

        if (mb_strlen($key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RangeException(sprintf('Key is not the correct size (must be %s bytes).', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $cipher = base64_encode(
            $nonce .
            sodium_crypto_secretbox(
                $message,
                $nonce,
                $key
            )
        );
        sodium_memzero($message);
        sodium_memzero($key);
        return $cipher;
    }

    /**
     * Decrypt a message
     *
     * @param string $encrypted - message encrypted with encrypt()
     * @return string
     * @throws \Exception
     */
    public static function decrypt(string $encrypted): string
    {
        $key = self::getKey();

        $decoded = base64_decode($encrypted);
        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        $plain = sodium_crypto_secretbox_open(
            $ciphertext,
            $nonce,
            $key
        );
        if (false === is_string($plain)) {
            throw new \Exception('Invalid MAC');
        }
        sodium_memzero($ciphertext);
        sodium_memzero($key);
        return $plain;
    }
}
