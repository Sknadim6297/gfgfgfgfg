<?php

namespace App\Support;

final class Decimal
{
    public static function toScaledInteger(string $value, int $scale): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $scaled = ltrim(($whole === '' ? '0' : $whole).$fraction, '0');

        return $scaled === '' ? '0' : $scaled;
    }

    public static function compare(string $left, string $right, int $scale): int
    {
        $leftInteger = self::toScaledInteger($left, $scale);
        $rightInteger = self::toScaledInteger($right, $scale);

        return strlen($leftInteger) <=> strlen($rightInteger)
            ?: strcmp($leftInteger, $rightInteger) <=> 0;
    }

    public static function add(string $left, string $right, int $scale): string
    {
        return self::fromScaledInteger(
            self::addIntegers(
                self::toScaledInteger($left, $scale),
                self::toScaledInteger($right, $scale),
            ),
            $scale,
        );
    }

    public static function subtract(string $left, string $right, int $scale): string
    {
        $leftInteger = self::toScaledInteger($left, $scale);
        $rightInteger = self::toScaledInteger($right, $scale);
        if (self::compare($left, $right, $scale) < 0) {
            throw new \InvalidArgumentException('The decimal result cannot be negative.');
        }

        return self::fromScaledInteger(self::subtractIntegers($leftInteger, $rightInteger), $scale);
    }

    public static function divideToScale(
        string $dividend,
        string $divisor,
        int $dividendScale,
        int $divisorScale,
        int $resultScale,
    ): string {
        $dividendInteger = self::toScaledInteger($dividend, $dividendScale);
        $divisorInteger = self::toScaledInteger($divisor, $divisorScale);

        if ($divisorInteger === '0') {
            throw new \InvalidArgumentException('Division by zero is not allowed.');
        }

        $shift = $divisorScale + $resultScale - $dividendScale;
        if ($shift >= 0) {
            $dividendInteger .= str_repeat('0', $shift);
        } else {
            $divisorInteger .= str_repeat('0', -$shift);
        }

        [$quotient, $remainder] = self::divideIntegers($dividendInteger, $divisorInteger);
        if (self::compareIntegers(self::addIntegers($remainder, $remainder), $divisorInteger) >= 0) {
            $quotient = self::increment($quotient);
        }

        return self::fromScaledInteger($quotient, $resultScale);
    }

    public static function multiplyToCents(string $amount, string $rate): string
    {
        $product = self::multiply(
            self::toScaledInteger($amount, 8),
            self::toScaledInteger($rate, 8),
        );
        $padded = str_pad($product, 15, '0', STR_PAD_LEFT);
        $cents = substr($padded, 0, -14);
        // INR amounts use half-up rounding to the nearest paise.
        if ((int) $padded[-14] >= 5) {
            $cents = self::increment($cents);
        }
        $cents = str_pad(ltrim($cents, '0'), 3, '0', STR_PAD_LEFT);

        return substr($cents, 0, -2).'.'.substr($cents, -2);
    }

    private static function multiply(string $left, string $right): string
    {
        $leftDigits = array_reverse(array_map('intval', str_split($left)));
        $rightDigits = array_reverse(array_map('intval', str_split($right)));
        $result = array_fill(0, count($leftDigits) + count($rightDigits), 0);

        foreach ($leftDigits as $leftIndex => $leftDigit) {
            foreach ($rightDigits as $rightIndex => $rightDigit) {
                $result[$leftIndex + $rightIndex] += $leftDigit * $rightDigit;
            }
        }

        for ($index = 0, $length = count($result) - 1; $index < $length; $index++) {
            $result[$index + 1] += intdiv($result[$index], 10);
            $result[$index] %= 10;
        }

        $value = implode('', array_reverse($result));
        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }

    private static function addIntegers(string $left, string $right): string
    {
        $leftDigits = array_reverse(array_map('intval', str_split($left)));
        $rightDigits = array_reverse(array_map('intval', str_split($right)));
        $length = max(count($leftDigits), count($rightDigits));
        $result = [];
        $carry = 0;

        for ($index = 0; $index < $length; $index++) {
            $sum = ($leftDigits[$index] ?? 0) + ($rightDigits[$index] ?? 0) + $carry;
            $result[] = $sum % 10;
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $result[] = $carry;
        }

        return implode('', array_reverse($result));
    }

    private static function subtractIntegers(string $left, string $right): string
    {
        $leftDigits = array_reverse(array_map('intval', str_split($left)));
        $rightDigits = array_reverse(array_map('intval', str_split($right)));
        $result = [];
        $borrow = 0;

        foreach ($leftDigits as $index => $digit) {
            $difference = $digit - ($rightDigits[$index] ?? 0) - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $result[] = $difference < 0 ? $difference + 10 : $difference;
        }

        $value = ltrim(implode('', array_reverse($result)), '0');

        return $value === '' ? '0' : $value;
    }

    private static function divideIntegers(string $dividend, string $divisor): array
    {
        $quotient = '';
        $remainder = '0';

        foreach (str_split($dividend) as $digit) {
            $remainder = ltrim(($remainder === '0' ? '' : $remainder).$digit, '0') ?: '0';
            $quotientDigit = 0;
            while (self::compareIntegers($remainder, $divisor) >= 0) {
                $remainder = self::subtractIntegers($remainder, $divisor);
                $quotientDigit++;
            }
            $quotient .= (string) $quotientDigit;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    private static function compareIntegers(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right) <=> 0;
    }

    private static function fromScaledInteger(string $value, int $scale): string
    {
        $value = str_pad($value, $scale + 1, '0', STR_PAD_LEFT);
        if ($scale === 0) {
            return ltrim($value, '0') ?: '0';
        }

        return substr($value, 0, -$scale).'.'.substr($value, -$scale);
    }

    private static function increment(string $value): string
    {
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }

            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }
}
