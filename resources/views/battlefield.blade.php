@extends('layouts.app')

@push('styles')
    {{-- HUD grid caps/numbers typefaces (resources/css/battlefield-hud.css) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@500;700&family=Silkscreen&display=swap" rel="stylesheet">
    @vite('resources/css/battlefield-hud.css')
    {{-- The fighter sheet's own typefaces, as the approved mockup loads them (resources/css/fighter-sheet.css) --}}
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite('resources/css/fighter-sheet.css')
@endpush

@section('content')
    @livewire('battlefield')
@endsection
