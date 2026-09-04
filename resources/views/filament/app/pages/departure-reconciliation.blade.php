<x-filament-panels::page>
    {{--
        Derived on read (see DepartureReconciler), so an operator who fixes a
        rule sees the row disappear on the next load with nothing having to
        remember to delete it.
    --}}
    @if (count($this->getIssues()) === 0)
        <x-filament::section>
            <p class="text-sm">{{ __('availability.reconciliation.empty') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm">{{ __('availability.reconciliation.intro') }}</p>
        </x-filament::section>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-start p-2">{{ __('availability.reconciliation.table.product') }}</th>
                        <th class="text-start p-2">{{ __('availability.reconciliation.table.local_date') }}</th>
                        <th class="text-start p-2">{{ __('availability.reconciliation.table.kind') }}</th>
                        <th class="text-start p-2">{{ __('availability.reconciliation.table.detail') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getIssues() as $issue)
                        <tr>
                            <td class="p-2">{{ $issue['product'] }}</td>
                            <td class="p-2">{{ $issue['local_date'] }}</td>
                            <td class="p-2">
                                <strong>{{ $issue['kind'] }}</strong><br>
                                <span class="text-xs">{{ $issue['explanation'] }}</span>
                            </td>
                            <td class="p-2">{{ $issue['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
