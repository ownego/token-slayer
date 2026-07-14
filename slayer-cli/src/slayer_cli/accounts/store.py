"""Account slot CRUD (`accounts/<name>.json`) and the `state.json`
active-slot pointer. See `.ai/domain/account-slots.md`."""
from __future__ import annotations
import json
import os
import time
from pathlib import Path
from slayer_cli.accounts import alias
from slayer_cli.errors import AccountNotFound
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


class AccountStore:
    """Reads/writes account slot JSON files and the active-slot pointer.

    Slot files hold the raw token, so they get the same hardening as
    `credstore.file_store`: the accounts directory is created `0700` and
    every slot file is created `0600` from the moment it is written (never
    briefly group/world-readable under the default umask).
    """

    def __init__(self, paths: Paths) -> None:
        """:param paths: Resolved OS paths for this namespace."""
        self._paths = paths

    def add(self, account: Account) -> None:
        """Write `account` to `accounts/<name>.json`, creating the slot (or
        overwriting an existing slot with the same name).

        :param account: The account slot to persist.
        :return: None
        """
        self._write_slot(account)

    def get(self, name: str) -> Account:
        """Return the account slot named `name`.

        :param name: Slot name.
        :return: The parsed `Account`.
        :raises AccountNotFound: If no slot file exists for `name`.
        """
        path = self._slot_path(name)
        if not path.is_file():
            raise AccountNotFound(name)
        return Account.model_validate_json(path.read_text())

    def list(self) -> list[Account]:
        """Return all known account slots, sorted by name.

        :return: `Account` list, sorted ascending by `name`.
        """
        if not self._paths.accounts_dir.is_dir():
            return []
        accounts = [
            Account.model_validate_json(f.read_text())
            for f in self._paths.accounts_dir.glob("*.json")
        ]
        return sorted(accounts, key=lambda a: a.name)

    def remove(self, name: str) -> None:
        """Delete the slot file for `name`. Never touches `state.json` — if
        `name` is the active slot, `active()` keeps returning it (a
        dangling pointer the caller is responsible for handling).

        :param name: Slot name.
        :return: None
        :raises AccountNotFound: If no slot file exists for `name`.
        """
        path = self._slot_path(name)
        if not path.is_file():
            raise AccountNotFound(name)
        path.unlink()

    def exists(self, name: str) -> bool:
        """Return whether a slot file exists for `name`.

        :param name: Slot name.
        :return: True if the slot file exists.
        """
        return self._slot_path(name).is_file()

    def set_active(self, name: str) -> None:
        """Persist `name` as the active slot in `state.json`. Does not
        verify the slot exists — that's the caller's job.

        :param name: Slot name to mark active.
        :return: None
        """
        state = self._read_state()
        state["active_slot"] = name
        state["updated_at"] = int(time.time())
        self._write_state(state)

    def active(self) -> str | None:
        """Return the active slot name from `state.json`, or None if unset.

        The name is returned even after that slot has been removed —
        callers must handle a dangling pointer themselves.

        :return: The active slot name, or None.
        """
        return self._read_state().get("active_slot")

    def clear_active(self) -> None:
        """Unset the active slot in `state.json` (`active()` returns `None`
        afterward). Used when the active slot is removed, so it doesn't
        linger as a dangling pointer.

        :return: None
        """
        state = self._read_state()
        state.pop("active_slot", None)
        state["updated_at"] = int(time.time())
        self._write_state(state)

    def touch_last_used(self, name: str) -> None:
        """Set the slot's `last_used` to now and re-write it (`0600`).

        :param name: Slot name.
        :return: None
        :raises AccountNotFound: If no slot file exists for `name`.
        """
        account = self.get(name)
        self._write_slot(account.model_copy(update={"last_used": int(time.time())}))

    def resolve(self, identifier: str) -> Account:
        """Resolve `identifier` to an account by slot name, then alias, then
        email.

        :param identifier: Slot name, alias, or email.
        :return: The matching `Account`.
        :raises AccountNotFound: If nothing matches.
        """
        if self.exists(identifier):
            return self.get(identifier)
        for account in self.list():
            if account.alias == identifier:
                return account
        for account in self.list():
            if account.email == identifier:
                return account
        raise AccountNotFound(identifier)

    def set_alias(self, name: str, new_alias: str | None) -> None:
        """Set (or clear, when `new_alias` is None) the alias of slot `name`.

        :param name: Slot name.
        :param new_alias: New alias, or None to clear.
        :return: None
        :raises AccountNotFound: If the slot does not exist.
        :raises alias.InvalidAlias: If `new_alias` is malformed.
        :raises alias.AliasInUse: If `new_alias` is taken by another slot.
        """
        account = self.get(name)
        if new_alias is not None:
            alias.validate_alias(new_alias)
            for other in self.list():
                if other.name != name and other.alias == new_alias:
                    raise alias.AliasInUse(f"alias '{new_alias}' is already used by '{other.name}'")
        self._write_slot(account.model_copy(update={"alias": new_alias}))

    def _slot_path(self, name: str) -> Path:
        """Return the on-disk path for slot `name`.

        :param name: Slot name.
        :return: Path to `accounts/<name>.json`.
        """
        return self._paths.accounts_dir / f"{name}.json"

    def _write_slot(self, account: Account) -> None:
        """Serialize `account` to its slot file, creating the file `0600`
        from the start and the accounts directory `0700`.

        :param account: The account slot to persist.
        :return: None
        """
        self._harden_dir(self._paths.config_dir)
        self._harden_dir(self._paths.accounts_dir)
        self._atomic_write(self._slot_path(account.name), account.model_dump_json())

    def _read_state(self) -> dict:
        """Return the parsed contents of `state.json`, or `{}` if absent/unreadable.

        :return: Parsed state dict.
        """
        path = self._paths.state_file
        if not path.is_file():
            return {}
        try:
            return json.loads(path.read_text())
        except ValueError:
            return {}

    def _write_state(self, state: dict) -> None:
        """Write `state` to `state.json`, creating the file `0600` and the
        config dir `0700`.

        :param state: The full state dict to persist.
        :return: None
        """
        self._harden_dir(self._paths.config_dir)
        self._atomic_write(self._paths.state_file, json.dumps(state))

    @staticmethod
    def _harden_dir(path: Path) -> None:
        """Create `path` (and parents) if absent and force it to `0700`, so a
        credential directory is never left group/world-listable — `mkdir(mode=)`
        alone is subject to the umask, and `mkdir(parents=True)` would otherwise
        create intermediate dirs at the default `0755`.

        :param path: Directory to create and harden.
        :return: None
        """
        path.mkdir(parents=True, exist_ok=True)
        os.chmod(path, 0o700)

    @staticmethod
    def _atomic_write(path: Path, text: str) -> None:
        """Write `text` to `path` atomically, leaving the file `0600`.

        Writes to a sibling `.tmp` file created `0600` from the start (so the
        token is never briefly group/world-readable under the default umask),
        then `replace()`s it onto `path` — a crash/kill mid-write leaves the
        live file untouched instead of a truncated/corrupt JSON. Mirrors
        `credstore.file_store.write`.

        :param path: Final destination path.
        :param text: File contents to write.
        :return: None
        """
        tmp = path.with_suffix(".tmp")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as handle:
            handle.write(text)
        tmp.replace(path)
