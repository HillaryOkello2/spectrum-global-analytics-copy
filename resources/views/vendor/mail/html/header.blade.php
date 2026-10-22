@props(['url'])
@php($brand = app(App\Services\Access\BrandAssets::class))
{{-- The masthead.

     The logo is a remote image, and a good share of mail clients refuse to
     load those until the reader asks. Two things make that bearable:

     - `alt` is the brand name rather than "logo", so a blocked image still
       reads as Spectrum Global Analytics, styled to match the wordmark it
       stands in for.
     - width AND height are both declared. With only a width, a client that
       cannot load the image reserves a large empty square for it; with both,
       it reserves exactly the strip the logo occupies and puts the alt text
       in it.

     With no logo file at all this falls back to the serif wordmark, which is
     what the masthead looked like before the logo existed. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brand->logoUrl())
<img src="{{ $brand->logoUrl() }}"
     alt="{{ config('app.name') }}"
     width="{{ $brand->logoWidth() }}"
     height="{{ $brand->logoHeight() }}"
     style="display: block; border: 0; outline: none; text-decoration: none; margin: 0 auto; width: {{ $brand->logoWidth() }}px; height: {{ $brand->logoHeight() }}px; max-width: 100%; color: #10172d; font-family: Georgia, Cambria, 'Times New Roman', Times, serif; font-size: 20px; line-height: {{ $brand->logoHeight() }}px;">
@else
{!! $slot !!}
<span class="mark">{{ config('mail.brand.tagline') }}</span>
@endif
</a>
</td>
</tr>
