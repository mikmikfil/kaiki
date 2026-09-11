<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementSeverity;
use App\Models\Concerns\HasKaikiTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notice from the platform to every operator at once (spec SAA-1).
 *
 * **Platform-owned**, like `VatRate`: no `tenant_id`, named in
 * `config/tenancy.php`'s `platform_owned_models`. A super-admin writes it in
 * `/admin`; every operator's panel reads it.
 *
 * ## Plain text, and stripped on the way in as well as escaped on the way out
 *
 * The banner renders with `{{ }}`, so markup could never execute. Stripping it
 * here too means the text an operator reads is the text the platform owner
 * meant — not `<b>Κυριακή</b>` with the angle brackets showing.
 *
 * @property int $id
 * @property string $message translatable
 * @property AnnouncementSeverity $severity
 * @property Carbon|null $starts_at null means from the moment it is saved
 * @property Carbon|null $ends_at null means until it is switched off
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformAnnouncement extends Model
{
    use HasKaikiTranslations;

    protected $guarded = [];

    /**
     * Translatable and neither searchable nor sortable: a handful of rows,
     * written by one person.
     *
     * @var list<string>
     */
    public array $translatable = ['message'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => AnnouncementSeverity::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $announcement): void {
            foreach ($announcement->getTranslations('message') as $locale => $text) {
                $announcement->setTranslation('message', (string) $locale, trim(strip_tags((string) $text)));
            }
        });
    }

    /**
     * Switched on, started, and not yet ended.
     *
     * @param  Builder<PlatformAnnouncement>  $query
     * @return Builder<PlatformAnnouncement>
     */
    public function scopeCurrent(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->where('is_active', true)
            ->where(static fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(static fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
