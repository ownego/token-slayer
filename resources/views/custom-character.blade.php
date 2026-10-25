{{-- resources/views/custom-character.blade.php --}}
@extends('layouts.app')

@section('content')
    @include('partials.account-nav', ['active' => 'character'])

    <div class="max-w-3xl mx-auto p-4 sm:p-8 space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Your own fighter</h1>
            <p class="text-sm text-gray-500">Make a character sheet with an image AI, upload it, preview it, and it becomes your fighter, or your minion. Switch between your fighter and the roster in your profile, under Change character.</p>
        </div>

        @include('partials.custom-character-guide')

        <section class="space-y-2">
            <h2 class="font-bold text-gray-900">2. Upload it in the game</h2>
            <p class="text-sm text-gray-600">Upload the sheet, preview your fighter and use it from the <b>Character</b> tab of your profile: <b>Create</b> opens the editor next to the roster, and <b>Manage</b> replaces the sheet, renames the fighter and switches between versions.</p>
            <a href="{{ route('battlefield.open-sheet', 'editor') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-orange-400 hover:text-orange-600">Open the Character editor</a>
        </section>

        <section class="space-y-2">
            <h2 class="font-bold text-gray-900">3. Or make it a minion</h2>
            <p class="text-sm text-gray-600">The same sheet works for your subagents. Build it from any prompt above, then upload it in the <b>Minions</b> tab, next to <b>Fighter</b>, in the Character tab of your profile. <b>Use my fighter</b> there turns your custom fighter into a minion preview without an upload, and nothing joins your minions until you add it.</p>
            <p class="text-sm text-gray-600">Your minions share one pool with the five default looks, each with a tick: every subagent you start summons one ticked look at random, and unticking everything brings the defaults back. You can keep up to {{ (int) config('token_slayer.custom_character.minions_max') }} minions of your own, and <b>Replace</b> swaps a sheet while keeping its slot, name and tick. A minion stands half as tall as a fighter, so a big, simple shape in contrasting colours reads best: say so on a COSTUME ITEMS line.</p>
            <a href="{{ route('battlefield.open-sheet', 'minions') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-orange-400 hover:text-orange-600">Open the Minions tab</a>
        </section>
    </div>
@endsection
