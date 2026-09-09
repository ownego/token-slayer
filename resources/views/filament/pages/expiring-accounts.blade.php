<x-filament-panels::page>
    @php($rows = $this->rows())

    <x-filament::section heading="Accounts needing attention">
        @if (empty($rows))
            <p style="opacity:.6;">No accounts expiring or stale right now.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Account</th>
                            <th style="padding:.4rem .6rem;">Provider</th>
                            <th style="padding:.4rem .6rem;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ $row['name'] ?? $row['email'] ?? '— unnamed —' }}</td>
                                <td style="padding:.4rem .6rem;">
                                    <x-filament::badge :color="$row['provider'] === 'codex' ? 'gray' : 'primary'">
                                        {{ ucfirst($row['provider']) }}
                                    </x-filament::badge>
                                </td>
                                <td style="padding:.4rem .6rem; opacity:.85;">{{ $row['label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
