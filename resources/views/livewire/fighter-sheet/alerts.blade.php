@if (count($alerts) > 0)
    <div class="heads has-data" id="heads" aria-live="polite" x-data="{ dismissed: JSON.parse(sessionStorage.getItem('fs-dismissed-alerts') || '[]') }">
        @foreach ($alerts as $alert)
            <div class="alert {{ $alert['severity'] }}" data-id="{{ $alert['id'] }}" x-show="!dismissed.includes('{{ $alert['id'] }}')">
                <span>{{ $alert['text'] }}</span>
                <button
                    class="dismiss"
                    type="button"
                    aria-label="Dismiss"
                    @click="dismissed.push('{{ $alert['id'] }}'); sessionStorage.setItem('fs-dismissed-alerts', JSON.stringify(dismissed))"
                >×</button>
            </div>
        @endforeach
    </div>
@endif
