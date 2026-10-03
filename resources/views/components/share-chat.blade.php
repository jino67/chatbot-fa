{{--
    Partager le lien de discussion d'un assistant : copier, WhatsApp, Facebook, e-mail, SMS, partage du téléphone, QR code et affiche.
    variant « card » : la carte complète (page Canaux) ; « menu » : le même contenu allégé, dans le menu « Partager » de l'en-tête.
--}}
@props(['bot', 'variant' => 'card'])
@php
    $url = $bot->chatUrl();
    $company = $bot->company();
    $message = "Discutez avec {$company} : posez vos questions, la réponse est immédiate.";
    $payload = [
        'url' => $url,
        'message' => $message,
        'title' => $company,
        'wa' => 'https://wa.me/?text='.rawurlencode($message."\n".$url),
        'fb' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url),
        'tg' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode($message),
        'mail' => 'mailto:?subject='.rawurlencode('Discutez avec '.$company).'&body='.rawurlencode($message."\n\n".$url),
        'sms' => 'sms:?&body='.rawurlencode($message.' '.$url),
        'file' => 'qr-'.\Illuminate\Support\Str::slug($company ?: 'assistant'),
    ];
    $card = $variant === 'card';
@endphp
<div x-data="shareChat(@js($payload))" {{ $attributes->merge(['class' => $card ? 'space-y-5' : 'space-y-4']) }}>
    <div>
        <label for="share-url-{{ $variant }}" class="text-sm font-medium text-slate-700">Votre lien de discussion</label>
        <div class="mt-1.5 flex gap-2">
            <input id="share-url-{{ $variant }}" x-ref="url" type="text" readonly value="{{ $url }}" @focus="$event.target.select()" class="field !mt-0 min-w-0 flex-1 truncate font-mono text-xs">
            <button type="button" @click="copy()" class="btn-primary shrink-0" x-text="copied ? 'Copié' : 'Copier'">Copier</button>
        </div>
        @if ($card)
            <p class="mt-1.5 text-xs text-slate-500">Vos clients l'ouvrent sur leur téléphone : rien à installer. Mettez-le sur votre statut WhatsApp, votre page Facebook, votre carte de visite, vos factures.</p>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-2 {{ $card ? 'sm:grid-cols-3' : '' }}">
        <a :href="links.wa" target="_blank" rel="noopener" class="share-btn"><svg viewBox="0 0 24 24" class="h-4 w-4 text-[#25D366]" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.78h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.24-6.17-3.48-8.41"/></svg> WhatsApp</a>
        <a :href="links.fb" target="_blank" rel="noopener" class="share-btn"><svg viewBox="0 0 24 24" class="h-4 w-4 text-[#1877F2]" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07"/></svg> Facebook</a>
        <a :href="links.mail" class="share-btn"><x-icon name="inbox" class="h-4 w-4 text-slate-500" /> E-mail</a>
        <a :href="links.sms" class="share-btn"><x-icon name="chat" class="h-4 w-4 text-slate-500" /> SMS</a>
        <a :href="links.tg" target="_blank" rel="noopener" class="share-btn"><x-icon name="external" class="h-4 w-4 text-slate-500" /> Telegram</a>
        <button type="button" x-show="canShare" x-cloak @click="nativeShare()" class="share-btn"><x-icon name="link" class="h-4 w-4 text-slate-500" /> Autres…</button>
    </div>

    @if ($card)
        <div class="grid gap-5 rounded-xl bg-slate-50 p-5 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
            <div class="mx-auto w-40 overflow-hidden rounded-lg bg-white p-1.5 ring-1 ring-slate-200 sm:mx-0" x-ref="qr" aria-label="QR code du lien de discussion">{!! \App\Support\QrCode::svg($url) !!}</div>
            <div class="space-y-3 text-sm text-slate-600">
                <p><strong class="text-brand-950">Un QR code pour votre boutique.</strong> Vos clients le scannent avec leur téléphone et la discussion s'ouvre. Collez-le sur votre vitrine, votre comptoir, vos emballages.</p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('share.poster', $bot) }}" target="_blank" rel="noopener" class="btn-primary text-sm">Affiche à imprimer</a>
                    <button type="button" @click="downloadPng()" class="btn-outline text-sm">QR en PNG</button>
                    <a href="{{ route('share.qr', [$bot, 'telecharger' => 1]) }}" class="btn-outline text-sm">QR en SVG</a>
                </div>
                @if ($bot->whatsappNumber())
                    <p class="text-xs text-slate-500">Votre numéro WhatsApp est actif : l'affiche peut aussi pointer directement vers WhatsApp (<a class="underline" href="{{ route('share.poster', [$bot, 'cible' => 'whatsapp']) }}" target="_blank" rel="noopener">affiche WhatsApp</a>).</p>
                @endif
            </div>
        </div>
    @else
        <a href="{{ route('channels.show', $bot) }}#partager" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:text-brand-900">QR code et affiche à imprimer <x-icon name="arrow-right" class="h-3.5 w-3.5" /></a>
    @endif
</div>

@once
    <style>
        .share-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; border-radius: .75rem; background: #fff; padding: .55rem .75rem; font-size: .875rem; font-weight: 500; color: #1e293b; box-shadow: inset 0 0 0 1px #cbd5e1; transition: background-color .15s; }
        .share-btn:hover { background: #f1f5f9; }
    </style>
    <script>
        function shareChat(p) {
            return {
                copied: false,
                canShare: typeof navigator.share === 'function',
                links: { wa: p.wa, fb: p.fb, mail: p.mail, sms: p.sms, tg: p.tg },
                async copy() {
                    try { await navigator.clipboard.writeText(p.url); } catch (e) { this.$refs.url.select(); document.execCommand('copy'); }
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1800);
                },
                async nativeShare() {
                    try { await navigator.share({ title: p.title, text: p.message, url: p.url }); } catch (e) { /* partage annulé */ }
                },
                // Le QR est déjà dans la page en SVG : on le dessine sur un canvas pour en faire un PNG net (1 024 px).
                downloadPng() {
                    const svg = this.$refs.qr.querySelector('svg');
                    const image = new Image();
                    image.onload = () => {
                        const canvas = document.createElement('canvas');
                        canvas.width = canvas.height = 1024;
                        const context = canvas.getContext('2d');
                        context.imageSmoothingEnabled = false;
                        context.drawImage(image, 0, 0, 1024, 1024);
                        const link = document.createElement('a');
                        link.download = p.file + '.png';
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                    };
                    image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
                },
            };
        }
    </script>
@endonce
