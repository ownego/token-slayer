@if ($attribution['hookOutdated'])
    <a href="{{ route('update') }}" class="inline-block rounded bg-amber-500/20 px-2.5 py-1 text-xs text-amber-200">Hook v{{ $attribution['latestHookVersion'] }} ↑</a>
@endif
{{-- The CLI-outdated pill moved to header.blade.php's .cli-pill --}}
@if ($attribution['event'])
    <span title="{{ $attribution['event']->account_email }} &middot; {{ $attribution['event']->account_source }}" class="text-xs text-slate-500">{{ $attribution['event']->account_email }} &middot; {{ $attribution['event']->account_source }}</span>
@endif
