{{-- Bouton d'e-mail : un tableau, pour que la couleur et les coins arrondis tiennent aussi dans Outlook. --}}
@props(['url', 'tone' => 'primary'])
@php $colors = $tone === 'light' ? ['#EEF1FE', '#2340D9'] : ['#2340D9', '#ffffff']; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-block;margin:6px 8px 6px 0;">
    <tr>
        <td align="center" bgcolor="{{ $colors[0] }}" style="border-radius:999px;background:{{ $colors[0] }};">
            <a href="{{ $url }}" style="display:inline-block;padding:13px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:{{ $colors[1] }};text-decoration:none;border-radius:999px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
