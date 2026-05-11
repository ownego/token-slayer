{
  "hooks": {
@foreach (['SessionStart','UserPromptSubmit','PreToolUse','PostToolUse','Stop','SubagentStop','SessionEnd','Notification'] as $event)
    "{{ $event }}": [
      { "hooks": [{
        "type": "command",
        "command": "curl -sS --max-time 3 -X POST '{{ $baseUrl }}' -H 'Authorization: Bearer {{ $token }}' -H 'Content-Type: application/json' -d @- &"
      }]}]{{ ! $loop->last ? ',' : '' }}
@endforeach
  }
}
