<x-filament-panels::page>
    @php($groups = $this->groups())

    <label style="display:flex; gap:.5rem; align-items:center; font-size:.85rem;">
        <input type="checkbox" wire:model.live="showAll">
        Show all accounts and devices
    </label>

    @forelse ($groups as $group)
        <x-filament::section>
            <x-slot name="heading">
                <span style="display:flex; gap:.6rem; align-items:center; flex-wrap:wrap;">
                    {{ $group['name'] }}
                    <x-filament::badge :color="$group['provider']->getColor()">
                        {{ $group['provider']->getLabel() }}
                    </x-filament::badge>
                    {{-- Whether a per-employee grant is already out awaiting pull --
                         an admin who already reissued one otherwise has no way to
                         tell "already did this" from "haven't yet" while the
                         account keeps showing here. --}}
                    @if ($group['has_fresh_pending_grant'])
                        <x-filament::badge color="success">🟢 pending — awaiting pull</x-filament::badge>
                    @elseif ($group['needs_attention'])
                        <x-filament::badge color="danger">🔴 no live grant yet</x-filament::badge>
                    @endif
                </span>
            </x-slot>

            {{-- The account's own shared credential: repaired by reconnecting the
                 account (Claude) or re-probing it (Codex has no per-row connect
                 to offer -- its device-code flow binds to whoever approves),
                 never by reissuing a member device. --}}
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; font-size:.85rem;">
                <span style="opacity:.85;">{{ $group['credential_label'] ?? 'Account credential healthy' }}</span>
                @if ($group['provider'] === \App\Enums\Provider::Claude)
                    {{ ($this->reconnectAccountAction)(['account' => $group['account_id']]) }}
                @else
                    {{ ($this->refreshAccountUsageAction)(['account' => $group['account_id']]) }}
                @endif
            </div>

            {{-- One member device's own claimed session: reissued in place on
                 the same device (reserve token or pasted code). --}}
            @if (! empty($group['devices']))
                <div style="overflow-x:auto; margin-top:.6rem;">
                    <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                        <tbody>
                            @foreach ($group['devices'] as $device)
                                <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                    <td style="padding:.4rem .6rem;">{{ $device['user_email'] }}</td>
                                    <td style="padding:.4rem .6rem; opacity:.85;">{{ $device['device_label'] }}</td>
                                    <td style="padding:.4rem .6rem;">
                                        <x-filament::badge :color="\App\Support\DaysLeft::isOverdue($device['deadline']) ? 'danger' : 'warning'">
                                            {{ \App\Support\DaysLeft::label($device['deadline'], $device['estimated']) }}
                                        </x-filament::badge>
                                    </td>
                                    <td style="padding:.4rem .6rem; text-align:right;">
                                        {{ ($this->reissueGrantAction)(['grant' => $device['grant_id']]) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <p style="opacity:.6;">No accounts expiring or stale right now.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
