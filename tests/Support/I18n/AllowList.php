<?php

declare(strict_types=1);

namespace Tests\Support\I18n;

/**
 * Reads `allow-list.php`, the single documented home for both i18n lint
 * exemptions (I18N-2).
 */
final class AllowList
{
    /**
     * Lang keys whose Greek and English values are legitimately identical.
     *
     * @return array<string, string> key => reason
     */
    public static function identicalTranslations(): array
    {
        /** @var array<string, string> $entries */
        $entries = self::read()['identical_translations'] ?? [];

        return $entries;
    }

    /**
     * Source locations permitted to hold a user-facing literal.
     *
     * @return array<string, string> "path:line" or "path" => reason
     */
    public static function literals(): array
    {
        /** @var array<string, string> $entries */
        $entries = self::read()['literals'] ?? [];

        return $entries;
    }

    /** @return array<string, array<string, string>> */
    private static function read(): array
    {
        /** @var array<string, array<string, string>> $list */
        $list = require __DIR__ . '/allow-list.php';

        return $list;
    }
}
