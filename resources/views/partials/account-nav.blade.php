{{-- resources/views/partials/account-nav.blade.php --}}
{{-- Setup and Guide are the only standalone pages left; the profile lives in
     the battlefield's own fighter sheet, so these lead back to the battlefield
     and the dashboard. --}}
@php($active = $active ?? null)
<nav class="max-w-3xl mx-auto px-8 pt-6 flex items-center gap-2 text-sm font-medium">
    <a href="{{ route('battlefield') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Battlefield</a>
    <a href="{{ route('filament.admin.pages.dashboard') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600">Dashboard</a>
    <span class="text-gray-300">·</span>
    <a href="{{ route('setup') }}" class="{{ $active === 'setup' ? 'text-orange-600' : 'text-gray-500 hover:text-gray-900' }}">Setup</a>
    <span class="text-gray-300">·</span>
    <a href="{{ route('guide') }}" class="{{ $active === 'guide' ? 'text-orange-600' : 'text-gray-500 hover:text-gray-900' }}">Guide</a>
</nav>
