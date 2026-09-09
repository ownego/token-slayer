{{-- resources/views/update.blade.php --}}
@extends('layouts.app')

@section('content')
    <div x-data="{ platform: null, copied: null, copy(id, text) {
        navigator.clipboard && navigator.clipboard.writeText(text).catch(() => {});
        this.copied = id;
        clearTimeout(this._copyTimer);
        this._copyTimer = setTimeout(() => { this.copied = null; }, 1300);
    } }">
        @include('partials.account-nav', ['active' => 'setup'])

        <div class="max-w-3xl mx-auto p-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Update token-slayer</h1>
            <p class="text-sm text-gray-500 mb-6">You already have it installed — pick your platform and re-run the installer. It reuses the token already saved on this machine, nothing to paste.</p>

            <div class="grid grid-cols-2 gap-3 mb-4">
                <button type="button" @click="platform = 'unix'" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'unix' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">macOS / Linux</button>
                <button type="button" @click="platform = 'windows'" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Windows</button>
            </div>

            <div x-show="platform === 'unix'" x-cloak class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer" @click="copy('unix', 'curl -fsSL {{ route('install-script') }} | sh')">
                curl -fsSL {{ route('install-script') }} | sh
                <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'unix' ? 'Copied' : 'Copy'"></span>
            </div>

            <div x-show="platform === 'windows'" x-cloak class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer" @click="copy('windows', 'irm {{ route('install-script-ps1') }} | iex')">
                irm {{ route('install-script-ps1') }} | iex
                <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'windows' ? 'Copied' : 'Copy'"></span>
            </div>

            <p class="text-xs text-gray-500 mt-4">A few seconds of silence after Enter is normal — it's installing, not frozen. Never worked before on this machine? Use <a href="{{ route('setup') }}" class="underline hover:text-orange-600">the full setup guide</a> instead.</p>
        </div>
    </div>
@endsection
