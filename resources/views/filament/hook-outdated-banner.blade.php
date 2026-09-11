{{--
    Panel-wide nudge for a hook too old to self-update, rendered before the
    content of every admin page (App\Providers\Filament\AdminPanelProvider),
    not just the Dashboard -- some admins never open the Battlefield, where
    the equivalent banner already lives, so this is the only surface they see
    it on. Only reached by App\Support\HookVersionStatus::needsManualNudge():
    a hook that can report its own version already self-updates on its own
    and never sets this flag.
--}}
@php($user = auth()->user())
@if ($user && \App\Support\HookVersionStatus::needsManualNudge($user, config('token_slayer.hook_version')))
    <div class="mx-4 mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
        Your hook is out of date &mdash; usage is being recorded with less detail.
        <a href="{{ route('update') }}" class="underline hover:text-amber-950 dark:hover:text-amber-50">Re-run the install command</a>.
    </div>
@endif
