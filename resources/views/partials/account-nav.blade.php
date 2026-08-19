{{-- resources/views/partials/account-nav.blade.php --}}
@php($active = $active ?? null)
<nav class="max-w-3xl mx-auto px-8 pt-6 flex items-center gap-2 text-sm font-medium">
    <a href="{{ route('profile') }}" class="{{ $active === 'profile' ? 'text-orange-600' : 'text-gray-500 hover:text-gray-900' }}">Profile</a>
    <span class="text-gray-300">·</span>
    <a href="{{ route('setup') }}" class="{{ $active === 'setup' ? 'text-orange-600' : 'text-gray-500 hover:text-gray-900' }}">Setup</a>
    <span class="text-gray-300">·</span>
    <a href="{{ route('guide') }}" class="{{ $active === 'guide' ? 'text-orange-600' : 'text-gray-500 hover:text-gray-900' }}">Guide</a>
</nav>
