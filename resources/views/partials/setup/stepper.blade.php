{{-- resources/views/partials/setup/stepper.blade.php --}}
@php($labels = $labels ?? [])
<div class="flex items-center w-full mb-8">
    @foreach ($labels as $i => $label)
        @php($n = $i + 1)
        <div class="flex items-center {{ $loop->last ? 'w-auto' : 'w-full' }}">
            <div class="flex flex-col items-center flex-shrink-0">
                <div
                    class="flex items-center justify-center w-11 h-11 rounded-full font-bold text-sm transition"
                    :class="{
                        'stepper-dot-done': step > {{ $n }},
                        'stepper-dot-active': step === {{ $n }},
                        'bg-gray-100 text-gray-400': step < {{ $n }},
                    }"
                >
                    <template x-if="step > {{ $n }}">
                        <svg class="w-5 h-5 stepper-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 11.917 9.724 16.5 19 7.5"/></svg>
                    </template>
                    <template x-if="step <= {{ $n }}">
                        <span>{{ $n }}</span>
                    </template>
                </div>
                <span class="text-[11px] text-gray-500 mt-1.5 text-center whitespace-nowrap">{{ $label }}</span>
            </div>
            @unless ($loop->last)
                <div class="h-2 w-full min-w-[24px] rounded-full mx-2 relative overflow-hidden bg-gray-200">
                    <div class="stepper-bar-fill absolute inset-0 rounded-full" :class="step > {{ $n }} ? 'stepper-bar-fill-on' : 'scale-x-0'"></div>
                </div>
            @endunless
        </div>
    @endforeach
</div>
