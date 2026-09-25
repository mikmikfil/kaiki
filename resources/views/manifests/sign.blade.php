{{--
    The captain's block at the foot of the passenger list (ν. 4926/2022 άρθρο
    13, 2026-09-24): name, signature, and the date and time it was signed —
    written by hand at the quay, so they are lines, not values. The captain's
    name is printed when the boat has one on record, with the line kept beside
    it for whoever actually takes her out.
--}}
<div class="signblock">
    <div class="signblock-title">{{ __('manifest.sign.captain') }}</div>
    <div class="signblock-row">
        <span>{{ __('manifest.sign.name') }}</span>
        <span class="line">{{ $manifest->header['captain'] ?? '' }}</span>
    </div>
    <div class="signblock-row">
        <span>{{ __('manifest.sign.signature') }}</span>
        <span class="line"></span>
    </div>
    <div class="signblock-row">
        <span>{{ __('manifest.sign.at') }}</span>
        <span class="line"></span>
    </div>
</div>
