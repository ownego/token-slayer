@extends('layouts.app')

{{-- A full-screen game: iOS Safari zooms the page in on a double tap (the field
     is tapped constantly) and when a field under 16px gets focus, leaving it
     slightly wider than the screen and panning sideways. maximum-scale=1 stops
     the zoom; viewport-fit=cover lets the arena fill a notched screen. --}}
@section('viewport', 'width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover')

@push('styles')
    {{-- HUD grid caps/numbers typefaces (resources/css/battlefield-hud.css) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@500;700&family=Silkscreen&display=swap" rel="stylesheet">
    {{-- Damage-number face (self-hosted via vite.config.js's bunny() — see
         battlefield-hud.css's --bf-num comment). Vite::fonts() emits the
         preload links + @font-face block the fonts plugin built; without
         it the manifest exists but nothing ever links it in, and --bf-num
         silently falls through to its monospace fallback. --}}
    {{ \Illuminate\Support\Facades\Vite::fonts(['press-start-2p']) }}
    @vite('resources/css/battlefield-hud.css')
    {{-- The fighter sheet's own typefaces, as the approved mockup loads them (resources/css/fighter-sheet.css) --}}
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite('resources/css/fighter-sheet.css')
@endpush

@section('content')
    @livewire('battlefield')
@endsection
