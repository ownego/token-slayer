@php
    $embed = request('embed') === 'ide';
@endphp
<!DOCTYPE html>
<html lang="en" @if ($embed) data-ide-embed="true" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
