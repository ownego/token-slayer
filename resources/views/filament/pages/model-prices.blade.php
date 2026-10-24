<x-filament-panels::page>
    {{-- Inline styles: custom Filament page Blades don't get arbitrary app
         Tailwind utilities unless a viteTheme is registered. --}}
    @php($prices = $this->prices())
    @php($missingModels = $this->missingModels())

    <x-filament::section heading="Prices in use" description="USD per million tokens, from the LiteLLM price list. A row applies from its date on.">
        @if (empty($prices))
            <p style="opacity:.6;">No prices yet. They load with the first price sync.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Model</th>
                            <th style="padding:.4rem .6rem;">Provider</th>
                            <th style="padding:.4rem .6rem;">Since</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Input</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Output</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Cache read</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Cache write 5m</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Cache write 1h</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($prices as $price)
                            <tr style="border-top:1px solid rgba(120,120,140,.16);">
                                <td style="padding:.4rem .6rem; font-family:ui-monospace,monospace;">{{ $price['model'] }}</td>
                                <td style="padding:.4rem .6rem;">{{ $price['provider'] }}</td>
                                <td style="padding:.4rem .6rem; font-variant-numeric:tabular-nums;">{{ $price['effective_from'] }}</td>
                                @foreach (['input', 'output', 'cache_read', 'cache_write_5m', 'cache_write_1h'] as $field)
                                    <td style="padding:.4rem .6rem; text-align:right; font-variant-numeric:tabular-nums;">
                                        {{ $price[$field] === null ? '—' : '$'.rtrim(rtrim(number_format($price[$field], 6), '0'), '.') }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Models missing a price" description="Their events are stored without a cost until the price list carries them; the sync then fills the cost in.">
        @if (empty($missingModels))
            <p style="opacity:.6;">None. Every model in use has a price.</p>
        @else
            <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                <thead>
                    <tr style="text-align:left; opacity:.6;">
                        <th style="padding:.4rem .6rem;">Model</th>
                        <th style="padding:.4rem .6rem;">First seen</th>
                        <th style="padding:.4rem .6rem;">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($missingModels as $missing)
                        <tr style="border-top:1px solid rgba(120,120,140,.16);">
                            <td style="padding:.4rem .6rem; font-family:ui-monospace,monospace;">{{ $missing['model'] }}</td>
                            <td style="padding:.4rem .6rem; font-variant-numeric:tabular-nums;">{{ $missing['first_seen_at'] }}</td>
                            <td style="padding:.4rem .6rem; font-variant-numeric:tabular-nums;">{{ $missing['last_seen_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
