{{-- resources/views/filament/pages/expiring-accounts.blade.php --}}
<x-filament-panels::page>
    @php($rows = $this->rows())

    <x-filament::section heading="Accounts nearing their refresh-token deadline">
        @if (empty($rows))
            <p style="opacity:.6;">Nothing expiring in the next 3 days.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Account</th>
                            <th style="padding:.4rem .6rem;">Plan</th>
                            <th style="padding:.4rem .6rem;">Expires in</th>
                            <th style="padding:.4rem .6rem; text-align:right;">Tracked members</th>
                            <th style="padding:.4rem .6rem;">Last refreshed</th>
                            <th style="padding:.4rem .6rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($hoursLeft = now()->diffInHours($row['expires_at'], false))
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ $row['email'] ?? '—' }}</td>
                                <td style="padding:.4rem .6rem;">{{ $row['plan'] ?? '—' }}</td>
                                <td style="padding:.4rem .6rem; color: {{ $row['is_expired'] || $hoursLeft < 24 ? '#ef4444' : '#f59e0b' }}; font-weight: {{ $row['is_expired'] ? 'bold' : 'normal' }};">
                                    @if ($row['is_expired'])
                                        EXPIRED — {{ $row['expires_at']->diffForHumans() }}
                                    @else
                                        {{ $row['expires_at']->diffForHumans() }}
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem; text-align:right; font-variant-numeric:tabular-nums;">{{ $row['tracked_members'] }}</td>
                                <td style="padding:.4rem .6rem; opacity:.75;">{{ $row['updated_at']?->diffForHumans() ?? '—' }}</td>
                                <td style="padding:.4rem .6rem; text-align:right;">
                                    <x-filament::dropdown placement="bottom-end">
                                        <x-slot name="trigger">
                                            <x-filament::icon-button icon="heroicon-o-ellipsis-vertical" label="Actions" />
                                        </x-slot>

                                        <x-filament::dropdown.list>
                                            <x-filament::dropdown.list.item wire:click="mountAction('reconnect', { account: {{ $row['id'] }} })">
                                                Reconnect
                                            </x-filament::dropdown.list.item>
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
