"""Per-wrapper session registry: runtime/sessions/<pid>.json, so `sessions`
can show which wrappers are running and what each is doing. Dead PIDs pruned."""
from __future__ import annotations
import json
import os
import time
from pydantic import BaseModel
from slayer_cli.platform.paths import Paths

class Entry(BaseModel):
    pid: int
    state: str = "running"    # running | swapping | retrying | waiting-reset
    account: str | None = None
    cwd: str = ""
    updated_at: int = 0

def _path(paths: Paths, pid: int):
    return paths.sessions_dir / f"{pid}.json"

def update_self(paths: Paths, mutate) -> None:
    """Read this process's registry entry, apply `mutate(dict)`, write it back
    (0600, dir 0700). `mutate` receives a plain dict to update in place."""
    pid = os.getpid()
    d = paths.sessions_dir
    d.mkdir(parents=True, exist_ok=True)
    os.chmod(d, 0o700)
    path = _path(paths, pid)
    entry = {"pid": pid, "state": "running", "account": None, "cwd": os.getcwd(), "updated_at": int(time.time())}
    if path.is_file():
        try:
            entry.update(json.loads(path.read_text()))
        except ValueError:
            pass
    entry["cwd"] = os.getcwd()
    mutate(entry)
    entry["updated_at"] = int(time.time())
    tmp = path.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as h:
        h.write(json.dumps(entry))
    tmp.replace(path)

def _alive(pid: int) -> bool:
    """Return whether process `pid` is currently running."""
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    except OSError:
        return False
    return True

def list(paths: Paths) -> list[Entry]:
    """Return live wrapper entries, pruning (deleting) any whose PID is dead."""
    d = paths.sessions_dir
    if not d.is_dir():
        return []
    out: list[Entry] = []
    for f in d.glob("*.json"):
        try:
            e = Entry.model_validate_json(f.read_text())
        except ValueError:
            continue
        if _alive(e.pid):
            out.append(e)
        else:
            f.unlink(missing_ok=True)
    return sorted(out, key=lambda e: e.pid)

def remove_self(paths: Paths) -> None:
    """Delete this process's registry entry (clean shutdown)."""
    _path(paths, os.getpid()).unlink(missing_ok=True)
