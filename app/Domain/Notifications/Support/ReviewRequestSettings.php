<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use App\Domain\Catalog\Support\SearchFilters;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Whether, when and where to ask a guest for a Google review (2026-09-17).
 *
 * «Μετά από 24 ώρες (επιλογή operator) να στέλνεται να μπουν για αξιολόγηση
 * στην Google» — three settings, one switch, a delay and the link.
 *
 * ## In `tenants.settings`, not three columns
 *
 * The same home as the search filters ({@see SearchFilters}):
 * an operator preference nothing queries by and nothing joins on. The sweep
 * reads it per booking it already has the tenant for.
 *
 * ## Off until the operator says otherwise, and off without a link
 *
 * An email that asks for a review and cannot say where to leave one is worse
 * than no email, so {@see active()} needs both the switch and a URL. The delay
 * is clamped to between an hour and a week: never before the boat is back,
 * never so late the guest has forgotten the day.
 */
final class ReviewRequestSettings
{
    public const SETTINGS_KEY = 'review_requests';

    public const DEFAULT_DELAY_HOURS = 24;

    public const MIN_DELAY_HOURS = 1;

    public const MAX_DELAY_HOURS = 168;

    public function __construct(
        public readonly bool $enabled,
        public readonly int $delayHours,
        public readonly ?string $googleUrl,
    ) {}

    public static function for(?Tenant $tenant = null): self
    {
        $tenant ??= Tenancy::current();

        $stored = $tenant?->settings[self::SETTINGS_KEY] ?? [];

        return self::fromArray(is_array($stored) ? $stored : []);
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $url = is_string($input['google_url'] ?? null) ? trim($input['google_url']) : '';

        return new self(
            enabled: filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            delayHours: max(self::MIN_DELAY_HOURS, min(self::MAX_DELAY_HOURS, (int) ($input['delay_hours'] ?? self::DEFAULT_DELAY_HOURS))),
            googleUrl: $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://')
                ? $url
                : null,
        );
    }

    /** Switched on, and with somewhere to send the guest. */
    public function active(): bool
    {
        return $this->enabled && $this->googleUrl !== null;
    }

    /** @return array{enabled: bool, delay_hours: int, google_url: string|null} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'delay_hours' => $this->delayHours,
            'google_url' => $this->googleUrl,
        ];
    }
}
