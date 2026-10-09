<?php

namespace App\Support;

class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';

        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        return self::matchingCounter($secret, $code, $time) !== null;
    }

    public static function matchingCounter(string $secret, string $code, ?int $time = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $secretBytes = self::decode($secret);
        $counter = intdiv($time ?? time(), 30);

        for ($offset = -1; $offset <= 1; $offset++) {
            $matchedCounter = $counter + $offset;
            $binaryCounter = pack('N*', 0, $matchedCounter);
            $hash = hash_hmac('sha1', $binaryCounter, $secretBytes, true);
            $position = ord($hash[19]) & 0x0F;
            $number = unpack('N', substr($hash, $position, 4))[1] & 0x7FFFFFFF;
            $expected = str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return $matchedCounter;
            }
        }

        return null;
    }

    public static function provisioningUri(string $secret, string $email): string
    {
        $label = rawurlencode('Quick PayMoney:'.$email);

        return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer=Quick%20PayMoney&digits=6&period=30';
    }

    private static function decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::BASE32, $character);

            if ($index === false) {
                throw new \InvalidArgumentException('Invalid authenticator secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
