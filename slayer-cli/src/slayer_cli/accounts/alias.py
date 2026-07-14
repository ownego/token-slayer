"""Account alias rules: a short friendly name that can't be confused with a
slot name or an email, plus the errors the store raises around them."""
from __future__ import annotations

import re

from slayer_cli.errors import SlayerError

_ALIAS_RE = re.compile(r"^[a-z][a-z0-9-]{0,19}$")


class InvalidAlias(SlayerError):
    """Raised when an alias violates the naming rules."""


class AliasInUse(SlayerError):
    """Raised when an alias is already assigned to another slot."""


def validate_alias(alias: str) -> None:
    """Raise :class:`InvalidAlias` unless `alias` is 1-20 chars, starts with a
    lowercase letter, and contains only lowercase letters, digits, or hyphens.

    :param alias: Candidate alias.
    :return: None
    :raises InvalidAlias: If the alias is malformed.
    """
    if not _ALIAS_RE.match(alias):
        raise InvalidAlias(
            f"alias '{alias}' must start with a letter and contain only "
            "lowercase letters, digits, or hyphens (max 20 chars)"
        )
