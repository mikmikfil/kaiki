<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Forms\Components\TextInput;

/**
 * A euro amount that is an integer number of cents on both sides of the form.
 *
 * CNV-1 forbids a float anywhere in the money path, **including transiently in
 * form state**. An operator types «150,50» and the column stores `15050`; the
 * obvious `(float) $state * 100` between those two is exactly the thing the
 * rule exists to prevent, because `1.15 * 100` is `114.99999999999999` and the
 * cent it loses turns up on an invoice months later.
 *
 * So the conversion goes through `brick/money` from a **string**: `Money::of()`
 * parses decimal text exactly, and `getMinorAmount()` hands back the integer.
 * Nothing here is ever a float.
 *
 * ## The comma
 *
 * A Greek operator types a decimal comma, because that is what a Greek keyboard
 * and a Greek invoice both use. `Money::of('150,50')` would throw, so the comma
 * is normalised to a point before parsing — and the input is therefore not a
 * `numeric()` field, since a browser number input rejects the comma outright.
 */
final class MoneyInput
{
    public static function make(string $name, string $label, ?string $helperText = null, bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->helperText($helperText)
            ->prefix(self::CURRENCY_PREFIX)
            ->required($required)
            ->maxLength(12)
            // Not `numeric()`: that renders `<input type="number">`, which
            // silently refuses the decimal comma a Greek operator types.
            //
            // The seven integer digits are also the only cap on the amount —
            // one place saying how large a price may be, rather than a rule and
            // a clamp that can drift apart.
            ->rule('regex:/^\s*\d{1,7}([.,]\d{1,2})?\s*$/')
            ->formatStateUsing(static fn (mixed $state): ?string => self::toDecimal($state))
            ->dehydrateStateUsing(static fn (mixed $state): ?int => self::toCents($state));
    }

    /** Not a translatable string: the euro sign is the same in every locale. */
    private const CURRENCY_PREFIX = '€';

    /** Integer cents out of the database, decimal text into the input. */
    public static function toDecimal(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_string($state) && ! ctype_digit($state)) {
            // Already decimal text — a failed submit being re-rendered.
            return $state;
        }

        return (string) Money::ofMinor((int) $state, 'EUR')->getAmount();
    }

    /** Decimal text out of the input, integer cents into the database. */
    public static function toCents(mixed $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }

        $normalised = str_replace([' ', ','], ['', '.'], trim((string) $state));

        if ($normalised === '' || ! preg_match('/^\d+(\.\d+)?$/', $normalised)) {
            return null;
        }

        return Money::of($normalised, 'EUR', roundingMode: RoundingMode::HALF_UP)
            ->getMinorAmount()
            ->toInt();
    }
}
