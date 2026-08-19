@php($command = "PROVIDER=codex bash \$HOME/.config/{$namespace}/send-hook.sh")
{
  "hooks": {
@foreach (['SessionStart', 'Stop'] as $event)
    "{{ $event }}": [
      { "hooks": [{
        "type": "command",
        "command": "{!! $command !!}"
      }]}]{{ ! $loop->last ? ',' : '' }}
@endforeach
  }
}
