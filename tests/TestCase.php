<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Translatable\TranslatableFixture;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test-only migration paths, registered before any trait migrates.
     *
     * {@see TranslatableFixture} needs a table, and there are only bad places
     * to put it. `database/migrations` ships a fixture table to production.
     * `Schema::create()` in a `beforeEach` is DDL inside
     * {@see RefreshDatabase}'s transaction — fine on SQLite, an implicit commit
     * on MySQL 8, so the isolation quietly disappears in CI and nowhere else.
     *
     * The timing is why this hangs off `refreshApplication()` rather than
     * `afterApplicationCreated()`. Laravel's `setUp()` runs `setUpTraits()` —
     * where `RefreshDatabase` migrates — **before** the after-created callbacks
     * fire, so a path registered there arrives one step too late and the table
     * is never created.
     *
     * @var list<string>
     */
    private const MIGRATION_PATHS = [
        __DIR__ . '/Support/Translatable/migrations',
    ];

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $migrator = $this->app?->make('migrator');

        if (! $migrator instanceof Migrator) {
            return;
        }

        foreach (self::MIGRATION_PATHS as $path) {
            $migrator->path($path);
        }
    }
}
