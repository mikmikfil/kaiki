{{--
    «Άδειες και ασφάλεια» (2026-09-24): the business's own details and its
    boats' licences, from the tenant record and «Σκάφη». The operator writes
    the heading and the sentence above them (insurance, say); the numbers are
    never typed here, so they cannot disagree with the invoice.
--}}
@php
    use App\Enums\VesselLicence;

    $lines = [];

    foreach ($credentials['licences'] ?? [] as $licence => $count) {
        $type = VesselLicence::tryFrom((string) $licence);

        if ($type !== null) {
            $lines[] = ['icon' => 'shield', 'title' => $type->label(), 'text' => trans_choice('hosted.about.credentials.boats', $count, ['count' => $count])];
        }
    }

    $tax = collect([
        ($credentials['vat_number'] ?? null) ? __('hosted.about.credentials.vat', ['number' => $credentials['vat_number']]) : null,
        $credentials['tax_office'] ?? null,
    ])->filter()->implode(' · ');

    if (($credentials['legal_name'] ?? '') !== '') {
        $lines[] = ['icon' => 'anchor', 'title' => $credentials['legal_name'], 'text' => $tax];
    }

    if ($credentials['gemi_number'] ?? null) {
        $lines[] = ['icon' => 'check', 'title' => __('hosted.about.credentials.gemi'), 'text' => $credentials['gemi_number']];
    }
@endphp

@if ($lines !== [] || $block->heading)
    <section class="block credentials-block">
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => $block->body ? $block->prose() : null,
        ])

        @if ($lines !== [])
            <ul class="credentials">
                @foreach ($lines as $line)
                    <li>
                        @include('hosted.partials.icon', ['name' => $line['icon']])
                        <div>
                            <strong>{{ $line['title'] }}</strong>
                            @if ($line['text'] !== '')
                                <span class="credential-number">{{ $line['text'] }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
