<?php

namespace App\Support;

/**
 * Pul va miqdorlar uchun float'siz (butun sonli) hisob-kitob.
 * Miqdor: 3 xona (1.5 => 1500), pul: 2 xona (1500.50 => 150050).
 */
class Dec
{
    public static function units(string|int|float|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $s = is_string($value) ? trim($value) : number_format((float) $value, $scale, '.', '');
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            $s = number_format((float) $s, $scale, '.', '');
        }

        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);
        $n = (int) ($int.$frac);

        return $neg ? -$n : $n;
    }

    public static function str(int $units, int $scale): string
    {
        $neg = $units < 0;
        $s = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $out = $scale > 0 ? substr($s, 0, -$scale).'.'.substr($s, -$scale) : $s;

        return ($neg ? '-' : '').$out;
    }

    /** Miqdor(3 xona) x narx(2 xona) => summa(2 xona), yarim yuqoriga yaxlitlanadi. */
    public static function lineTotal(int $qty3, int $price2): int
    {
        return intdiv($qty3 * $price2 + 500, 1000);
    }

    /**
     * a * b / c ni butun sonlarda aniq hisoblaydi: oraliq ko'paytma 64 bitdan oshsa ham xato bermaydi (float ishlatilmaydi).
     * Yaxlitlash: 'half_up' (yarim yuqoriga) yoki 'floor'. Talab: a >= 0, b >= 0, c > 0.
     */
    public static function mulDiv(int $a, int $b, int $c, string $mode = 'half_up'): int
    {
        if ($a < 0 || $b < 0 || $c <= 0) {
            throw new \InvalidArgumentException('mulDiv: noto‘g‘ri argumentlar.');
        }

        $base = intdiv($a, $c) * $b;
        $a %= $c;
        $result = 0;
        $rem = 0;

        for ($i = 62; $i >= 0; $i--) {
            $result *= 2;
            $rem *= 2;
            if (($b >> $i) & 1) {
                $rem += $a;
            }
            if ($rem >= $c) {
                $q = intdiv($rem, $c);
                $result += $q;
                $rem -= $q * $c;
            }
        }

        if ($mode === 'half_up' && $rem * 2 >= $c) {
            $result++;
        }

        return $base + $result;
    }
}
