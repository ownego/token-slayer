{{-- resources/views/partials/account-nav.blade.php --}}
{{-- Setup and Guide are the only standalone pages left; the profile lives in
     the battlefield's own fighter sheet, so these lead back to the battlefield
     and the dashboard. A link to another page opens in a new tab, so nobody
     loses a build in progress or a running battlefield; the page you are on is
     not a link. --}}
@php($active = $active ?? null)
@php($link = 'inline-flex min-h-11 items-center')
<nav class="max-w-3xl mx-auto px-4 sm:px-8 pt-6 flex flex-wrap items-center gap-2 text-sm font-medium">
    <a href="{{ route('battlefield') }}" target="_blank" rel="noopener" class="{{ $link }} rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Battlefield</a>
    <a href="{{ route('filament.admin.pages.dashboard') }}" target="_blank" rel="noopener" class="{{ $link }} rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Dashboard</a>
    <span class="text-gray-300">·</span>
    @if ($active === 'setup')
        <span class="{{ $link }} text-orange-600" aria-current="page">Setup</span>
    @else
        <a href="{{ route('setup') }}" target="_blank" rel="noopener" class="{{ $link }} text-gray-500 hover:text-gray-900">Setup</a>
    @endif
    <span class="text-gray-300">·</span>
    @if ($active === 'guide')
        <span class="{{ $link }} text-orange-600" aria-current="page">Guide</span>
    @else
        <a href="{{ route('guide') }}" target="_blank" rel="noopener" class="{{ $link }} text-gray-500 hover:text-gray-900">Guide</a>
    @endif
    <span class="text-gray-300">·</span>
    @if ($active === 'character')
        <span class="{{ $link }} text-orange-600" aria-current="page">Character</span>
    @else
        <a href="{{ route('custom-character') }}" target="_blank" rel="noopener" class="{{ $link }} text-gray-500 hover:text-gray-900">Character</a>
    @endif
</nav>
