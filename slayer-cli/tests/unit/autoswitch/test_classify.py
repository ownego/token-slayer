"""Test classify_failure function for rate-limit vs API-error detection."""
from slayer_cli.autoswitch.classify import classify_failure
from slayer_cli.autoswitch import signals


def test_rate_limit_variants():
    """Rate-limit detection on any event type."""
    for text in ["rate_limit_error", "hit your session limit", "usage limit reached", "HTTP 429", "overloaded_error"]:
        assert classify_failure(text, "PostToolUseFailure")[0] == signals.RATE_LIMITED


def test_turn_failed_only_on_stopfailure_and_api_pattern():
    """API-error detection only on StopFailure event with API-failure patterns."""
    assert classify_failure("internal server error", "StopFailure")[0] == signals.TURN_FAILED
    assert classify_failure("HTTP 503", "StopFailure")[0] == signals.TURN_FAILED
    # PostToolUseFailure with a tool's own timeout is NOT the API's problem:
    assert classify_failure("connection timed out", "PostToolUseFailure")[0] is None


def test_benign_text_no_signal():
    """Benign text with no rate-limit or API-failure tokens returns None."""
    assert classify_failure("discussing model latency", "Stop")[0] is None
    assert classify_failure("", "StopFailure")[0] is None
    assert classify_failure("some random message", "PostToolUseFailure")[0] is None
