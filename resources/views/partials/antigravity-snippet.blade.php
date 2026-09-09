@php($command = "PROVIDER=antigravity bash \$HOME/.config/{$namespace}/send-hook.sh")
{
  "{{ $namespace }}": {
    "SessionStart": [
      { "type": "command", "command": "{!! $command !!}" }
    ],
    "PreInvocation": [
      { "type": "command", "command": "{!! $command !!}" }
    ],
    "PreToolUse": [
      {
        "matcher": "*",
        "hooks": [
          { "type": "command", "command": "{!! $command !!}" }
        ]
      }
    ],
    "Stop": [
      { "type": "command", "command": "{!! $command !!}" }
    ]
  }
}
