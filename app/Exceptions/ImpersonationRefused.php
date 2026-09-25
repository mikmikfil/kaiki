<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Tenancy\Actions\StartImpersonation;
use RuntimeException;

/**
 * A request to sign in as an operator's person was refused (TEN-7, SAA-2).
 *
 * Thrown from {@see StartImpersonation}, which is the only way in — so the same
 * four refusals apply whether the request came from the merchant list, a
 * console command or a future API.
 *
 * Each named constructor is one refusal rather than one message, for the reason
 * {@see UploadRefused} gives: a super-admin who picked a person from the wrong
 * operator and one who left the reason box empty have made different mistakes,
 * and «δεν επιτρέπεται» tells neither of them what to do next.
 *
 * Messages come from `lang/*\/tenants.php` (CNV-11), never from a literal here.
 */
final class ImpersonationRefused extends RuntimeException
{
    public static function notPermitted(): self
    {
        return new self(__('tenants.impersonation.refused.not_permitted'));
    }

    /**
     * The target belongs to a different operator.
     *
     * The one refusal that is a tenancy boundary rather than a policy: TEN-4
     * scopes every query to one tenant, and an impersonation that crossed
     * operators would hand somebody a session inside an account the form never
     * named. Checked against the tenant rather than trusted from the request,
     * because the user id arrives from a form.
     */
    public static function wrongTenant(): self
    {
        return new self(__('tenants.impersonation.refused.wrong_tenant'));
    }

    public static function noReason(): self
    {
        return new self(__('tenants.impersonation.refused.no_reason'));
    }

    /**
     * A super-admin asked to become themselves.
     *
     * Refused rather than ignored: it would write an audit row saying the
     * platform owner impersonated the platform owner, and leave a session that
     * looks impersonated with no way back that means anything.
     */
    public static function yourself(): self
    {
        return new self(__('tenants.impersonation.refused.yourself'));
    }
}
