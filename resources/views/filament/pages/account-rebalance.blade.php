<x-filament-panels::page>
    <x-filament::section heading="Gợi ý phân bổ">
        @if (empty($moves) && $unresolvedOverflowTokens === 0.0)
            <p style="opacity:.6;">Bấm "Tính lại phân bổ" để xem gợi ý.</p>
        @endif

        @if (! empty($moves))
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">User</th>
                            <th style="padding:.4rem .6rem;">Từ account</th>
                            <th style="padding:.4rem .6rem;">Sang account</th>
                            <th style="padding:.4rem .6rem;">Demand/ngày</th>
                            <th style="padding:.4rem .6rem;">Độ tin cậy</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moves as $move)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ \App\Models\User::find($move['userId'])?->displayHandle() }}</td>
                                <td style="padding:.4rem .6rem;">
                                    {{ \App\Models\Account::find($move['fromAccountId'])?->email }}
                                    ({{ $move['fromProjectedBefore'] }}% → {{ $move['fromProjectedAfter'] }}%)
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ \App\Models\Account::find($move['toAccountId'])?->email }}
                                    ({{ $move['toProjectedBefore'] }}% → {{ $move['toProjectedAfter'] }}%)
                                </td>
                                <td style="padding:.4rem .6rem;">{{ number_format($move['demandTokensPerDay']) }} ({{ $move['demandBasis'] }})</td>
                                <td style="padding:.4rem .6rem;">
                                    @if ($move['confident'])
                                        <x-filament::badge color="success">Đủ dữ liệu</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning">Dữ liệu chưa đủ</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($unresolvedOverflowTokens > 0.0)
            <p style="color:var(--danger-500, #dc2626); margin-top:.75rem;">
                Không tìm được account đủ chỗ cho {{ number_format($unresolvedOverflowTokens) }} token còn dư — cần thêm capacity.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
