<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasKaikiTranslations;
use Database\Factories\VatRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A VAT rate, set by Greek tax law rather than by an operator
 * (`docs/data-model.md` §2.3, spec CAT-11, ADR-0002 Option A).
 *
 * **Platform-owned.** It carries no `tenant_id` and does not use
 * `BelongsToTenant`; it is named in `platform_owned_models` in
 * `config/tenancy.php`, because TEN-5 allows no third state between
 * tenant-owned and explicitly platform-owned. Every operator selects from the
 * same rows.
 *
 * ## This model holds no percentage
 *
 * It reads them. CAT-11a forbids a percent literal or a percent-to-category
 * mapping anywhere in `app/`, and `NoHardcodedVatRateTest` enforces that with a
 * scanner — so `vat_category` is a column beside `rate_bp` rather than a
 * `match` here, and the myDATA client will read the snapshot rather than
 * deriving a category from a number.
 *
 * ## The rate is read once and then frozen
 *
 * `products.vat_rate_id` and `extras.vat_rate_id` point here, but nothing
 * downstream re-reads this table: PRC-14 snapshots `rate_bp` and `vat_category`
 * onto every priced line, so a statutory change cannot rewrite an invoice
 * issued last year. That is why `restrictOnDelete` guards the foreign keys and
 * why a superseded rate is retired with `is_selectable` rather than deleted.
 *
 * @property int $id
 * @property string $code
 * @property int $rate_bp basis points — 1300 is 13.00%
 * @property string $vat_category the AADE myDATA `vatCategory` id
 * @property string $description translatable
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to null means currently in force
 * @property bool $is_selectable
 */
class VatRate extends Model
{
    /** @use HasFactory<VatRateFactory> */
    use HasFactory;

    use HasKaikiTranslations;

    protected $guarded = [];

    /**
     * Translatable (§1.6, §3.14) and not searchable — there are a handful of
     * rows, maintained by one super-admin, and nobody searches a VAT table.
     *
     * @var list<string>
     */
    public array $translatable = ['description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_selectable' => 'boolean',
        ];
    }

    /**
     * The rates an operator may pick in the product form.
     *
     * Retired rows are excluded here and **only** here: a product already
     * pointing at one still resolves it, which is the difference between
     * retiring a rate and deleting one.
     *
     * @param  Builder<VatRate>  $query
     * @return Builder<VatRate>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_selectable', true);
    }

    /**
     * The rows in force on a given date.
     *
     * `valid_to` null means open-ended, which is why this cannot be a plain
     * `whereBetween` — the common case is exactly the row with no end date.
     *
     * @param  Builder<VatRate>  $query
     * @return Builder<VatRate>
     */
    public function scopeInForceOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $date));
    }

    /** Is this row the one currently in force for its code? */
    public function isInForce(): bool
    {
        return $this->valid_to === null || $this->valid_to->isFuture();
    }

    /**
     * The rate as a percentage, for display only.
     *
     * Not a business calculation: pricing works in basis points and integer
     * cents throughout (CNV-1), and this exists so a form label can read
     * "13.00%" without every caller dividing by a hundred slightly differently.
     */
    public function percentLabel(): string
    {
        return number_format($this->rate_bp / 100, 2) . '%';
    }
}
