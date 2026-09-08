<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

/**
 * The one place this system writes a CSV (spec OPS-8, OPS-17, CMP-3).
 *
 * ## Three properties of the operator's machine, not of the data
 *
 * A file that is technically correct and unreadable at the other end is not a
 * correct file, and all three of these were learned the same way — from Excel
 * on a Greek Windows machine, which is what the harbourmaster and the
 * accountant both use.
 *
 * - **A byte-order mark.** Without one Excel reads UTF-8 as Windows-1253 and
 *   every Greek name becomes mojibake. It looks like our bug and cannot be
 *   fixed from the operator's side.
 * - **CRLF line endings**, because that is what Excel and a Windows printer
 *   driver expect; LF alone can arrive as one very long line.
 * - **A configurable separator.** Excel in a Greek locale splits on semicolons,
 *   so a comma-separated file opens as a single column.
 *
 * ## Written to a stream, never assembled
 *
 * OPS-18 requires exports to be *"streamed to avoid memory pressure"*, and the
 * reason is arithmetic rather than principle: an operator with four seasons of
 * bookings has six figures of rows, and a string built in memory is the whole
 * file plus its own copy during concatenation. This writes each line to the
 * handle as it is produced and never holds more than one.
 *
 * `fputcsv` rather than `implode`, because a Greek name containing a comma — or
 * a separator that *is* a semicolon inside an address — is exactly the case a
 * hand-rolled join gets wrong, and it gets it wrong silently by shifting every
 * subsequent column one to the left.
 */
final class CsvWriter
{
    /** @param resource $handle */
    private function __construct(private $handle, private readonly string $separator) {}

    /**
     * Open a writer over a stream, writing the byte-order mark first.
     *
     * @param  resource  $handle
     */
    public static function to($handle, ?string $separator = null): self
    {
        $writer = new self($handle, $separator ?? self::separator());

        fwrite($handle, "\u{FEFF}");

        return $writer;
    }

    /**
     * The whole thing in memory, for a file small enough that it does not
     * matter — a single departure's manifest is at most a few dozen rows.
     *
     * @param  list<list<string>>  $rows
     */
    public static function toString(array $rows, ?string $separator = null): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        $writer = self::to($handle, $separator);

        foreach ($rows as $row) {
            $writer->write($row);
        }

        rewind($handle);

        $out = (string) stream_get_contents($handle);

        fclose($handle);

        return $out;
    }

    /**
     * One row.
     *
     * `fputcsv` writes its own trailing newline and there is no way to ask it
     * for CRLF, so the line is produced into a scratch stream and re-terminated.
     * Doing it any other way means re-implementing the quoting rules, which is
     * the part that is easy to get subtly wrong.
     *
     * @param  list<string>  $values
     */
    public function write(array $values): void
    {
        $scratch = fopen('php://memory', 'r+');

        if ($scratch === false) {
            fwrite($this->handle, implode($this->separator, $values) . "\r\n");

            return;
        }

        fputcsv($scratch, $values, $this->separator, '"', '\\');

        rewind($scratch);

        $line = (string) stream_get_contents($scratch);

        fclose($scratch);

        fwrite($this->handle, rtrim($line, "\r\n") . "\r\n");
    }

    /** The separator from config — a property of the machine (§`kaiki.exports`). */
    public static function separator(): string
    {
        $separator = config('kaiki.exports.csv_separator', ',');

        return is_string($separator) && $separator !== '' ? $separator : ',';
    }
}
