<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a manifest may carry (spec OPS-8, OPS-9, OPS-10, GDR-6).
 *
 * An enum rather than free text, because two of these columns are not like the
 * others: `document_number` and `date_of_birth` are the most sensitive fields in
 * the database, and the difference between a manifest with them and one without
 * is the difference between a logged action and an ordinary export.
 *
 * OPS-8 fixes the defaults: *"full name, date of birth, nationality, document
 * number, vessel, date, port, captain"*. The last four are properties of the
 * sailing rather than of a person and appear in the header of the printed
 * layout, not as repeated columns; the first four are the rows.
 */
enum ManifestColumn: string
{
    use HasTranslatedLabel;

    case FullName = 'full_name';
    case DateOfBirth = 'date_of_birth';
    case Nationality = 'nationality';
    case DocumentType = 'document_type';
    case DocumentNumber = 'document_number';

    /** Which booking they came in on, which is how a name is chased. */
    case Reference = 'reference';

    /** Adult, child, infant — and which of those take a seat (OPS-9). */
    case AgeBand = 'age_band';

    case CheckedIn = 'checked_in';

    /**
     * What OPS-8 asks for by default.
     *
     * `document_type` is here beside the number because a passport number and
     * an ID card number look identical on paper and the harbour needs to know
     * which it is holding.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [
            self::FullName,
            self::DateOfBirth,
            self::Nationality,
            self::DocumentType,
            self::DocumentNumber,
        ];
    }

    /**
     * The set an operator may pick from, in the order they appear.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return self::cases();
    }

    /**
     * Does including this column make the manifest a GDR-6 action?
     *
     * The document number, and only the document number. A date of birth is
     * personal and is on every airline boarding card; a passport number is the
     * thing an operator would be reported for emailing to themselves.
     */
    public function isSensitive(): bool
    {
        return $this === self::DocumentNumber;
    }

    /**
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $columns = [];

        // Iterated in **enum order** rather than in the order the operator
        // ticked them, so two manifests of the same departure have their
        // columns in the same places. A harbourmaster reading two sheets side
        // by side is why.
        foreach (self::cases() as $case) {
            if (in_array($case->value, $values, true)) {
                $columns[] = $case;
            }
        }

        // **Not `defaults()`.** Falling back to the default set when nothing was
        // chosen would put document numbers on a manifest the operator did not
        // ask for them on — the one fallback in this file that must not be the
        // convenient one. A name is the least a passenger list can be.
        return $columns === [] ? [self::FullName] : $columns;
    }

    /**
     * @param  list<self>  $columns
     */
    public static function anySensitive(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column->isSensitive()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
