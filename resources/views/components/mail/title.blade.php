{{-- Titre d'un e-mail, avec son filet de couleur (info, success, warning, danger). --}}
@props(['tone' => 'info'])
@php $accent = ['info' => '#2340D9', 'success' => '#12A06B', 'warning' => '#FFB400', 'danger' => '#D6342A'][$tone] ?? '#2340D9'; @endphp
<h1 style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.3;color:#0B1340;">{{ $slot }}</h1>
<div style="width:44px;height:3px;border-radius:3px;background:{{ $accent }};margin:12px 0 22px;font-size:0;line-height:0;">&nbsp;</div>
