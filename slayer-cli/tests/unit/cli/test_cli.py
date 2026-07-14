"""Tests for the Click CLI (`cli.main.main` + `cli/commands/*`)."""
from __future__ import annotations

from click.testing import CliRunner

from slayer_cli.accounts.store import AccountStore
from slayer_cli.errors import AccountNotFound
from slayer_cli.models.account import Account
from slayer_cli.platform.paths import Paths


def test_list_and_switch(tmp_path, monkeypatch):
    """`list` shows an added slot without ever printing its token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    r = CliRunner()
    from slayer_cli.cli.main import main

    AccountStore(Paths("token_slayer")).add(
        Account(name="oedev", email="a@b.com", org_uuid="o1", plan=None,
                token="sk-ant-oat01-x", added_at=1))
    out = r.invoke(main, ["list"])
    assert out.exit_code == 0 and "oedev" in out.output and "sk-ant-oat01" not in out.output


def test_hook_usage_refresh_is_unconditional(monkeypatch, tmp_path):
    """`hook usage-refresh` always calls the refresh (no TS_WRAPPED gate,
    unlike the other `hook` subcommands)."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("TS_WRAPPED", raising=False)
    called = {}
    monkeypatch.setattr(
        "slayer_cli.cli.commands.hook.stop_refresh.refresh_active_on_stop",
        lambda paths: called.setdefault("ran", True),
    )
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["hook", "usage-refresh"], input="{}")
    assert out.exit_code == 0
    assert called.get("ran")


def test_no_args_would_launch_tui(monkeypatch, tmp_path):
    """With no subcommand, the group callback launches the TUI."""
    monkeypatch.setenv("HOME", str(tmp_path))
    called = {}
    monkeypatch.setattr("slayer_cli.cli.commands.tui.launch", lambda paths: called.setdefault("t", True))
    from slayer_cli.cli.main import main

    CliRunner().invoke(main, [])
    assert called.get("t")


def test_add_snapshot_prints_success_without_token(tmp_path, monkeypatch):
    """`add <name>` (no --login) calls add_snapshot and never echoes the token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.commands import add as add_cmd
    from slayer_cli.cli.main import main

    fake_account = Account(name="oedev", email="a@b.com", org_uuid="o1", plan=None,
                            token="sk-ant-oat01-SECRET", added_at=1)
    monkeypatch.setattr(add_cmd, "add_snapshot", lambda store, paths, name: fake_account)

    out = CliRunner().invoke(main, ["add", "oedev"])
    assert out.exit_code == 0
    assert "oedev" in out.output
    assert "sk-ant-oat01" not in out.output


def test_add_login_prompts_for_code_and_never_prints_token(tmp_path, monkeypatch):
    """`add <name> --login` drives the PKCE code_provider prompt, never echoing the token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.commands import add as add_cmd
    from slayer_cli.cli.main import main

    fake_account = Account(name="oedev", email=None, org_uuid="o1", plan=None,
                            token="sk-ant-oat01-SECRET", added_at=1)

    captured = {}

    def fake_add_via_login(store, paths, name, code_provider):
        captured["url"] = code_provider("https://example.com/authorize")
        return fake_account

    monkeypatch.setattr(add_cmd, "add_via_login", fake_add_via_login)

    out = CliRunner().invoke(main, ["add", "oedev", "--login"], input="the-code#state\n")
    assert out.exit_code == 0
    assert "sk-ant-oat01" not in out.output
    assert captured["url"] == "the-code#state"


def test_switch_unknown_name_is_clean_error(tmp_path, monkeypatch):
    """`switch <unknown>` exits non-zero with a clean message, not a traceback."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["switch", "ghost"])
    assert out.exit_code != 0
    assert "Traceback" not in out.output


def test_switch_success_sets_active(tmp_path, monkeypatch):
    """`switch <name>` on a known slot reports success and updates the active slot."""
    monkeypatch.setenv("HOME", str(tmp_path))
    AccountStore(Paths("token_slayer")).add(
        Account(name="oedev", email="a@b.com", org_uuid="o1", plan=None,
                token="sk-ant-oat01-x", added_at=1))
    from slayer_cli.accounts import switch as switch_mod
    from slayer_cli.cli.main import main

    monkeypatch.setattr(switch_mod.credstore, "write_active_token", lambda paths, tok: None)
    monkeypatch.setattr(switch_mod.credstore.claude_json, "patch_oauth_account",
                        lambda paths, email, uuid, org: None)

    out = CliRunner().invoke(main, ["switch", "oedev"])
    assert out.exit_code == 0
    assert "oedev" in out.output
    assert AccountStore(Paths("token_slayer")).active() == "oedev"


def test_remove_unknown_name_is_clean_error(tmp_path, monkeypatch):
    """`remove <unknown>` exits non-zero with a clean message, not a traceback."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["remove", "ghost"])
    assert out.exit_code != 0
    assert "Traceback" not in out.output


