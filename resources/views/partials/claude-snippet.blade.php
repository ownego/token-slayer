@php($command = "bash \$HOME/.config/{$namespace}/send-hook.sh")
{
  "hooks": {
@foreach (['SessionStart','UserPromptSubmit','PreToolUse','Stop','SubagentStop'] as $event)
    "{{ $event }}": [
      { "hooks": [{
        "type": "command",
        "command": "{!! $command !!}"
      }]}]{{ ! $loop->last ? ',' : '' }}
@endforeach
  }
}
