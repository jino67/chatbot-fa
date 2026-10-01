{{--
    Gabarit de tous les e-mails de la plateforme : bandeau marine avec la marque, filet safran, carte blanche, pied de page avec
    la signature. Écrit en tableaux et styles en ligne : c'est ce que lisent Gmail, Outlook et les messageries des téléphones.
    Props : preheader (aperçu dans la boîte de réception), reason (pourquoi on reçoit ce message), settings (lien vers les préférences),
    tone (info, success, warning, danger : couleur du filet sous le titre).
--}}
@props(['preheader' => null, 'reason' => null, 'settings' => true, 'tone' => 'info'])
@php
    $name = $brand['name'] ?? 'Kouma';
    $site = rtrim($brand['url'] ?? url('/'), '/');
    $host = preg_replace('#^https?://#', '', $site);
    $accent = ['info' => '#2340D9', 'success' => '#12A06B', 'warning' => '#FFB400', 'danger' => '#D6342A'][$tone] ?? '#2340D9';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $name }}</title>
    <style>
        body { margin: 0; padding: 0; background: #F1F4FB; }
        a { color: #2340D9; }
        p { margin: 0 0 14px; }
        @media only screen and (max-width: 620px) {
            .wrap { padding: 12px 8px !important; }
            .card { padding: 24px 20px !important; }
            .head { padding: 18px 20px !important; }
            .foot { padding: 18px 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#F1F4FB;">
    @if ($preheader)
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">{{ $preheader }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F1F4FB;">
        <tr>
            <td align="center" class="wrap" style="padding:28px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
                    {{-- Bandeau --}}
                    <tr>
                        <td class="head" style="background:#101B5B;border-radius:16px 16px 0 0;padding:22px 32px;">
                            <a href="{{ $site }}" style="text-decoration:none;color:#ffffff;">
                                <img src="{{ url('email-mark.png') }}" width="30" height="30" alt="" style="display:inline-block;vertical-align:middle;border:0;margin-right:8px;">
                                <span style="display:inline-block;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;font-size:24px;font-weight:bold;letter-spacing:-0.5px;color:#ffffff;">{{ mb_strtolower($name) }}</span>
                            </a>
                        </td>
                    </tr>
                    <tr><td style="height:4px;line-height:4px;font-size:0;background:#FFB400;">&nbsp;</td></tr>

                    {{-- Carte --}}
                    <tr>
                        <td class="card" style="background:#ffffff;padding:34px 38px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#1F2937;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Pied de page --}}
                    <tr>
                        <td class="foot" style="background:#ffffff;border-top:1px solid #E5E9F5;border-radius:0 0 16px 16px;padding:22px 38px 26px;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.6;color:#6B7280;">
                            <p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#2340D9;font-style:italic;">{{ \App\Support\Guides::SIGNATURE }}</p>
                            <p style="margin:0 0 10px;">L'équipe {{ $name }}@if (! empty($brand['tagline'])) : {{ $brand['tagline'] }}@endif</p>
                            <p style="margin:0 0 10px;">
                                <a href="{{ $site }}" style="color:#6B7280;">{{ $host }}</a>
                                @if (! empty($brand['email'])) &nbsp;|&nbsp; <a href="mailto:{{ $brand['email'] }}" style="color:#6B7280;">{{ $brand['email'] }}</a> @endif
                            </p>
                            @if ($reason)
                                <p style="margin:0 0 6px;color:#9CA3AF;">{{ $reason }}</p>
                            @endif
                            @if ($settings && \Illuminate\Support\Facades\Route::has('notifications.preferences'))
                                <p style="margin:0;color:#9CA3AF;"><a href="{{ route('notifications.preferences') }}" style="color:#9CA3AF;">Choisir les messages que vous recevez</a></p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
