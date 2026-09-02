<div class="max-w-xl mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Admin API token</h1>
    <p class="mb-4 text-sm text-gray-600">
        Generates a bearer token for <code>token-slayer admin login --token &lt;token&gt;</code>,
        used to provision shared Codex accounts from your own machine. Shown once — copy it now.
    </p>

    @if ($plainToken)
        <div class="mb-4 p-3 bg-gray-100 rounded font-mono text-sm break-all">{{ $plainToken }}</div>
    @endif

    <button wire:click="generateToken" class="px-4 py-2 bg-black text-white rounded">
        {{ $plainToken ? 'Generate a new token' : 'Generate token' }}
    </button>
</div>
