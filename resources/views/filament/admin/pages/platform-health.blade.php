{{--
    «Υγεία πλατφόρμας» (spec SAA-17).

    Three blocks, in the order something goes wrong: the queue (is anything
    being done at all), the failed jobs (what was tried and gave up), and the
    operators with an open failure (who is affected). Polls every minute —
    this is the screen left open while something is being fixed.

    A scoped `<style>` rather than utility classes: Filament's CSS is
    precompiled and the grid here is not guaranteed to be in it. No uppercase
    anywhere (I18N-2), and no hardcoded strings (`NoHardcodedStringsTest`).
--}}
<x-filament-panels::page>
    @php
        $report = $this->report();
        $queue = $report->queue();
        $failedTotal = $report->failedJobsTotal();
        $byClass = $report->failedJobsByClass();
        $recent = $report->recentFailedJobs();
        $tenants = $report->perTenant();
        $orphans = $report->orphanGatewayWebhooks();
    @endphp

    <div class="ka-health" wire:poll.60s>
        <x-filament::section :heading="__('platform.health.queue.heading')">
            <dl class="ka-health-figures">
                <div>
                    <dt>{{ __('platform.health.queue.depth') }}</dt>
                    <dd>{{ $queue['depth'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('platform.health.queue.reserved') }}</dt>
                    <dd>{{ $queue['reserved'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('platform.health.queue.oldest') }}</dt>
                    <dd>{{ $this->age($queue['oldest_age_seconds']) ?? __('platform.health.none') }}</dd>
                </div>
                <div>
                    <dt>{{ __('platform.health.queue.driver') }}</dt>
                    <dd class="ka-health-mono">{{ $queue['driver'] }}</dd>
                </div>
            </dl>

            <p class="ka-health-hint">{{ __('platform.health.queue.worker_hint') }}</p>
        </x-filament::section>

        <x-filament::section
            :heading="__('platform.health.failed.heading')"
            :description="trans_choice('platform.health.failed.total', $failedTotal, ['count' => $failedTotal])"
        >
            @if ($failedTotal === 0)
                <p class="ka-health-empty">{{ __('platform.health.failed.none') }}</p>
            @else
                <h3 class="ka-health-sub">{{ __('platform.health.failed.by_class') }}</h3>

                <table class="ka-health-table">
                    <thead>
                        <tr>
                            <th>{{ __('platform.health.failed.class') }}</th>
                            <th class="ka-num">{{ __('platform.health.failed.count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byClass as $class => $count)
                            <tr>
                                <td class="ka-health-mono" title="{{ $class }}">{{ $this->shortClass($class) }}</td>
                                <td class="ka-num">{{ $count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($failedTotal > \App\Domain\Platform\Support\PlatformHealthReport::FAILED_JOB_SAMPLE)
                    <p class="ka-health-hint">{{ __('platform.health.failed.sample', ['count' => \App\Domain\Platform\Support\PlatformHealthReport::FAILED_JOB_SAMPLE]) }}</p>
                @endif

                <h3 class="ka-health-sub">{{ __('platform.health.failed.recent') }}</h3>

                <div class="ka-health-scroll">
                    <table class="ka-health-table">
                        <thead>
                            <tr>
                                <th>{{ __('platform.health.failed.when') }}</th>
                                <th>{{ __('platform.health.failed.class') }}</th>
                                <th>{{ __('platform.health.failed.error') }}</th>
                                <th>{{ __('platform.health.failed.uuid') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $job)
                                <tr>
                                    <td class="ka-nowrap">{{ $job['failed_at']->diffForHumans() }}</td>
                                    <td class="ka-health-mono">{{ $this->shortClass($job['class']) }}</td>
                                    <td class="ka-health-error">{{ $job['error'] }}</td>
                                    {{-- Wraps rather than being clipped: the whole uuid
                                         is what `queue:retry` needs, so a cut one is useless. --}}
                                    <td class="ka-health-mono ka-health-uuid">{{ $job['uuid'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="ka-health-hint">{{ __('platform.health.failed.retry_hint') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section
            :heading="__('platform.health.tenants.heading')"
            :description="__('platform.health.tenants.help', ['threshold' => \App\Models\IcalSource::ATTENTION_THRESHOLD])"
        >
            @if ($tenants === [])
                <p class="ka-health-empty">{{ __('platform.health.tenants.none') }}</p>
            @else
                <div class="ka-health-scroll">
                    <table class="ka-health-table">
                        <thead>
                            <tr>
                                <th>{{ __('platform.health.tenants.operator') }}</th>
                                <th class="ka-num">{{ __('platform.health.tenants.mydata') }}</th>
                                <th class="ka-num">{{ __('platform.health.tenants.gateway') }}</th>
                                <th class="ka-num">{{ __('platform.health.tenants.ical') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tenants as $row)
                                <tr>
                                    <td>
                                        @if ($row['tenant_uuid'])
                                            <a href="{{ $this->tenantUrl($row['tenant_uuid']) }}" class="ka-health-link">{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </td>
                                    <td class="ka-num">{{ $row['mydata'] ?: '·' }}</td>
                                    <td class="ka-num">{{ $row['gateway_webhooks'] ?: '·' }}</td>
                                    <td class="ka-num">{{ $row['ical'] ?: '·' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($orphans > 0)
                <p class="ka-health-warn">{{ trans_choice('platform.health.tenants.orphans', $orphans, ['count' => $orphans]) }}</p>
            @endif
        </x-filament::section>
    </div>

    <style>
        .ka-health { display: flex; flex-direction: column; gap: 1.5rem; }

        .ka-health-figures {
            display: grid; gap: 1rem; margin: 0;
            grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
        }

        .ka-health-figures dt { font-size: .8125rem; color: rgb(var(--gray-500)); }
        .ka-health-figures dd { margin: .2rem 0 0; font-size: 1.6rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .ka-health-figures dd.ka-health-mono { font-size: 1rem; padding-top: .45rem; }

        .ka-health-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; }

        .ka-health-hint { margin-top: .85rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
        .ka-health-empty { font-size: .875rem; color: rgb(var(--gray-500)); }
        .ka-health-warn { margin-top: .85rem; font-size: .875rem; font-weight: 600; color: rgb(var(--danger-600)); }

        .ka-health-sub { margin: 0 0 .5rem; font-size: .875rem; font-weight: 600; }
        .ka-health-sub:not(:first-child) { margin-top: 1.25rem; }

        .ka-health-scroll { overflow-x: auto; }

        /* An error line carries a Windows or Linux path with no spaces, which
           would otherwise hold the table wider than its card and push the
           uuid column out of sight. Both break anywhere; the uuid keeps enough
           width to read in two lines rather than five. */
        .ka-health-error { overflow-wrap: anywhere; }
        .ka-health-uuid { overflow-wrap: anywhere; min-width: 19ch; }

        .ka-health-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ka-health-table th {
            text-align: left; font-weight: 500; font-size: .8125rem;
            color: rgb(var(--gray-500));
            padding: .4rem .75rem .4rem 0;
            border-bottom: 1px solid rgba(var(--gray-950), .08);
        }
        .ka-health-table td {
            padding: .45rem .75rem .45rem 0; vertical-align: top;
            border-bottom: 1px solid rgba(var(--gray-950), .05);
        }
        .ka-health-table .ka-num { text-align: right; font-variant-numeric: tabular-nums; }
        .ka-nowrap { white-space: nowrap; }

        .ka-health-link { color: rgb(var(--primary-600)); font-weight: 500; }
        .ka-health-link:hover { color: rgb(var(--primary-500)); }

        .dark .ka-health-table th, .dark .ka-health-table td { border-bottom-color: rgba(255, 255, 255, .08); }
        .dark .ka-health-link { color: rgb(var(--primary-400)); }
    </style>
</x-filament-panels::page>
