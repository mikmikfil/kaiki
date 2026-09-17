<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Actions\ConvertPercentPricesToEuros;
use App\Models\Tenant;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Write every percentage-priced age band as euros, per rate plan (2026-09-17).
 *
 * A one-off command rather than a migration: it reads tenant-scoped models,
 * rounds money through the engine's own helper, and needs a preview an owner
 * can read before anything is written. A migration can do none of those
 * comfortably and runs on every fresh test database for no reason.
 *
 * **A preview by default.** Nothing is written without `--apply`, and the
 * preview lists every price it would write, so the figures can be checked
 * against what guests pay today. See {@see ConvertPercentPricesToEuros}.
 */
final class ConvertPercentPricesCommand extends Command
{
    protected $signature = 'kaiki:prices-to-euros
        {--tenant=* : Tenant id or slug; repeatable. Defaults to every tenant.}
        {--apply : Write the prices. Without it, only show what would be written.}';

    protected $description = 'Convert age bands priced as a percentage of the base band into euro prices, once.';

    public function handle(ConvertPercentPricesToEuros $convert): int
    {
        $apply = (bool) $this->option('apply');
        $named = array_map('strval', (array) $this->option('tenant'));

        $tenants = Tenant::query()
            ->when($named !== [], static fn ($query) => $query->whereIn('id', $named)->orWhereIn('slug', $named))
            ->get();

        $rows = [];
        $skipped = [];
        $products = 0;

        foreach ($tenants as $tenant) {
            $result = Tenancy::forTenant($tenant, static fn (): array => $convert($apply));
            $products += $result['products'];

            foreach ($result['planned'] as $line) {
                $rows[] = [
                    (string) $tenant->slug,
                    $line['product'],
                    $line['plan'],
                    $line['band'],
                    ($line['basis_points'] / 100) . '%',
                    MoneyFormatter::format($line['base_cents']),
                    MoneyFormatter::format($line['cents']),
                ];
            }

            foreach ($result['skipped'] as $line) {
                $skipped[] = [(string) $tenant->slug, $line['product'], $line['plan'], 'no base price on this plan'];
            }
        }

        if ($rows !== []) {
            $this->table(['Tenant', 'Trip', 'Period', 'Band', 'Share', 'Base', 'Writes'], $rows);
        }

        if ($skipped !== []) {
            $this->components->warn('Left unchanged (fix in the trip price table):');
            $this->table(['Tenant', 'Trip', 'Period', 'Why'], $skipped);
        }

        $this->components->info($apply
            ? "Converted {$products} trip(s), wrote " . count($rows) . ' price(s).'
            : "Would convert {$products} trip(s) and write " . count($rows) . ' price(s). Run with --apply to write them.');

        return self::SUCCESS;
    }
}
