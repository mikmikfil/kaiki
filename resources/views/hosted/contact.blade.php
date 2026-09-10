{{--
    The contact page: a form on the left, the ways to reach a person on the right.

    Two columns because they are two different answers to the same question.
    Somebody who wants to ask something fills in the form; somebody who wants an
    answer in the next ninety seconds rings the number. A page that offered only
    the form would be a page that makes the second person wait for an email.

    Nothing on the right is retyped here — the phone, the email and the address
    come from `tenants`, the same row the footer and the home page read, so the
    three cannot disagree about where the boat leaves from.
--}}
@extends('hosted.layout')

@section('title', __('hosted.contact.title') . ' — ' . $tenant->name)
@section('description', __('hosted.contact.meta', ['operator' => $tenant->name]))

@section('content')
    <div class="page-head">
        <h1>{{ __('hosted.contact.title') }}</h1>
        <p class="lede">{{ __('hosted.contact.lede') }}</p>
    </div>

    <div class="contact-page">
        <div class="contact-form-card">
            @if (session('sent'))
                {{-- `status` and not `alert`: the guest pressed a button and this
                     is the answer to it, so a screen reader should read it when
                     it reaches it rather than interrupt what is being read. --}}
                <p class="sent" role="status">{{ __('hosted.contact.sent') }}</p>
            @endif

            <form method="post" action="{{ route('hosted.contact.send', ['operator' => $tenant->slug, 'lang' => $locale]) }}">
                @csrf

                @if ($about)
                    {{-- Arrived from a trip page's «any questions» card. The uuid
                         goes back with the message so the enquiry is attached to
                         the trip in the operator's panel, and the visitor is told
                         which trip it is — a hidden field that silently decides
                         what a message is about is a hidden field that will one
                         day be about the wrong thing. --}}
                    <input type="hidden" name="product_uuid" value="{{ $about->uuid }}">

                    <p class="about-trip">{{ __('hosted.contact.about', ['trip' => $about->title]) }}</p>
                @endif

                {{-- BKG-29's timing check. Stamped by the server when the page was
                     built, not by a script when it loaded: HOS-4 means there is no
                     script, and a server stamp is the harder one to forge anyway. --}}
                <input type="hidden" name="form_rendered_at" value="{{ $renderedAt }}">

                {{-- BKG-29's honeypot. Hidden from sight *and* from the
                     accessibility tree, and taken out of the tab order — a
                     screen-reader user must never be asked to fill in a field
                     whose whole purpose is that nobody fills it in.

                     `position: absolute` rather than `display: none`, because the
                     bots that matter skip anything that is `display: none`. --}}
                <div class="honey" aria-hidden="true">
                    <label for="company_website">{{ __('hosted.contact.honeypot') }}</label>
                    <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="contact_name">{{ __('hosted.contact.name') }}</label>
                        <input id="contact_name" name="name" type="text" required maxlength="120"
                               autocomplete="name" value="{{ old('name') }}">
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="contact_email">{{ __('hosted.contact.email') }}</label>
                        <input id="contact_email" name="email" type="email" required maxlength="190"
                               autocomplete="email" value="{{ old('email') }}">
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="field">
                    {{-- Optional, and it says so. An asterisk on the required
                         fields would mean reading four asterisks to find the one
                         field that does not have one. --}}
                    <label for="contact_phone">{{ __('hosted.contact.phone') }} <span class="optional">{{ __('hosted.contact.optional') }}</span></label>
                    <input id="contact_phone" name="phone" type="tel" maxlength="32"
                           autocomplete="tel" value="{{ old('phone') }}">
                    @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="contact_message">{{ __('hosted.contact.message') }}</label>
                    <textarea id="contact_message" name="message" required rows="6" minlength="5" maxlength="4000">{{ old('message') }}</textarea>
                    @error('message') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="button">{{ __('hosted.contact.submit') }}</button>

                <p class="muted privacy">
                    {{ __('hosted.contact.privacy') }}
                    <a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#privacy">{{ __('hosted.footer.privacy') }}</a>
                </p>
            </form>
        </div>

        <aside class="contact-details">
            <h2>{{ __('hosted.contact.details_heading') }}</h2>

            {{-- The same rows, the same icons and the same classes as the home
                 page's contact block, because they are the same four facts. A
                 second style for them would be a second thing to keep in step. --}}
            <ul class="contact-list">
                @if ($tenant->phone)
                    <li>
                        @include('hosted.partials.icon', ['name' => 'phone-solid'])
                        <span class="sr-only">{{ __('hosted.blocks.contact.phone') }}</span>
                        <a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a>
                    </li>
                @endif

                <li>
                    @include('hosted.partials.icon', ['name' => 'mail-solid'])
                    <span class="sr-only">{{ __('hosted.blocks.contact.email') }}</span>
                    <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>
                </li>

                @if ($tenant->address_line1)
                    @php
                        $postal = collect([$tenant->address_line1, trim($tenant->postcode . ' ' . $tenant->city)])
                            ->filter(static fn (?string $part): bool => $part !== null && trim($part) !== '')
                            ->implode(', ');
                    @endphp

                    <li>
                        @include('hosted.partials.icon', ['name' => 'pin-solid'])
                        <span class="sr-only">{{ __('hosted.blocks.contact.address') }}</span>
                        <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($postal) }}"
                           rel="noopener noreferrer"
                           target="_blank">{{ $postal }}</a>
                    </li>
                @endif
            </ul>

            @php
                /** @var array<string, string> $social */
                $social = collect((array) data_get($tenant->settings, 'social', []))
                    ->filter(static fn (mixed $url, mixed $key): bool => is_string($key) && is_string($url) && str_starts_with($url, 'https://'))
                    ->all();
            @endphp

            @if ($social !== [])
                {{-- `https://` only, checked here rather than trusted: this is a
                     value an operator types, it becomes a link on their public
                     page, and `javascript:` in an `href` is the oldest trick
                     there is. --}}
                <ul class="social">
                    @foreach ($social as $network => $url)
                        <li>
                            <a href="{{ $url }}"
                               rel="noopener noreferrer me"
                               target="_blank"
                               aria-label="{{ __('hosted.blocks.contact.social.' . $network) }}"
                               title="{{ __('hosted.blocks.contact.social.' . $network) }}">
                                @include('hosted.partials.icon', ['name' => 'social-' . $network . '-solid'])
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="muted reply">{{ __('hosted.contact.reply_time') }}</p>
        </aside>
    </div>
@endsection
