<?php

declare(strict_types=1);

namespace App\Domain\Import\Parsing;

use App\Domain\Import\Exceptions\ImportFileUnreadable;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;

/**
 * Reads YITH Booking's bookings export (CSV) into one normalised array per
 * booking.
 *
 * ## Headers by meaning, not by position
 *
 * YITH's export columns have changed between versions, and an operator who
 * opened the file in Excel and saved it again may have translated nothing but
 * reordered everything. So each column is recognised by its header against a
 * list of the names it has been seen under, case- and space-insensitively.
 * The canonical shape — the one `tests/Fixtures/import/yith-bookings.csv`
 * uses — is:
 *
 * `ID, Product ID, Product, Status, From, To, Persons, Person types, Customer,
 * Email, Phone, Order ID, Total`
 *
 * ## Commas and semicolons
 *
 * A Greek Excel saves CSV with `;`, because `,` is the decimal separator. The
 * delimiter is whichever of the two the header line uses more.
 */
final class BookingsCsvReader
{
    /** canonical key => header names it may appear under (lowercased). */
    private const COLUMNS = [
        'id' => ['id', 'booking id', 'booking_id', 'κωδικός', 'κράτηση'],
        'product_id' => ['product id', 'product_id', 'κωδικός προϊόντος'],
        'product' => ['product', 'product name', 'προϊόν'],
        'status' => ['status', 'booking status', 'κατάσταση'],
        'from' => ['from', 'start', 'start date', 'date', 'από'],
        'to' => ['to', 'end', 'end date', 'έως'],
        'persons' => ['persons', 'people', 'guests', 'pax', 'άτομα'],
        'person_types' => ['person types', 'people types', 'person_types', 'κατηγορίες ατόμων'],
        'customer' => ['customer', 'customer name', 'name', 'user', 'πελάτης'],
        'email' => ['email', 'customer email', 'billing email', 'e-mail'],
        'phone' => ['phone', 'customer phone', 'billing phone', 'τηλέφωνο'],
        'order_id' => ['order id', 'order', 'order_id', 'παραγγελία'],
        'total' => ['total', 'order total', 'cost', 'price', 'σύνολο'],
    ];

    /**
     * @return list<array<string, string|null>>
     *
     * @throws ImportFileUnreadable
     */
    public function read(string $path): array
    {
        $firstLine = $this->firstLine($path);

        if ($firstLine === null) {
            throw ImportFileUnreadable::csv();
        }

        try {
            $reader = Reader::from($path, 'r');
            $reader->setDelimiter(substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');
            $reader->setHeaderOffset(0);

            $columns = $this->columnMap($reader->getHeader());

            if (! isset($columns['id'], $columns['product_id'], $columns['from'])) {
                throw ImportFileUnreadable::csvColumns();
            }

            $rows = [];

            foreach ($reader->getRecords() as $record) {
                $row = [];

                foreach (array_keys(self::COLUMNS) as $key) {
                    $header = $columns[$key] ?? null;
                    $value = $header === null ? null : ($record[$header] ?? null);
                    $value = is_string($value) ? trim($value) : null;
                    $row[$key] = $value === '' ? null : $value;
                }

                if (($row['id'] ?? null) === null) {
                    continue;
                }

                $rows[] = $row;
            }

            return $rows;
        } catch (CsvException) {
            throw ImportFileUnreadable::csv();
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string> canonical key => the header as it appears
     */
    private function columnMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            $normal = $this->normalise($header);

            foreach (self::COLUMNS as $key => $aliases) {
                if (! isset($map[$key]) && in_array($normal, $aliases, true)) {
                    $map[$key] = $header;
                }
            }
        }

        return $map;
    }

    private function normalise(string $header): string
    {
        // A UTF-8 byte-order mark on the first header is how Excel says hello.
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $header) ?? $header));
    }

    private function firstLine(string $path): ?string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        return $line === false || trim($line) === '' ? null : $line;
    }
}
