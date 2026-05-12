@php($command = "curl -s --max-time 3 -X POST '{$baseUrl}' -H 'Authorization: Bearer {$token}' -H 'Content-Type: application/json' -d @- >/dev/null 2>&1 &")
{
  "hooks": {
@foreach (['SessionStart','UserPromptSubmit','PreToolUse','PostToolUse','Stop','SubagentStop','SessionEnd','Notification'] as $event)
    "{{ $event }}": [
      { "hooks": [{
        "type": "command",
        "command": "{!! $command !!}"
      }]}]{{ ! $loop->last ? ',' : '' }}
@endforeach
  }
}
