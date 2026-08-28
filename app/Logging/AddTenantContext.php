<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Monolog tap that installs {@see TenantContextProcessor} on a channel.
 *
 * Registered in `config/logging.php` rather than pushed from a service
 * provider, so which channels carry tenant context is visible in configuration
 * instead of buried in boot code.
 */
final class AddTenantContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new TenantContextProcessor);
    }
}
