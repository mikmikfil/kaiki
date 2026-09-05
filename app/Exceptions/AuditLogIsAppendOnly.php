<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\AuditLog;
use RuntimeException;

/**
 * Something tried to change or remove an audit row (ADR-0025, spec SEC-16).
 *
 * The ADR's sentence is the whole rule: *"an audit row that can be edited is
 * not an audit row."* This is thrown rather than logged because a caller that
 * silently failed to edit the trail would go on believing it had, and a trail
 * whose integrity depends on nobody having tried is not evidence.
 *
 * The one legitimate removal is the seven-year retention purge, and it goes
 * through {@see AuditLog::purge()} — the query builder rather than the model,
 * named and documented, so the exception is a method rather than a loophole.
 */
final class AuditLogIsAppendOnly extends RuntimeException
{
    public static function onUpdate(AuditLog $log): self
    {
        return new self(sprintf(
            'Audit log %s cannot be updated. The trail is append-only (ADR-0025); '
            . 'record a new entry describing the correction instead.',
            self::describe($log),
        ));
    }

    public static function onDelete(AuditLog $log): self
    {
        return new self(sprintf(
            'Audit log %s cannot be deleted. The trail is append-only (ADR-0025); '
            . 'the retention purge is AuditLog::purge(), which is the only removal there is.',
            self::describe($log),
        ));
    }

    private static function describe(AuditLog $log): string
    {
        // The id and the action, never the reason or the context: this message
        // reaches a log and a developer's screen, and CNV-11 keeps operator
        // free text out of both.
        return sprintf('#%s [%s]', (string) ($log->getKey() ?? 'new'), $log->action->value);
    }
}
