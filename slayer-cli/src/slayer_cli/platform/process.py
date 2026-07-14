"""Subprocess helper (used by the macOS Keychain store)."""
from __future__ import annotations
import subprocess

def run(cmd: list[str], input_: str | None = None) -> tuple[int, str, str]:
    p = subprocess.run(cmd, input=input_, capture_output=True, text=True)
    return p.returncode, p.stdout, p.stderr