def test_remove_the_active_slot_clears_the_active_pointer(tmp_path, monkeypatch):
    """`remove <active-slot>` doesn't leave a dangling active pointer -- no
    account stays marked active once its slot is gone."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    store = AccountStore(Paths("token_slayer"))
    store.add(Account(name="oedev", email="a@b.com", org_uuid="o1", plan=None,
                       token="sk-ant-oat01-x", added_at=1))
    store.set_active("oedev")

    out = CliRunner().invoke(main, ["remove", "oedev"])
    assert out.exit_code == 0
    assert store.active() is None


def test_list_empty_is_friendly(tmp_path, monkeypatch):
    """`list` with no slots prints a friendly message, not an empty table."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["list"])
    assert out.exit_code == 0
    assert "no account" in out.output.lower()


def test_current_with_no_active_account(tmp_path, monkeypatch):
    """`current` with nothing active reports "none"."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["current"])
    assert out.exit_code == 0
    assert "sk-ant-oat01" not in out.output


def test_status_never_prints_token(tmp_path, monkeypatch):
    """`status` prints version/namespace/active/login-state, never the token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    AccountStore(Paths("token_slayer")).add(
        Account(name="oedev", email="a@b.com", org_uuid="o1", plan=None,
                token="sk-ant-oat01-x", added_at=1))
    from slayer_cli import credstore
    from slayer_cli.cli.main import main

    monkeypatch.setattr(credstore, "read_active_token", lambda paths: "sk-ant-oat01-x")

    out = CliRunner().invoke(main, ["status"])
    assert out.exit_code == 0
    assert "sk-ant-oat01" not in out.output
    assert "logged in: yes" in out.output.lower() or "logged in: yes" in out.output


def test_uninstall_yes_runs_teardown_and_prints_summary_without_token(tmp_path, monkeypatch):
    """`uninstall --yes` skips the prompt, calls teardown.uninstall, and never
    echoes a token even if a fake summary carried one."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.commands import uninstall as uninstall_cmd
    from slayer_cli.cli.main import main
    from slayer_cli.teardown import UninstallSummary

    captured = {}

    def fake_uninstall(paths, *, keep_accounts=False):
        captured["keep_accounts"] = keep_accounts
        return UninstallSummary(
            credential_restored=True,
            removed=["venv", "shim (token-slayer)", "symlink (slayer)"],
            kept_accounts=keep_accounts,
        )

    monkeypatch.setattr(uninstall_cmd.teardown, "uninstall", fake_uninstall)

    out = CliRunner().invoke(main, ["uninstall", "--yes"])
    assert out.exit_code == 0
    assert captured["keep_accounts"] is False
    assert "sk-ant-oat01" not in out.output
    assert "restored" in out.output.lower()


def test_uninstall_declined_confirmation_does_not_run_teardown(tmp_path, monkeypatch):
    """`uninstall` without --yes, declined at the confirm prompt, exits 0
    without calling teardown.uninstall."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.commands import uninstall as uninstall_cmd
    from slayer_cli.cli.main import main

    called = {}
    monkeypatch.setattr(
        uninstall_cmd.teardown, "uninstall", lambda *a, **k: called.setdefault("hit", True)
    )

    out = CliRunner().invoke(main, ["uninstall"], input="n\n")
    assert out.exit_code == 0
    assert "hit" not in called
    assert "aborted" in out.output.lower()


def test_update_without_install_url_prints_guidance(tmp_path, monkeypatch):
    """`update` with SLAYER_INSTALL_URL unset prints guidance instead of failing."""
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.delenv("SLAYER_INSTALL_URL", raising=False)
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["update"])
    assert out.exit_code == 0
    assert "SLAYER_INSTALL_URL" in out.output


def test_setup_pulls_and_reports(tmp_path, monkeypatch):
    """`setup` reads the hook token, calls `provisioned.pull_and_setup`, and
    reports the account names it set up without ever printing a token."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.platform.paths import Paths

    p = Paths("token_slayer")
    p.config_dir.mkdir(parents=True, exist_ok=True)
    (p.config_dir / "token").write_text("HOOKTOK")
    import slayer_cli.cli.commands.setup as setup_cmd

    monkeypatch.setattr(setup_cmd.provisioned, "pull_and_setup", lambda paths, tok: ["a@b.com"])
    from click.testing import CliRunner

    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["setup"])
    assert out.exit_code == 0 and "a@b.com" in out.output and "sk-ant" not in out.output


def test_setup_without_hook_token_is_clean_error(tmp_path, monkeypatch):
    """`setup` with no hook token on disk exits non-zero with a clean
    message, not a traceback."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["setup"])
    assert out.exit_code != 0
    assert "Traceback" not in out.output


