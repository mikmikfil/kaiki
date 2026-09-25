{{--
    One page's sections, in order: the home page's and, since 2026-09-24,
    «Σχετικά με εμάς». Every partial receives what it needs already resolved by
    `BuildHomePage` — a template that queries is a template that queries once
    per block.
--}}
@foreach ($blocks as $entry)
    @include('hosted.blocks.' . str_replace('_', '-', $entry['block']->type->value), [
        'block' => $entry['block'],
        'products' => $entry['products'],
        'meetingPoint' => $entry['meetingPoint'],
        'faqs' => $entry['faqs'],
        'anchor' => $entry['anchor'],
        'links' => $entry['links'] ?? [],
        'vessels' => $entry['vessels'] ?? collect(),
        'crew' => $entry['crew'] ?? collect(),
        'credentials' => $entry['credentials'] ?? [],
        'port' => $entry['port'] ?? null,
        'pageName' => $entry['page'] ?? 'home',
    ])
@endforeach
