@extends('layouts.app')
@section('content')
    <div class="max-w-4xl mx-auto p-8 space-y-4 text-white bg-slate-950 min-h-screen">
        <h1 class="text-2xl font-bold mb-6">Defeated bosses</h1>

        @if ($bosses->isEmpty())
            <p class="text-gray-400">No boss has fallen yet.</p>
        @else
            <table class="w-full text-left">
                <thead class="text-xs text-gray-400 uppercase">
                    <tr>
                        <th class="py-2">Boss</th>
                        <th>Killing blow</th>
                        <th>Spawned</th>
                        <th>Defeated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($bosses as $boss)
                        <tr>
                            <td class="py-3 font-semibold">{{ $boss->name ?: 'Boss #'.$boss->number }}</td>
                            <td>
                                @if ($boss->killingBlowUser)
                                    <span class="inline-flex items-center gap-2">
                                        @if ($boss->killingBlowUser->avatar_url)
                                            <img src="{{ $boss->killingBlowUser->avatar_url }}" class="w-6 h-6 rounded-full">
                                        @endif
                                        {{ $boss->killingBlowUser->slack_handle }}
                                    </span>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="text-sm text-gray-400">{{ $boss->spawned_at?->diffForHumans() }}</td>
                            <td class="text-sm text-gray-400">{{ $boss->defeated_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pt-4">{{ $bosses->links() }}</div>
        @endif
    </div>
@endsection
