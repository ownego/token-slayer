{{-- Sits in the topbar next to the Battlefield link (USER_MENU_BEFORE), so an
     admin sees it wherever in the panel they land -- not just the Dashboard,
     and not buried in the page content where it would scroll out of view.

     Rose, not amber: the panel's own primary color IS amber (see
     AdminPanelProvider), so an amber badge here would blend into the topbar
     instead of reading as something that needs attention. Self-contained
     <style>, same reason as topbar-battlefield-link.blade.php -- the panel's
     Tailwind build only ships the utilities Filament itself scans for, so a
     one-off utility class here is not guaranteed to be compiled in. --}}
<style>
    .ts-hook-outdated-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.25rem;
        color: #fff;
        background-color: rgb(225 29 72);
        text-decoration: none;
        transition: background-color 75ms;
    }
    .ts-hook-outdated-badge:hover {
        background-color: rgb(190 18 60);
        color: #fff;
    }
    .ts-hook-outdated-badge:focus-visible {
        outline: 2px solid rgb(225 29 72);
        outline-offset: 2px;
    }
    .ts-hook-outdated-badge svg {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }
    @media (max-width: 639px) {
        .ts-hook-outdated-badge span {
            display: none;
        }
    }
</style>
@php($user = auth()->user())
@if ($user && \App\Support\HookVersionStatus::needsManualNudge($user, config('token_slayer.hook_version')))
    <a
        href="{{ route('update') }}"
        class="ts-hook-outdated-badge"
        title="Your hook is out of date -- usage is being recorded with less detail. Click to re-run the install command."
    >
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
        <span>Hook out of date</span>
    </a>
@endif
