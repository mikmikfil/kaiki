{{--
    An email as the guest receives it, inside the "sent messages" feed.

    `sandbox` with no permissions: the email's styles stay inside the frame,
    and no script or link in it can run or take the panel anywhere. `srcdoc`
    rather than a URL, so there is no second route to authorise.
--}}
<div class="space-y-3">
    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-gray-500">{{ __('notifications.actions.preview.to') }}</dt>
        <dd class="font-medium">{{ $to }}</dd>
        <dt class="text-gray-500">{{ __('notifications.actions.preview.subject') }}</dt>
        <dd class="font-medium">{{ $subject }}</dd>
    </dl>

    <iframe
        sandbox
        srcdoc="{{ $html }}"
        title="{{ $subject }}"
        style="width: 100%; height: 70vh; border: 1px solid rgb(229 231 235); border-radius: .5rem; background: #fff;"
    ></iframe>
</div>
