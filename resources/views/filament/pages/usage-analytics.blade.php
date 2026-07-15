<x-filament-panels::page>
    {{-- The filter schema is live-reactive (statePath('filters'), ->live() on the range
         select) — no submit button/action is needed. The analytics widgets are registered
         as footer widgets, so they render below this filter form. --}}
    <div style="width:100%;">
        {{ $this->filtersForm }}
    </div>
</x-filament-panels::page>
