<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

/**
 * The crew typed by name (Mike, 2026-09-25) — people with no Kaiki account,
 * the crew's counterpart of `captain_name`.
 *
 * One cleaning for every writer: each name trimmed and its inner spaces
 * collapsed, empties dropped, the same name twice kept once (ignoring case),
 * at most {@see self::MAX_COUNT} of them, each at most
 * {@see self::MAX_LENGTH} characters — the length of `captain_name`.
 */
final class CrewNames
{
    public const int MAX_LENGTH = 120;

    public const int MAX_COUNT = 20;

    /**
     * @return list<string>|null null when nobody is left, which is what the
     *                           column stores for "none"
     */
    public static function clean(mixed $names): ?array
    {
        if (is_string($names)) {
            $names = [$names];
        }

        if (! is_array($names)) {
            return null;
        }

        $clean = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', $name));

            if ($name === '') {
                continue;
            }

            $name = mb_substr($name, 0, self::MAX_LENGTH);
            $clean[mb_strtolower($name)] ??= $name;

            if (count($clean) >= self::MAX_COUNT) {
                break;
            }
        }

        return $clean === [] ? null : array_values($clean);
    }
}
