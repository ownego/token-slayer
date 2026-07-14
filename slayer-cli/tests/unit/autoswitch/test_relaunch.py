from slayer_cli.autoswitch.relaunch import relaunch_argv, session_id_from, fibonacci_delay


def test_relaunch_strips_and_appends():
    out = relaunch_argv(["--model", "opus", "--resume", "old", "-p"], "sid",
                        auto_resume=True, auto_message="continue")
    assert "--resume" in out and out[out.index("--resume") + 1] == "sid"
    assert "old" not in out and "-p" not in out
    assert out[-1] == "continue"
    assert "--model" in out and out[out.index("--model") + 1] == "opus"   # non-session flag kept


def test_relaunch_silent_and_no_resume():
    out = relaunch_argv(["--model", "opus"], "sid", auto_resume=False, auto_message="")
    assert "--resume" not in out and out == ["--model", "opus"]


def test_session_id_from(tmp_path):
    (tmp_path / "aaa.jsonl").write_text("{}"); import time; time.sleep(0.01)
    (tmp_path / "bbb.jsonl").write_text("{}")
    assert session_id_from("", str(tmp_path)) == "bbb"                     # newest by mtime
    assert session_id_from("envid", str(tmp_path)) == "envid"             # env wins


def test_fibonacci():
    assert [fibonacci_delay(n) for n in range(5)] == [1, 1, 2, 3, 5]
