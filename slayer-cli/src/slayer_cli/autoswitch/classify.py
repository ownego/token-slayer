"""Classify hook failure events as rate-limit vs API errors."""
from __future__ import annotations
import re
from slayer_cli.autoswitch import signals


# Rate-limit indicators (any event type).
_RATE_LIMIT_TOKENS = {
    "rate_limit",
    "rate limit",
    "session limit",
    "usage limit",
    "too many requests",
    "overloaded",
    "429",
}

# API-failure indicators (only for StopFailure events).
_API_FAILURE_TOKENS = {
    "api_error",
    "internal server error",
    "service unavailable",
    "bad gateway",
    "gateway timeout",
    "connection error",
    "connection refused",
    "connection reset",
    "connection closed",
    "timed out",
    "timeout",
    "network error",
    "fetch failed",
    "socket hang up",
}

# Regex for 5xx status codes (word-boundary protected to avoid false matches like "215000" matching "500").
_HTTP_5XX_PATTERN = re.compile(r"\b(500|502|503|504|529)\b")


def classify_failure(error_text: str, event_name: str) -> tuple[str | None, str]:
    """Classify a hook failure as rate-limit, API error, or neither.

    Returns a tuple of (signal_name, error_text) where signal_name is one of:
    - signals.RATE_LIMITED: detected rate-limit error (any event)
    - signals.TURN_FAILED: detected API failure (StopFailure only)
    - None: no signal (no recognized error pattern)

    :param error_text: The error message text.
    :param event_name: The hook event name (e.g., "StopFailure", "PostToolUseFailure").
    :return: Tuple of (signal_name or None, error_text if signal, empty string if not).
    """
    # Lowercase once for case-insensitive matching.
    text_lower = error_text.lower()

    # Check for rate-limit indicators first (any event).
    for token in _RATE_LIMIT_TOKENS:
        if token in text_lower:
            return (signals.RATE_LIMITED, error_text)

    # Check for API-failure indicators (StopFailure only).
    if event_name == "StopFailure":
        # Check substring tokens.
        for token in _API_FAILURE_TOKENS:
            if token in text_lower:
                return (signals.TURN_FAILED, error_text)
        # Check regex for 5xx codes.
        if _HTTP_5XX_PATTERN.search(text_lower):
            return (signals.TURN_FAILED, error_text)

    # No recognized pattern.
    return (None, "")
