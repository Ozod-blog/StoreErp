<?php

namespace App\Support;

/**
 * EAN-13 yordamchilari. Ichki (do'kon ichida ishlatiladigan) kodlar "2" prefiksi bilan boshlanadi:
 * GS1 bu diapazonni (20–29) do'kon ichki foydalanishi uchun ajratgan, shuning uchun
 * tashqi ishlab chiqaruvchilarning haqiqiy kodlari bilan to'qnashmaydi.
 */
final class Barcode
{
    public const INTERNAL_PREFIX = '2';

    /** 12 xonali asosdan nazorat raqamini (13-xona) hisoblaydi. */
    public static function ean13Check(string $base12): int
    {
        if (! preg_match('/^\d{12}$/', $base12)) {
            throw new \InvalidArgumentException('EAN-13 asosi 12 ta raqam bo‘lishi kerak.');
        }
        $sum = 0;
        foreach (str_split($base12) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code)
            && self::ean13Check(substr($code, 0, 12)) === (int) $code[12];
    }

    /** Mahsulot ID si bo'yicha deterministik ichki kod: 2 + 11 xonali ID + nazorat raqami. */
    public static function internalFromId(int $id): string
    {
        $base = self::INTERNAL_PREFIX.str_pad((string) $id, 11, '0', STR_PAD_LEFT);

        return $base.self::ean13Check($base);
    }

    /** ID asosidagi kod band bo'lganda ishlatiladigan tasodifiy ichki kod. */
    public static function internalRandom(): string
    {
        $base = self::INTERNAL_PREFIX.str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);

        return $base.self::ean13Check($base);
    }
}
