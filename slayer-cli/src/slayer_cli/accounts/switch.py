"""The switch service: makes a stored slot the active Claude account. See
`.ai/domain/switching.md` for the authoritative pipeline contract."""
from __future__ import annotations
import os
import time
from slayer_cli import credstore
from slayer_cli.accounts import attribution
from slayer_cli.auth import beacon
from slayer_cli.accounts.history import SwapHistory
from slayer_cli.accounts.store import AccountStore
from slayer_cli.models.account import Account
from slayer_cli.models.history import SwapHistoryEntry
from slayer_cli.platform.paths import Paths

__all__ = ["switch_to", "SwapHistory"]


def switch_to(store: AccountStore, name: str, *, paths: Paths, force: bool = False) -> Account:
    """Make the `name` slot the active Claude account.

    Pipeline (each step only runs after the previous one succeeds, so a
    failed credential write never leaves `state.json`/`active.json`
    pointing at a credential that wasn't actually written): capture the
    outgoing (currently-active) slot's rotated live grant before switching
    away (preserves its refresh token), resolve the incoming slot's
    `org_uuid` (re-beaconing and persisting it back if missing), write the
    incoming slot as a full grant when it has a refresh token + expires_at
    (self-refreshing), else fall back to token-only write, patch
    `.claude.json`'s `.oauthAccount` block, set the active slot + touch its
    `last_used`, then reconcile the provider `active.json` attribution file
    — rewritten with the resolved org when known, or REMOVED when the org
    can't be resolved (never left stale, pointing at the previous account) —
    and finally append a history entry.

    :param store: Account slot store.
    :param name: Slot name to switch to.
    :param paths: Resolved OS paths for this namespace.
    :param force: When True, skip the rotation-capture step (recovery when the
        outgoing slot's live credentials are broken). Defaults to False.
    :return: The `Account` that is now active.
    :raises AccountNotFound: If no slot exists for `name`.
    :raises ValidationError: If `provider.writer.write_active` is invoked
        with a blank `org_uuid`.
    """
    acc = store.get(name)
    prev = store.active()

    # Capture rotation: if the outgoing (currently-active) slot exists, Claude
    # Code may have refreshed its token in place since we last switched to it.
    # Persist the live grant back onto that slot so its refresh token stays
    # valid across this switch (else the slot goes stale and loses self-refresh).
    # When force=True, skip this step entirely (recovery when live creds are broken).
    if not force and prev and prev != name and store.exists(prev):
        live = credstore.read_active_full(paths)
        if live and live.get("accessToken"):
            outgoing = store.get(prev)
            store.add(outgoing.model_copy(update={
                "token": live["accessToken"],
                "refresh_token": live.get("refreshToken") or outgoing.refresh_token,
                "expires_at": live.get("expiresAt"),
            }))

    # Re-beacon a missing org_uuid (a beacon failure at `add` time can bake
    # an org-less slot) and persist it back so the attribution file can be
    # written correctly rather than left stale.
    org = acc.org_uuid or beacon.resolve_org_uuid(acc.token)
    if org and org != acc.org_uuid:
        acc = acc.model_copy(update={"org_uuid": org})
        store.add(acc)

    # Write the incoming slot as a FULL grant (self-refreshing) when it has a
    # refresh token; otherwise fall back to the legacy null-refresh write.
    # (A captured live block lacking `expiresAt` would also fall back here,
    # but Claude reliably sets it, so this is defensive/theoretical.)
    if acc.refresh_token and acc.expires_at:
        credstore.write_active_full(paths, acc.token, acc.refresh_token, acc.expires_at)
    else:
        credstore.write_active_token(paths, acc.token)
    store.set_active(name)
    store.touch_last_used(name)
    # Reconcile the hook's attribution files (oauthAccount label + provider
    # active.json). Handles the org-unresolvable case (clears active.json +
    # warns) so usage is never misattributed to the previous slot's org.
    attribution.reconcile_active(paths, acc)
    SwapHistory(paths).append(
        SwapHistoryEntry(ts=int(time.time()), from_=prev, to=name, cwd=os.getcwd())
    )
    return acc
