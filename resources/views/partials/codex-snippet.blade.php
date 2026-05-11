# Codex hook config — add to ~/.codex/config.toml under [hooks]
# Adjust event names to match your Codex CLI version.

[[hooks]]
event = "session_start"
command = "curl -sS --max-time 3 -X POST '{{ $baseUrl }}' -H 'Authorization: Bearer {{ $token }}' -H 'Content-Type: application/json' -d @- &"

[[hooks]]
event = "stop"
command = "curl -sS --max-time 3 -X POST '{{ $baseUrl }}' -H 'Authorization: Bearer {{ $token }}' -H 'Content-Type: application/json' -d @- &"
