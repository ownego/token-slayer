"""The behaviour config shape. Defaults: manual strategy, reactive thresholds."""
from __future__ import annotations
from pydantic import BaseModel
from slayer_cli.models.usage_windows import Thresholds


class StrategyConfig(BaseModel):
    """Strategy configuration for automatic account switching.

    :var kind: Strategy type (manual, balanced, or drain).
    :var order: List of account names to switch through (if applicable).
    """
    kind: str = "manual"          # manual | balanced | drain
    order: list[str] = []


class Config(BaseModel):
    """User behaviour configuration loaded from config.json.

    :var thresholds: Usage thresholds that trigger account switches.
    :var strategy: Account switching strategy configuration.
    :var auto_switch_on_threshold: Automatically switch when thresholds are exceeded.
    :var auto_switch_on_rate_limit: Automatically switch on rate limit errors.
    :var auto_resume: Automatically resume on the current account after reset.
    :var auto_message: Message to display when auto-switching.
    :var wait_for_reset: Wait for rate limit reset before resuming.
    :var retry_on_api_error: Retry operations on API errors.
    """
    thresholds: Thresholds = Thresholds()
    strategy: StrategyConfig = StrategyConfig()
    auto_switch_on_threshold: bool = True
    auto_switch_on_rate_limit: bool = True
    auto_resume: bool = True
    auto_message: str = "continue"
    wait_for_reset: bool = True
    retry_on_api_error: bool = True
