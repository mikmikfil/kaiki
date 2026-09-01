<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The public API scope vocabulary, in **dot form** (spec SEC-5, data-model §3.12).
 *
 * Colon form (`catalog:read`) appeared in an early draft of ADR-0013 and is
 * dead — it was corrected on 2026-08-28 to match the data model and the API
 * contract. Being an enum rather than loose strings means a typo is a fatal
 * error at save rather than a scope that silently never matches.
 */
enum ApiScope: string
{
    case ProductsRead = 'products.read';
    case AvailabilityRead = 'availability.read';
    case BrandingRead = 'branding.read';
    case BookingsWrite = 'bookings.write';
    case QuotesWrite = 'quotes.write';
    case WebhooksReceive = 'webhooks.receive';

    /**
     * The operator-facing name of this scope.
     *
     * Fetches the whole `api.scope` array and indexes it, rather than asking
     * for `api.scope.products.read`. The scope values contain a dot, and
     * Laravel reads a dot in a translation key as a path separator — so the
     * direct lookup searched for `scope → products → read`, found nothing, and
     * returned the key itself. It rendered as `api.scope.products.read` on
     * screen, which is exactly the quiet I18N-1 failure the lang files exist to
     * prevent. Found by #10 when these labels first reached a form.
     */
    public function label(): string
    {
        /** @var array<string, string> $labels */
        $labels = (array) trans('api.scope');

        return $labels[$this->value] ?? $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * The read-only set, granted to a publishable key by default.
     *
     * @return list<self>
     */
    public static function readScopes(): array
    {
        return [self::ProductsRead, self::AvailabilityRead, self::BrandingRead];
    }
}
