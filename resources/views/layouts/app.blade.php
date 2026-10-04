@php
    $embed = request('embed') === 'ide';
@endphp
<!DOCTYPE html>
<html lang="en" @if ($embed) data-ide-embed="true" @endif>
<head>
    <meta charset="UTF-8">
    {{-- A page may override it (the battlefield locks the zoom, see battlefield.blade.php) --}}
    <meta name="viewport" content="@yield('viewport', 'width=device-width, initial-scale=1.0')">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Page-specific stylesheets/fonts (e.g. the battlefield HUD's Pixelify Sans/Silkscreen link) --}}
    @stack('styles')
    @if ($embed)
        @auth
            <meta name="token-slayer-user-id" content="{{ auth()->id() }}">
        @endauth
        <meta name="token-slayer-embed-auth" content="{{ auth()->check() ? 'authed' : 'guest' }}">
        @vite('resources/js/ide-bridge.js')
    @endif
    @livewireStyles
</head>
<body class="bg-gray-50 @if ($embed) ide-embed @endif">
    @yield('content')
    @livewireScripts
</body>
</html>
