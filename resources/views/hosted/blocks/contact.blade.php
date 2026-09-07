{{--
    How to reach the operator, from the operator's own record.

    Nothing here is retyped into the block. The phone number, the email and the
    address come from `tenants`, and the meeting point is a `ports` row — so
    this block cannot disagree with the footer, the product pages or the
    confirmation email about where the boat leaves from.
--}}
<section class="block contact @if ($block->image_path) has-image @endif" @if ($anchor) id="{{ $anchor }}" @endif>
    @if ($block->image_path)
        {{-- A banner behind the details, not a picture beside them. Decorative:
             everything it could say is said in words below it, and a screen
             reader reading "boat at a quay" before a telephone number is noise
             in front of the one thing somebody came here for. --}}
        <img class="contact-image"
             src="{{ \App\Domain\Hosted\Support\HostedAsset::url($block->image_path) }}"
             alt=""
             loading="lazy">
    @endif

    {{-- Two columns on a wide screen: what to say on the left, how to reach
         them on the right. One wrapper rather than two, so the grid has
         something to be a grid of. --}}
    <div class="contact-inner">
        <div>
            @if ($block->heading)
                <h2>{{ $block->heading }}</h2>
            @endif

            @if ($block->body)
                <div class="prose">{{ $block->prose() }}</div>
            @endif

            {{-- Something to press. An address panel with no action in it asks a
                 visitor to copy an email address by hand, on a phone, which is
                 the point at which they give up. --}}
            @if ($tenant->email || $tenant->phone)
                <p class="contact-actions">
                    @if ($tenant->email)
                        <a class="button" href="mailto:{{ $tenant->email }}">{{ __('hosted.blocks.contact.write') }}</a>
                    @endif

                    @if ($tenant->phone)
                        <a class="button ghost" href="tel:{{ $tenant->phone }}">{{ __('hosted.blocks.contact.call') }}</a>
                    @endif
                </p>
            @endif
        </div>

        <ul class="contact-list">
            @if ($block->setting('show_phone') && $tenant->phone)
                <li>
                <span class="label">{{ __('hosted.blocks.contact.phone') }}</span>
                <a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a>
                </li>
            @endif

            @if ($block->setting('show_email') && $tenant->email)
                <li>
                <span class="label">{{ __('hosted.blocks.contact.email') }}</span>
                <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>
                </li>
            @endif

            @if ($block->setting('show_address') && $tenant->address_line1)
                <li>
                <span class="label">{{ __('hosted.blocks.contact.address') }}</span>
                <span>{{ trim($tenant->address_line1 . ' ' . $tenant->postcode . ' ' . $tenant->city) }}</span>
                </li>
            @endif

            @if ($meetingPoint)
                <li>
                <span class="label">{{ __('hosted.blocks.contact.meeting_point') }}</span>
                <span>
                    {{ $meetingPoint->name }}
                    @if ($meetingPoint->mapsUrl())
                        — <a href="{{ $meetingPoint->mapsUrl() }}" rel="noopener noreferrer">{{ __('hosted.blocks.contact.open_in_maps') }}</a>
                    @endif
                </span>
                @if ($meetingPoint->instructions)
                    <span class="instructions">{{ $meetingPoint->instructions }}</span>
                @endif
                </li>
            @endif
        </ul>
    </div>
</section>