def test_setup_with_no_provisioned_accounts_is_friendly(tmp_path, monkeypatch):
    """`setup` reports a friendly message when the server has nothing to give."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.platform.paths import Paths

    p = Paths("token_slayer")
    p.config_dir.mkdir(parents=True, exist_ok=True)
    (p.config_dir / "token").write_text("HOOKTOK")
    import slayer_cli.cli.commands.setup as setup_cmd

    monkeypatch.setattr(setup_cmd.provisioned, "pull_and_setup", lambda paths, tok: [])
    from slayer_cli.cli.main import main

    out = CliRunner().invoke(main, ["setup"])
    assert out.exit_code == 0
    assert "no provisioned accounts" in out.output.lower()


def test_run_resolves_claude_on_path_and_delegates_to_wrapper(tmp_path, monkeypatch):
    """`run -- <args>` resolves `claude` via PATH, splits argv at `--`, and
    hands off to `wrapper.run`, exiting with its returned code."""
    monkeypatch.setenv("HOME", str(tmp_path))
    import shutil

    from slayer_cli.autoswitch import wrapper as wrapper_mod
    from slayer_cli.cli.main import main

    monkeypatch.setattr(shutil, "which", lambda name: "/usr/local/bin/claude" if name == "claude" else None)

    captured = {}

    def fake_wrapper_run(claude_bin, argv, services, **kw):
        captured["claude_bin"] = claude_bin
        captured["argv"] = argv
        return 3

    monkeypatch.setattr(wrapper_mod, "run", fake_wrapper_run)

    out = CliRunner().invoke(main, ["run", "--", "--model", "opus"])
    assert out.exit_code == 3
    assert captured["claude_bin"] == "/usr/local/bin/claude"
    assert captured["argv"] == ["--model", "opus"]


def test_run_without_claude_on_path_is_clean_error(tmp_path, monkeypatch):
    """`run` with no `claude` on PATH exits non-zero with a clean message."""
    monkeypatch.setenv("HOME", str(tmp_path))
    import shutil

    from slayer_cli.cli.main import main

    monkeypatch.setattr(shutil, "which", lambda name: None)

    out = CliRunner().invoke(main, ["run"])
    assert out.exit_code != 0
    assert "Traceback" not in out.output
    assert "claude" in out.output.lower()


def test_alias_command_sets_and_clears(tmp_path, monkeypatch):
    """`alias TARGET NAME` sets an alias (resolving TARGET by slot/alias/
    email); `alias TARGET` with no NAME clears it."""
    monkeypatch.setenv("HOME", str(tmp_path))
    store = AccountStore(Paths("token_slayer"))
    store.add(Account(name="acc1", email="a@b.com", token="sk-ant-oat01-TESTTOKEN", added_at=1))
    from slayer_cli.cli.main import main

    result1 = CliRunner().invoke(main, ["alias", "acc1", "work"])
    assert result1.exit_code == 0
    assert "sk-ant" not in result1.output
    assert store.get("acc1").alias == "work"
    result2 = CliRunner().invoke(main, ["alias", "work"])   # resolve by alias, then clear
    assert result2.exit_code == 0
    assert "sk-ant" not in result2.output
    assert store.get("acc1").alias is None


def test_detect_base_command_adds_current_login(tmp_path, monkeypatch):
    """`detect-base` registers the machine's current Claude login as a slot."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.accounts import base as base_mod
    from slayer_cli.models.account import Account

    detected = Account(name="me", email="me@x.com", uuid="u-1", org_uuid="org-1",
                       plan=None, token="sk-ant-oat01-CUR", added_at=1)
    monkeypatch.setattr(base_mod, "detect_current", lambda paths: detected)

    from slayer_cli.cli.main import main
    out = CliRunner().invoke(main, ["detect-base"])
    assert out.exit_code == 0
    assert "me@x.com" in out.output
    assert "sk-ant" not in out.output


def test_detect_base_command_is_friendly_with_no_login(tmp_path, monkeypatch):
    """`detect-base` with no active login prints a friendly message, not an error."""
    monkeypatch.setenv("HOME", str(tmp_path))
    from slayer_cli.accounts import base as base_mod
    monkeypatch.setattr(base_mod, "detect_current", lambda paths: None)

    from slayer_cli.cli.main import main
    out = CliRunner().invoke(main, ["detect-base"])
    assert out.exit_code == 0
    assert "No active Claude login" in out.output
