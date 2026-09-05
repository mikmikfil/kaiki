<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\ApiKey;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A key was revoked (SEC-16, and the action that prompted ADR-0025).
 *
 * #10 satisfied SEC-16 here with a structured `Log::info` and said in its own
 * review that the defence would expire. ADR-0025's first complaint about that
 * line is the one this event answers: a revocation is *"precisely when"* an
 * operator needs to see their own team's actions, and grepping a file on a
 * Hetzner box is not seeing them.
 *
 * The **prefix** is the label, never the key. A `pk_live_` prefix identifies
 * which key without being one — SEC-3 keeps the secret out of everything that
 * is not a hash, and this table is kept for seven years.
 */
final class ApiKeyRevoked implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly ApiKey $key,
        private readonly ?string $reason = null,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::ApiKeyRevoked,
            subject: $this->key,
            label: $this->key->prefix,
            reason: $this->reason,
            context: [
                'environment' => $this->key->environment->value,
                'type' => $this->key->type->value,
            ],
        );
    }
}
