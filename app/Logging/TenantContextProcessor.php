<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Tenancy;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log line with `tenant_id` and `request_id` (spec OBS-2).
 *
 * In a single-database multi-tenant system a log line without a tenant is
 * nearly useless: "booking confirmation failed" is unanswerable until you know
 * whose. `request_id` ties together the lines a single request produced, which
 * is what makes a queued job's failure traceable back to the click that caused
 * it.
 *
 * Reads the tenant rather than being told it, so a line written from a queued
 * job carries whichever tenant that job resolved — no caller has to remember.
 */
final class TenantContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            'tenant_id' => Tenancy::id(),
            'request_id' => app()->bound('kaiki.request_id')
                ? app('kaiki.request_id')
                : null,
        ]);
    }
}
