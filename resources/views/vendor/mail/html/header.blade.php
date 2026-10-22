@props(['url'])
@php($brand = app(App\Services\Access\BrandAssets::class))
{{-- The navy masthead the public site opens with.

     The logo is a remote image, and a good share of mail clients refuse to
     load those until the reader asks. So the `alt` is the brand name rather
     than "logo": blocked, the masthead still reads as Spectrum Global
     Analytics instead of as an empty box. With no logo file at all it falls
     back to the serif wordmark, which is what this looked like before. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brand->logoUrl())
<img src="{{ $brand->logoUrl() }}"
     alt="{{ config('app.name') }}"
     width="{{ config('mail.brand.logo_width') }}"
     style="display: block; border: 0; outline: none; text-decoration: none; width: {{ config('mail.brand.logo_width') }}px; max-width: 100%; height: auto; margin: 0 auto;">
@else
{!! $slot !!}
<span class="mark">{{ config('mail.brand.tagline') }}</span>
@endif
</a>
</td>
</tr>
