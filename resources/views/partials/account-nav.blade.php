{{-- resources/views/partials/account-nav.blade.php --}}
{{-- Outer pages share same-tab navigation. The current page is plain text. --}}
@php($active = $active ?? null)
@php($link = 'inline-flex min-h-11 items-center')
<nav class="max-w-3xl mx-auto px-4 sm:px-8 pt-6 flex flex-wrap items-center gap-2 text-sm font-medium">
    <a href="{{ route('battlefield') }}" class="{{ $link }} rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Battlefield</a>
    <a href="{{ route('filament.admin.pages.dashboard') }}" class="{{ $link }} rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Dashboard</a>
    <span class="text-gray-300">·</span>
    @if ($active === 'setup')
        <span class="{{ $link }} text-orange-600" aria-current="page">Setup</span>
    @else
        <a href="{{ route('setup') }}" class="{{ $link }} text-gray-500 hover:text-gray-900">Setup</a>
    @endif
    <span class="text-gray-300">·</span>
    @if ($active === 'guide')
        <span class="{{ $link }} text-orange-600" aria-current="page">Guide</span>
    @else
        <a href="{{ route('guide') }}" class="{{ $link }} text-gray-500 hover:text-gray-900">Guide</a>
    @endif
    <span class="text-gray-300">·</span>
    @if ($active === 'character')
        <span class="{{ $link }} text-orange-600" aria-current="page">Character</span>
    @else
        <a href="{{ route('custom-character') }}" class="{{ $link }} text-gray-500 hover:text-gray-900">Character</a>
    @endif
</nav>
