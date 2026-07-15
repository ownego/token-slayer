<x-filament-panels::page>
    {{-- Inline styles: custom Filament page Blades don't get arbitrary app
         Tailwind utilities unless a viteTheme is registered. --}}
    <div style="display:flex; gap:.5rem; margin-bottom:1rem;">
        <x-filament::button
            :color="$activeTab === 'accounts' ? 'primary' : 'gray'"
            wire:click="$set('activeTab', 'accounts')"
        >
            Organizations
        </x-filament::button>
        <x-filament::button
            :color="$activeTab === 'users' ? 'primary' : 'gray'"
            wire:click="$set('activeTab', 'users')"
        >
            Users
        </x-filament::button>
    </div>

    @if ($activeTab === 'accounts')
        @php($rows = $this->rows())

        <x-filament::section heading="Unrecognized organizations">
            @if (empty($rows))
                <p style="opacity:.6;">No unrecognized organizations — every beacon event is attributed.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                        <thead>
                            <tr style="text-align:left; opacity:.6;">
                                <th style="padding:.4rem .6rem;">Organization UUID</th>
                                <th style="padding:.4rem .6rem; text-align:right;">Events</th>
                                <th style="padding:.4rem .6rem; text-align:right;">Tokens</th>
                                <th style="padding:.4rem .6rem; text-align:right;">Users</th>
                                <th style="padding:.4rem .6rem;">Last seen</th>
                                <th style="padding:.4rem .6rem;">Account</th>
                                <th style="padding:.4rem .6rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                    <td style="padding:.4rem .6rem; font-family:monospace;">{{ $row['org_uuid'] }}</td>
                                    <td style="padding:.4rem .6rem; text-align:right; font-variant-numeric:tabular-nums;">{{ number_format($row['events']) }}</td>
                                    <td style="padding:.4rem .6rem; text-align:right; font-variant-numeric:tabular-nums;">{{ number_format($row['tokens']) }}</td>
                                    <td style="padding:.4rem .6rem; text-align:right; font-variant-numeric:tabular-nums;">{{ $row['users'] }}</td>
                                    <td style="padding:.4rem .6rem; opacity:.75;">{{ $row['last_seen'] }}</td>
                                    <td style="padding:.4rem .6rem;">{{ $row['account_email'] ?? '— no account —' }}</td>
                                    <td style="padding:.4rem .6rem; text-align:right;">
                                        <x-filament::dropdown placement="bottom-end">
                                            <x-slot name="trigger">
                                                <x-filament::icon-button icon="heroicon-o-ellipsis-vertical" label="Actions" />
                                            </x-slot>

                                            <x-filament::dropdown.list>
                                                @if ($row['account_id'] !== null)
                                                    <x-filament::dropdown.list.item wire:click="mountAction('backfill', { org: @js($row['org_uuid']) })">
                                                        Backfill {{ number_format($row['events']) }}
                                                    </x-filament::dropdown.list.item>
                                                @else
                                                    <x-filament::dropdown.list.item wire:click="mountAction('connectAccount')">
                                                        Connect
                                                    </x-filament::dropdown.list.item>
                                                @endif
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
    @else
        @php($unattachedUsers = $this->unattachedUsers())

        <x-filament::section heading="Unattached users">
            @if (empty($unattachedUsers))
                <p style="opacity:.6;">No unattached users — every developer belongs to an account.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                        <thead>
                            <tr style="text-align:left; opacity:.6;">
                                <th style="padding:.4rem .6rem;">User</th>
                                <th style="padding:.4rem .6rem;">Email</th>
                                <th style="padding:.4rem .6rem;">Last activity</th>
                                <th style="padding:.4rem .6rem;">Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unattachedUsers as $row)
                                <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                    <td style="padding:.4rem .6rem;">{{ $row['handle'] }}</td>
                                    <td style="padding:.4rem .6rem; opacity:.75;">{{ $row['email'] ?? '—' }}</td>
                                    <td style="padding:.4rem .6rem; opacity:.75;">{{ $row['last_event_at'] ?? '—' }}</td>
                                    <td style="padding:.4rem .6rem; opacity:.75;">{{ $row['created_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
