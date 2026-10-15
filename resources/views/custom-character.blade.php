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

        <section class="space-y-4">
            <h2 class="font-bold text-gray-900">2. Upload, preview and manage</h2>
            @livewire('custom-character-editor')
        </section>
    </div>
@endsection
