{{-- resources/views/custom-character.blade.php --}}
@extends('layouts.app')

@section('content')
    @include('partials.account-nav', ['active' => 'character'])

    <div class="max-w-3xl mx-auto p-4 sm:p-8 space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Your own fighter</h1>
            <p class="text-sm text-gray-500">Make a character sheet with an image AI, upload it, preview it, and it becomes your fighter. Switch between it and the roster in your profile, under Change character.</p>
        </div>

        @include('partials.custom-character-guide')

        <section class="space-y-2">
            <h2 class="font-bold text-gray-900">2. Upload it in the game</h2>
            <p class="text-sm text-gray-600">Upload the sheet, preview your fighter and use it from the <b>Character</b> tab of your profile: <b>Create</b> opens the editor next to the roster, and <b>Manage</b> replaces the sheet, renames the fighter and switches between versions.</p>
            <a href="{{ route('battlefield') }}?sheet=character" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-orange-400 hover:text-orange-600">Open the Character tab &#8599;</a>
        </section>
    </div>
@endsection
