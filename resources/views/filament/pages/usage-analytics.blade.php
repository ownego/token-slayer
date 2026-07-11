<x-filament-panels::page>
    {{-- The filter schema is live-reactive (statePath('filters'), ->live() on the range
         select) — no submit button/action is needed. Header widgets are registered via
         getHeaderWidgets() and rendered automatically by x-filament-panels::page. --}}
    {{ $this->filtersForm }}
</x-filament-panels::page>
