@props(['url'])
{{-- The navy masthead the public site opens with: serif wordmark over a
     letter-spaced brass tagline. No image, so it renders identically in
     clients that block remote content by default. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
<span class="mark">{{ config('mail.brand.tagline') }}</span>
</a>
</td>
</tr>
