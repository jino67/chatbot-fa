{{-- Un petit tableau « libellé : valeur » (espace, offre, montant, référence...). --}}
@props(['items'])
@php $items = array_filter((array) $items, fn ($v) => $v !== null && $v !== ''); @endphp
@if (count($items))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;background:#F6F8FD;border-radius:12px;">
        @foreach ($items as $label => $value)
            <tr>
                <td style="padding:{{ $loop->first ? '14px' : '4px' }} 4px {{ $loop->last ? '14px' : '4px' }} 18px;width:38%;font-family:Arial,Helvetica,sans-serif;font-size:13.5px;color:#6B7280;vertical-align:top;">{{ $label }}</td>
                <td style="padding:{{ $loop->first ? '14px' : '4px' }} 18px {{ $loop->last ? '14px' : '4px' }} 4px;font-family:Arial,Helvetica,sans-serif;font-size:14.5px;color:#0B1340;font-weight:bold;vertical-align:top;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
@endif
