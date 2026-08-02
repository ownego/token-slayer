@props(['n'])

<div x-show="step === {{ $n }}" :class="direction === 1 ? 'step-in-fwd' : 'step-in-back'">
    {{ $slot }}
</div>
