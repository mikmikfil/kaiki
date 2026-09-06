<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuoteLineKind;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use Database\Factories\QuoteLineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line an operator wrote on a quote (`docs/data-model.md` §2.5, BKG-27).
 *
 * ## The amount is positive and `kind` carries the sign
 *
 * §1.4, restated by §2.5 for this table. {@see self::signedTotalCents()} is the
 * only place that mapping is applied, so a total computed anywhere else in the
 * product cannot disagree with the one on the quote the guest is reading.
 *
 * ## `total_cents` is stored rather than derived
 *
 * `qty × unit_price_cents` is trivial arithmetic, and it is stored anyway,
 * because a quote is a **document**: an operator who wrote "3 × €95 = €280"
 * with a hand-adjusted total has made an offer at €280, and a model that
 * recomputed it would quietly send the guest a different number from the one
 * their operator typed. {@see self::deriveTotal()} is available for the panel
 * to prefill; nothing recomputes on read.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $quote_id
 * @property string $label translatable
 * @property string|null $description translatable
 * @property QuoteLineKind $kind
 * @property int $qty
 * @property int $unit_price_cents
 * @property int $total_cents
 * @property int $sort_order
 */
class QuoteLineItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuoteLineItemFactory> */
    use HasFactory;

    use HasKaikiTranslations;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['label', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => QuoteLineKind::class,
            'qty' => 'integer',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** The line's contribution to the quote total, with its sign applied. */
    public function signedTotalCents(): int
    {
        return $this->kind->signum() * $this->total_cents;
    }

    /**
     * What `qty × unit_price_cents` comes to.
     *
     * For the panel to prefill with. **Not** used on read — see the class
     * docblock: an operator's hand-adjusted total is the offer they made.
     */
    public function deriveTotal(): int
    {
        return $this->qty * $this->unit_price_cents;
    }
}
