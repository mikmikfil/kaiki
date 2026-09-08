<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\CsvWriter;
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
     * The byte-order mark, the CRLF endings and the configurable separator all
     * live in {@see CsvWriter} — a manifest and an accounting export are opened
     * on the same Greek Windows machine, and two implementations of "what Excel
     * needs" is two chances to fix only one of them.
     *
     * In memory rather than streamed, unlike the OPS-17 exports: a manifest is
     * one sailing, which is at most a few dozen rows, and it is produced inside
     * a request that has to answer with a file.
     */
    public function csv(Manifest $manifest): string
    {
        $rows = [array_map(
            static fn (ManifestColumn $column): string => $column->label(),
            $manifest->columns,
        )];

        foreach ($manifest->rows as $row) {
            $rows[] = array_values($row);
        }

        return CsvWriter::toString($rows);
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
}
