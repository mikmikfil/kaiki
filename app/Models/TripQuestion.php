<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Booking\Support\TripQuestionForm;
use App\Enums\TripQuestionScope;
use App\Enums\TripQuestionType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasUuid;
use Database\Factories\TripQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A question the operator asks at checkout, on one trip (2026-09-17).
 *
 * The migration has the argument for the shape. What a guest is shown and how
 * an answer is checked is {@see TripQuestionForm}'s; what is kept is a
 * {@see BookingAnswer}, with its own copy of this question.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property string $label translatable
 * @property TripQuestionType $type
 * @property TripQuestionScope $scope
 * @property list<array<string, string>>|null $options for a choice, each {el, en}
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 */
class TripQuestion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TripQuestionFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['label'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TripQuestionType::class,
            'scope' => TripQuestionScope::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The copy an answer keeps, so renaming the question later changes nothing
     * that was already answered.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'uuid' => $this->uuid,
            'label' => $this->getTranslations('label'),
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'options' => $this->options ?? [],
        ];
    }
}
