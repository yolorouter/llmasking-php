<?php

// src/Internal/Validate.php

namespace Yolorouter\Llmasking\Internal;

/** Pure, total, side-effect-free predicates. Mirrors Go internal/validate. */
final class Validate
{
    public static function isDigit(string $byte): bool
    {
        return $byte >= '0' && $byte <= '9';
    }

    /**
     * Reports whether $score is a finite float in [0,1] — the Finding score
     * contract (spec sections 4.3 and 5.4). Single source for the NaN/INF/range
     * check used by Recognizers::rule() (config time) and ConflictResolver
     * (recognition time).
     */
    public static function scoreInUnitRange(float $score): bool
    {
        return !\is_nan($score) && !\is_infinite($score) && $score >= 0.0 && $score <= 1.0;
    }

    public static function luhn(string $digits): bool
    {
        if (\strlen($digits) < 2) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = \strlen($digits) - 1; $i >= 0; $i--) {
            $c = $digits[$i];
            if (!self::isDigit($c)) {
                return false;
            }
            $d = \ord($c) - \ord('0');
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    private const CHINA_ID_WEIGHTS = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
    private const CHINA_ID_CHECK = '10X98765432';

    public static function chinaIdChecksum(string $id): bool
    {
        if (\strlen($id) !== 18) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $c = $id[$i];
            if (!self::isDigit($c)) {
                return false;
            }
            $sum += (\ord($c) - \ord('0')) * self::CHINA_ID_WEIGHTS[$i];
        }
        $want = self::CHINA_ID_CHECK[$sum % 11];
        $got = $id[17];
        if ($got >= 'a' && $got <= 'z') {
            $got = \strtoupper($got); // normalize lowercase x
        }
        return $got === $want;
    }

    public static function ssnValid(string $ssn): bool
    {
        if (\strlen($ssn) !== 11 || $ssn[3] !== '-' || $ssn[6] !== '-') {
            return false;
        }
        for ($i = 0; $i < 11; $i++) {
            if (($i === 3 || $i === 6)) {
                continue;
            }
            if (!self::isDigit($ssn[$i])) {
                return false;
            }
        }
        $area = \substr($ssn, 0, 3);
        $group = \substr($ssn, 4, 2);
        $serial = \substr($ssn, 7, 4);
        if ($area === '000' || $area === '666' || $area[0] === '9') {
            return false;
        }
        if ($group === '00' || $serial === '0000') {
            return false;
        }
        return true;
    }

    public static function shannonEntropy(string $s): float
    {
        $len = \strlen($s);
        if ($len === 0) {
            return 0.0;
        }
        // Fixed 256-entry byte-frequency table: O(n) time, O(1) extra space.
        // Avoids str_split()+array_count_values(), which would allocate a zval
        // per input byte and risk blowing past the memory limit on a ~1 MiB
        // secret before the library could fail closed.
        $counts = \array_fill(0, 256, 0);
        for ($i = 0; $i < $len; $i++) {
            $counts[\ord($s[$i])]++;
        }
        $entropy = 0.0;
        foreach ($counts as $count) {
            if ($count > 0) {
                $p = $count / $len;
                $entropy -= $p * \log($p, 2);
            }
        }
        return $entropy;
    }
}
