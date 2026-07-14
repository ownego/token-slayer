"""Typed domain errors."""
class SlayerError(Exception):
    """Base class for all slayer-cli errors."""

class AccountNotFound(SlayerError):
    """Requested account slot does not exist."""

class CredentialError(SlayerError):
    """Reading/writing the active credential failed."""

class UsageFetchError(SlayerError):
    """Fetching quota from Anthropic failed."""

class LoginError(SlayerError):
    """PKCE code exchange with Anthropic's OAuth token endpoint failed."""

class ProvisioningError(SlayerError):
    """Pulling admin-provisioned grants from the token-slayer server failed."""
