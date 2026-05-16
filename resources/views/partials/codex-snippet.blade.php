@php($command = "curl -s --max-time 3 -X POST '{$baseUrl}' -H 'Authorization: Bearer '\$(cat ~/.config/{$namespace}/token) -H 'Content-Type: application/json' -d @- >/dev/null 2>&1 &")
# Codex hook config — add to ~/.codex/config.toml under [hooks]
# Adjust event names to match your Codex CLI version.

@foreach (['session_start', 'stop'] as $event)
[[hooks]]
event = "{{ $event }}"
command = "{!! $command !!}"

@endforeach
