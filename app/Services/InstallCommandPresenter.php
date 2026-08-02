<?php

namespace App\Services;

/**
 * Builds the copy-paste install/token commands shown on the setup wizard,
 * given a namespace and an already-resolved token value. Pure string
 * building — no I/O, no `route()` calls — so every variant is a plain Pest
 * Unit test.
 */
final class InstallCommandPresenter
{
    /**
     * Construct with the app's hook namespace and plaintext token value.
     *
     * @param  string  $namespace  the app's hook namespace (`config('app.hook_namespace')`)
     * @param  string  $tokenValue  the plaintext token to embed, or a display placeholder
     * @return void
     */
    public function __construct(
        private readonly string $namespace,
        private readonly string $tokenValue,
    ) {}

    /**
     * The environment variable name the install scripts read the token
     * from, e.g. `TOKEN_SLAYER_TOKEN` for the `token_slayer` namespace.
     *
     * @return string
     */
    public function envVar(): string
    {
        return strtoupper($this->namespace).'_TOKEN';
    }

    /**
     * Where the installer persists the token on disk.
     *
     * @return string
     */
    public function tokenPath(): string
    {
        return "~/.config/{$this->namespace}/token";
    }

    /**
     * macOS/Linux one-liner: pipes the install script through `sh` with the
     * token pre-set in the environment.
     *
     * @param  string  $installUrl  the `/install` route URL
     * @return string
     */
    public function cliUnix(string $installUrl): string
    {
        return "curl -fsSL {$installUrl} | {$this->envVar()}={$this->tokenValue} sh";
    }

    /**
     * Native PowerShell one-liner (no WSL) — sets the env var, then
     * downloads and executes the PowerShell installer.
     *
     * @param  string  $installPsUrl  the `/install.ps1` route URL
     * @return string
     */
    public function cliWindowsPowerShell(string $installPsUrl): string
    {
        return '$env:'.$this->envVar()."='{$this->tokenValue}'; irm {$installPsUrl} | iex";
    }

    /**
     * cmd.exe form: cmd doesn't expand `$env:VAR` and can't run `irm`/`iex`
     * directly, so this nests the PowerShell form inside a `powershell -c`
     * call instead.
     *
     * @param  string  $installPsUrl  the `/install.ps1` route URL
     * @return string
     */
    public function cliWindowsCmd(string $installPsUrl): string
    {
        return 'powershell -ExecutionPolicy ByPass -c "$env:'.$this->envVar()
            ."='{$this->tokenValue}'; irm {$installPsUrl} | iex\"";
    }

    /**
     * macOS/Windows one-liner for the Cowork watcher installer.
     *
     * @param  string  $coworkInstallUrl  the `/install-cowork` route URL
     * @return string
     */
    public function cowork(string $coworkInstallUrl): string
    {
        return "curl -fsSL {$coworkInstallUrl} | {$this->envVar()}={$this->tokenValue} sh";
    }

    /**
     * Manual fallback: creates the config directory, writes the token, and
     * locks its permissions to owner-only — used in the "paste by hand"
     * track and the "retrieve from another machine" flow's write-back step.
     *
     * @return string
     */
    public function tokenSave(): string
    {
        $path = $this->tokenPath();

        return "mkdir -p ~/.config/{$this->namespace} && printf '%s' '{$this->tokenValue}' > {$path} && chmod 600 {$path}";
    }
}
