<div class="p-8 max-w-5xl mx-auto space-y-10">
    <h1 class="text-2xl font-semibold">Admin usage</h1>

    <section>
        <h2 class="font-semibold mb-3">Usage by account</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-gray-500 uppercase">
                <tr>
                    <th class="py-2">Account</th>
                    <th>Plan</th>
                    <th class="text-right">Members</th>
                    <th class="text-right">Hourly</th>
                    <th class="text-right">Daily</th>
                    <th class="text-right">Monthly</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($accounts as $row)
                    <tr>
                        <td class="py-2 font-medium">{{ $row['email'] }}</td>
                        <td class="text-gray-500">{{ $row['plan'] ?? '—' }}</td>
                        <td class="text-right font-mono">{{ $row['memberCount'] }}</td>
                        <td class="text-right font-mono">{{ number_format($row['hourly']) }}</td>
                        <td class="text-right font-mono">{{ number_format($row['daily']) }}</td>
                        <td class="text-right font-mono">{{ number_format($row['monthly']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section>
        <h2 class="font-semibold mb-3">Usage by user</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-gray-500 uppercase">
                <tr>
                    <th class="py-2">User</th>
                    <th>Account</th>
                    <th class="text-right">Hourly</th>
                    <th class="text-right">Daily</th>
                    <th class="text-right">Monthly</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($users as $row)
                    <tr>
                        <td class="py-2 font-medium">
                            <span class="inline-flex items-center gap-2">
                                @if ($row['avatar_url'])
                                    <img src="{{ $row['avatar_url'] }}" alt="{{ $row['handle'] }}" class="w-6 h-6 rounded-full">
                                @endif
                                {{ $row['handle'] }}
                            </span>
                        </td>
                        <td class="text-gray-500">{{ $row['account_email'] ?? '—' }}</td>
                        <td class="text-right font-mono">{{ number_format($row['hourly']) }}</td>
                        <td class="text-right font-mono">{{ number_format($row['daily']) }}</td>
                        <td class="text-right font-mono">{{ number_format($row['monthly']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
