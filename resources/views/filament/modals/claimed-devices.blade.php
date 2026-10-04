{{-- Body of MembersRelationManager's "Claimed devices" modal: one row per
     device that has pulled a grant on this account, each with its own
     Reissue button (ReissuesGrants' reissueGrant, registered as a modal
     action of $action). --}}
@if ($grants->isEmpty())
    <p style="opacity:.6;">No claimed devices.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
            <thead>
                <tr style="text-align:left; opacity:.6;">
                    <th style="padding:.4rem .6rem;">User</th>
                    <th style="padding:.4rem .6rem;">Device</th>
                    <th style="padding:.4rem .6rem;">Claimed</th>
                    <th style="padding:.4rem .6rem;">Session</th>
                    <th style="padding:.4rem .6rem;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grants as $grant)
                    <tr style="border-top:1px solid rgba(120,120,140,.15);">
                        <td style="padding:.4rem .6rem;">{{ $grant->device->user->email }}</td>
                        <td style="padding:.4rem .6rem;" title="{{ $grant->device->device_id }}">{{ $grant->device->name ?? 'Unnamed device' }}</td>
                        <td style="padding:.4rem .6rem; opacity:.85;">{{ $grant->claimed_at?->diffForHumans() ?? '—' }}</td>
                        <td style="padding:.4rem .6rem;">
                            <x-filament::badge :color="\App\Support\DaysLeft::isOverdue($grant->session_expires_at) ? 'danger' : 'gray'">
                                {{ \App\Support\DaysLeft::label($grant->session_expires_at, $grant->session_expires_at_estimated) }}
                            </x-filament::badge>
                        </td>
                        <td style="padding:.4rem .6rem; text-align:right;">{{ ($action->getModalAction('reissueGrant'))(['grant' => $grant->id]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
