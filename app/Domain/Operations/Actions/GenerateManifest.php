<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\Manifest;
use App\Enums\ManifestColumn;
use App\Events\ManifestGenerated;
use App\Models\Booking;
use App\Models\Departure;
use Illuminate\Database\Eloquent\Model;

/**
 * A manifest, and the record that somebody asked for one
 * (spec OPS-8, OPS-10, GDR-6, CMP-2).
 *
 * ## The logging is the requirement, not the bookkeeping
 *
 * OPS-10: *"Generating a manifest containing document numbers is an explicit,
 * logged operator action"*. GDR-6 makes the manifest the **only** place those
 * numbers may appear, so the log is what makes that claim auditable — without
 * it, "they only appear in the manifest" is an assertion nobody can check.
 *
 * The row is written when the manifest *contains* the column, not whenever one
 * is generated. A crew list of names and nationalities is an ordinary export
 * and logging it would fill the trail with rows nobody needs to read, which is
 * how a trail stops being read at all.
 *
 * ## CSV is written by hand, and that is deliberate
 *
 * Excel on a Greek Windows machine opens a UTF-8 CSV as mojibake unless it
 * finds a byte-order mark, and it splits on semicolons rather than commas in a
 * Greek locale. Both are properties of the operator's machine rather than of
 * the data, and a library that produced a technically correct file would
 * produce one the harbourmaster cannot read. So: a BOM, and a separator from
 * config.
 *
 * ## The PDF is Blade through Chromium
 *
 * ARC-10, and dompdf is forbidden by it. The reason shows up immediately here:
 * CMP-3 requires Greek text to render correctly and long names not to clip, and
 * dompdf's font handling is where Greek goes to become boxes. Chromium renders
 * what the browser renders.
 */
final class GenerateManifest
{
    /**
     * @param  list<ManifestColumn>  $columns
     */
    public function forDeparture(Departure $departure, array $columns, ?int $userId = null): Manifest
    {
        return $this->record(Manifest::forDeparture($departure, $columns), $departure, $columns, $userId);
    }

    /**
     * @param  list<ManifestColumn>  $columns
     */
    public function forBooking(Booking $booking, array $columns, ?int $userId = null): Manifest
    {
        return $this->record(Manifest::forBooking($booking, $columns), $booking, $columns, $userId);
    }

    /**
     * The file itself.
     *
     * A BOM first: without it Excel on a Greek Windows machine reads UTF-8 as
     * Windows-1253 and every name becomes mojibake — which looks like our bug
     * and is unfixable from the operator's side.
     */
    public function csv(Manifest $manifest): string
    {
        $separator = (string) config('kaiki.exports.csv_separator', ',');

        $out = "\u{FEFF}";

        $out .= $this->csvLine(
            array_map(static fn (ManifestColumn $column): string => $column->label(), $manifest->columns),
            $separator,
        );

        foreach ($manifest->rows as $row) {
            $out .= $this->csvLine(array_values($row), $separator);
        }

        return $out;
    }

    /**
     * A filename an operator can find again in a downloads folder in November.
     */
    public function filename(Manifest $manifest, string $extension): string
    {
        $parts = array_filter([
            'manifest',
            $manifest->header['date'] ?? null,
            $manifest->header['vessel'] ?? null,
        ]);

        // `slug()` transliterates, so a Greek vessel name becomes something a
        // Windows downloads folder and an email attachment both survive.
        $slug = str(implode('-', $parts))->slug()->value();

        return ($slug === '' ? 'manifest' : $slug) . '.' . $extension;
    }

    /**
     * @param  list<ManifestColumn>  $columns
     */
    private function record(Manifest $manifest, Model $subject, array $columns, ?int $userId): Manifest
    {
        if (ManifestColumn::anySensitive($columns)) {
            ManifestGenerated::dispatch($subject, $manifest->onBoard, $userId);
        }

        return $manifest;
    }

    /**
     * One CSV line, quoted the way a spreadsheet expects.
     *
     * `fputcsv` to a memory stream rather than `implode`, because a Greek name
     * with a comma in it — or a separator that *is* a semicolon — is exactly
     * the case a hand-rolled join gets wrong.
     *
     * @param  list<string>  $values
     */
    private function csvLine(array $values, string $separator): string
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            return implode($separator, $values) . "\r\n";
        }

        fputcsv($handle, $values, $separator, '"', '\\');

        rewind($handle);

        $line = (string) stream_get_contents($handle);

        fclose($handle);

        // CRLF, because that is what Excel expects and what a Windows printer
        // driver handles without turning the file into one long line.
        return rtrim($line, "\r\n") . "\r\n";
    }
}
