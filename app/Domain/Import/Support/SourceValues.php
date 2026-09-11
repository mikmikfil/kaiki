<?php

declare(strict_types=1);

namespace App\Domain\Import\Support;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The small conversions every source needs: a price as integer cents, a local
 * date and time, a person-types cell as counts.
 *
 * Money goes through `brick/money` (CNV-1) — never a float. «165,00 €» from a
 * Greek export and «165.00» from an English one are the same 16500 cents.
 */
final class SourceValues
{
    /** Integer cents, or null when there is no price to read. */
    public static function cents(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';

        if ($clean === '' || $clean === '-') {
            return null;
        }

        // "1.234,56" (Greek) and "1,234.56" (English): whichever separator
        // comes last is the decimal one.
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        try {
            return Money::of($clean, 'EUR', null, RoundingMode::HALF_UP)->getMinorAmount()->toInt();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A local date and `HH:MM` time from an export cell, or null.
     *
     * @return array{date: string, time: string}|null
     */
    public static function localDateTime(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i', 'd-m-Y H:i', 'd/m/Y H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, trim($value));
            } catch (Throwable) {
                continue;
            }

            if ($parsed instanceof Carbon && $parsed->format($format) === trim($value)) {
                $hasTime = str_contains($format, 'H');

                return [
                    'date' => $parsed->toDateString(),
                    'time' => $hasTime ? $parsed->format('H:i') : '00:00',
                ];
            }
        }

        return null;
    }

    /**
     * "Ενήλικας: 2 | Παιδί: 1", "2 x Adult, 1 x Child" → ['Ενήλικας' => 2, 'Παιδί' => 1].
     *
     * @return array<string, int>
     */
    public static function personCounts(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $out = [];

        foreach (preg_split('/[|;,\n]+/u', $value) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^(.+?)\s*[:=]\s*(\d+)$/u', $part, $m) === 1
                || preg_match('/^(.+?)\s+[x×]\s*(\d+)$/u', $part, $m) === 1) {
                $out[trim($m[1])] = ($out[trim($m[1])] ?? 0) + (int) $m[2];
            } elseif (preg_match('/^(\d+)\s*[x×]?\s*(.+)$/u', $part, $m) === 1) {
                $out[trim($m[2])] = ($out[trim($m[2])] ?? 0) + (int) $m[1];
            }
        }

        return $out;
    }

    public static function normaliseName(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
