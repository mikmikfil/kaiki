<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the committed MySQL schema snapshot that ENV-10 compares against.
 *
 * The snapshot is deliberately NOT `database/schema/mysql-schema.sql`.
 * `MigrateCommand::prepareDatabase()` loads a file at that path in place of
 * running the migrations whenever no migration has run yet, so committing one
 * there would make every from-scratch MySQL migration a replay of the dump -
 * the drift job would compare the snapshot with itself and `test-mysql` would
 * stop exercising the migrations at all, both without a red build.
 */
final class SchemaSnapshotCommand extends Command
{
    /**
     * Relative to the project root, so the message a developer reads is the
     * path they type.
     */
    public const string PATH = 'database/schema/mysql-schema.snapshot.sql';

    protected $signature = 'schema:snapshot';

    protected $description = 'Dump the MySQL 8 schema to the committed snapshot compared by the migrate-from-zero gate (ENV-10).';

    public function handle(): int
    {
        $connection = DB::connection();

        if (! $connection instanceof MySqlConnection) {
            // The likely mistake is running this on the local SQLite stack
            // (ADR-0015). A SQLite snapshot would not describe the schema the
            // gate protects, and a silently wrong snapshot is worse than none.
            $this->components->error(sprintf(
                'schema:snapshot needs a MySQL 8 connection; this one is "%s". See docs/ci.md.',
                $connection->getDriverName(),
            ));

            return self::FAILURE;
        }

        $path = base_path(self::PATH);

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, recursive: true);
        }

        $dump = $this->normalise($this->dump($connection));

        file_put_contents($path, $this->header() . $dump);

        $this->components->info(sprintf('Schema snapshot written to %s.', self::PATH));

        return self::SUCCESS;
    }

    /**
     * A fingerprint of the migrations that produced the snapshot.
     *
     * Names and contents both, so renaming a migration or editing its body
     * invalidates the snapshot. This is the half of ENV-10 that can run on
     * SQLite: it catches "changed a migration, forgot to refresh" in seconds
     * instead of after a MySQL job round-trip.
     */
    public static function migrationsFingerprint(): string
    {
        $files = glob(base_path('database/migrations/*.php'));

        if ($files === false) {
            throw new RuntimeException('Could not read database/migrations.');
        }

        sort($files);

        $material = '';

        foreach ($files as $file) {
            // Normalised, not hash_file(): the fingerprint is written on Linux
            // in CI and checked on Windows locally, and a checkout configured
            // for CRLF would otherwise fail this against a snapshot that is
            // perfectly correct.
            $contents = str_replace("\r\n", "\n", (string) file_get_contents($file));

            $material .= basename($file) . ':' . hash('sha256', $contents) . "\n";
        }

        return hash('sha256', $material);
    }

    private function dump(MySqlConnection $connection): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'kaiki-schema-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the schema dump.');
        }

        try {
            // Laravel's own dumper, so the snapshot stays whatever `schema:dump`
            // would have produced - only the destination and the normalisation
            // below are ours.
            $connection->getSchemaState()->dump($connection, $temporary);

            $dump = file_get_contents($temporary);
        } finally {
            @unlink($temporary);
        }

        if ($dump === false) {
            throw new RuntimeException('The schema dump produced no output.');
        }

        return $dump;
    }

    /**
     * Strip everything that varies with the client rather than with the schema.
     *
     * mysqldump writes version-conditional session statements whose numbers
     * move when the runner image bumps its MySQL client. Left in, the drift
     * job goes red for a reason that has nothing to do with the schema, and a
     * gate that cries wolf is a gate people start ignoring.
     */
    private function normalise(string $dump): string
    {
        $lines = preg_split('/\R/', $dump) ?: [];

        $kept = array_filter($lines, static function (string $line): bool {
            $trimmed = trim($line);

            if ($trimmed === '') {
                return true;
            }

            // A leading `--` comment, or a whole-line /*!NNNNN ... */ directive:
            // the session settings mysqldump brackets a dump with, and the
            // sandbox-mode line 8.0.32 added. All of them describe the client,
            // not the schema. Column-level hints such as /*!80000 INVISIBLE */
            // sit inside a CREATE TABLE and are not whole lines, so they stay.
            return preg_match('/^--/', $trimmed) !== 1
                && preg_match('/^\/\*!\d{5,}.*\*\/\s*;?$/', $trimmed) !== 1;
        });

        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));

        return $body . "\n";
    }

    private function header(): string
    {
        return implode("\n", [
            '-- Kaiki - MySQL 8 schema snapshot (ENV-10).',
            '--',
            '-- Generated by `composer schema:snapshot`, which needs a MySQL 8 connection and so',
            '-- runs in CI rather than on the SQLite local stack. Never edited by hand.',
            '--',
            '-- Not named mysql-schema.sql on purpose: Laravel loads a file at that path instead',
            '-- of running the migrations, which would quietly retire the guarantee this file exists to give.',
            '--',
            '-- migrations-fingerprint: sha256:' . self::migrationsFingerprint(),
            '',
            '',
        ]);
    }
}
