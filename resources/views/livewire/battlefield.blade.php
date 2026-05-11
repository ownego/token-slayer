<div class="relative min-h-screen bg-slate-950 text-white">
    @if ($boss)
        <div class="absolute inset-0 flex flex-col items-center justify-center gap-4">
            <h2 class="text-3xl font-bold">Boss #{{ $boss->number }}</h2>
            <div class="w-96 h-4 bg-slate-700 rounded">
                <div class="h-4 bg-red-500 rounded" style="width: {{ ($boss->current_hp / $boss->max_hp) * 100 }}%"></div>
            </div>
            <span class="text-sm text-gray-300">{{ number_format($boss->current_hp) }} / {{ number_format($boss->max_hp) }}</span>
        </div>
    @endif

    <div class="absolute inset-x-0 bottom-8 flex justify-center gap-4 flex-wrap">
        @foreach ($fighters as $f)
            <div class="text-center" wire:key="fighter-{{ $f->id }}">
                <img src="{{ $f->avatar_url }}" class="w-12 h-12 rounded-full ring-2 ring-amber-400">
                <p class="text-xs mt-1">{{ $f->slack_handle }}</p>
            </div>
        @endforeach
    </div>
</div>
