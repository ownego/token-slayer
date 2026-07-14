"""Tests for swap history (JSONL append/recent)."""
from __future__ import annotations

from slayer_cli.platform.paths import Paths
from slayer_cli.accounts.history import SwapHistory
from slayer_cli.models.history import SwapHistoryEntry


def test_append_and_recent_newest_first(tmp_path, monkeypatch):
    """Appending two entries, recent(1) returns the newest to."""
    monkeypatch.setenv("HOME", str(tmp_path))
    h = SwapHistory(Paths("token_slayer"))
    h.append(SwapHistoryEntry(ts=1, to="a"))
    h.append(SwapHistoryEntry(ts=2, to="b"))
    r = h.recent(1)
    assert len(r) == 1 and r[0].to == "b"


def test_recent_respects_n(tmp_path, monkeypatch):
    """recent(n) returns last n entries."""
    monkeypatch.setenv("HOME", str(tmp_path))
    h = SwapHistory(Paths("token_slayer"))
    h.append(SwapHistoryEntry(ts=1, to="a"))
    h.append(SwapHistoryEntry(ts=2, to="b"))
    h.append(SwapHistoryEntry(ts=3, to="c"))
    r = h.recent(2)
    assert len(r) == 2
    assert r[0].to == "c"  # newest first
    assert r[1].to == "b"


def test_recent_on_missing_file_returns_empty(tmp_path, monkeypatch):
    """recent() on a missing file returns []."""
    monkeypatch.setenv("HOME", str(tmp_path))
    h = SwapHistory(Paths("token_slayer"))
    r = h.recent()
    assert r == []


def test_from_alias_round_trips(tmp_path, monkeypatch):
    """from field alias round-trips through append→recent."""
    monkeypatch.setenv("HOME", str(tmp_path))
    h = SwapHistory(Paths("token_slayer"))
    entry = SwapHistoryEntry(ts=1, from_="x", to="y")
    h.append(entry)
    r = h.recent(1)
    assert r[0].from_ == "x"
