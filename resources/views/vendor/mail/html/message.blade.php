{{-- The masthead and footer link to the site a reader can actually open, not
     to APP_URL — this backend is API-only and serves no page. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('mail.brand.url') ?: config('frontend.subscriber_url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}

@if (filled(config('mail.brand.support_email')))
Questions? Write to [{{ config('mail.brand.support_email') }}](mailto:{{ config('mail.brand.support_email') }}).
@endif

This message was sent to you because you hold an account with {{ config('app.name') }}.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
