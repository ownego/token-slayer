{{-- resources/views/partials/setup/cli-track.blade.php --}}
<div x-show="track === 'cli'" x-cloak x-data="{ platform: null, pythonOk: null, pyFix: false, tokenSource: null, sourcePlatform: null, pastedToken: '', plainTokenGenerated: false }">
    {{-- Step 2: platform --}}
    <x-setup.step :n="2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 2 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Choose your platform</h2>
        <div class="grid grid-cols-3 gap-3">
            <button type="button" @click="platform = 'macos'; direction = 1; step = 3" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'macos' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">macOS</button>
            <button type="button" @click="platform = 'linux'; direction = 1; step = 3" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'linux' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Linux</button>
            <button type="button" @click="platform = 'windows'; direction = 1; step = 3" class="cursor-pointer border-2 rounded-lg py-4 text-sm font-semibold transition" :class="platform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Windows</button>
        </div>
        <button type="button" @click="direction = -1; track = null" class="cursor-pointer mt-4 text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>

    {{-- Step 3: python check --}}
    <x-setup.step :n="3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 3 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Check Python</h2>
        <p class="text-sm text-gray-500 mb-3">Copy the command, paste it in your terminal, check the result, then pick below.</p>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('py-check', 'python3 --version')">
            $ python3 --version
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" :class="copied === 'py-check' && 'bg-emerald-800 text-emerald-300'" x-text="copied === 'py-check' ? 'Copied' : 'Copy'"></span>
        </div>

        <div class="flex gap-3 mb-2">
            <button type="button" @click="pythonOk = true; pyFix = false; direction = 1; step = 4" class="cursor-pointer flex-1 border-2 border-gray-200 hover:border-emerald-400 hover:text-emerald-700 rounded-lg py-3 text-sm font-semibold text-gray-700 transition">Yes, 3.10–3.13</button>
            <button type="button" @click="pythonOk = false; pyFix = true" class="cursor-pointer flex-1 border-2 border-gray-200 hover:border-orange-400 hover:text-orange-700 rounded-lg py-3 text-sm font-semibold text-gray-700 transition">Other / 3.14 / error</button>
        </div>

        <div x-show="pyFix" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4 mt-2">
            <p class="text-xs font-bold text-gray-500 uppercase mb-3">Quick fix</p>

            <template x-if="platform === 'macos'">
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">1. Check Homebrew</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('brew-check', 'brew --version')">
                            $ brew --version
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'brew-check' ? 'Copied' : 'Copy'"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Shows <b>"Homebrew 4.x.x"</b> → already installed, skip step 2. Shows <b>"command not found"</b> → do step 2.</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">2. Install Homebrew <span class="font-normal text-gray-400">(only if step 1 said "not found")</span></p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('brew-install', '/bin/bash -c &quot;$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)&quot;')">
                            $ /bin/bash -c "$(curl -fsSL .../install.sh)"
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'brew-install' ? 'Copied' : 'Copy'"></span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">3. Install Python 3.12</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-install', 'brew install python@3.12')">
                            $ brew install python@3.12
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-install' ? 'Copied' : 'Copy'"></span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">4. Check again</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-recheck', 'python3.12 --version')">
                            $ python3.12 --version
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-recheck' ? 'Copied' : 'Copy'"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Use the versioned name (<b>python3.12</b>), not bare <b>python3</b> — Homebrew doesn't change the default <b>python3</b>, but the real installer finds <b>python3.12</b> on its own, so no alias and no shell config file edits needed.</p>
                    </div>
                </div>
            </template>

            <template x-if="platform === 'linux'">
                <div>
                    <p class="text-sm font-semibold text-gray-700 mb-1">Install Python 3.10+ with venv</p>
                    <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-linux', 'sudo apt install python3-venv')">
                        $ sudo apt install python3-venv
                        <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-linux' ? 'Copied' : 'Copy'"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Use dnf or pacman instead of apt if your machine isn't Debian/Ubuntu.</p>
                </div>
            </template>

            <template x-if="platform === 'windows'">
                <div>
                    <p class="text-sm font-semibold text-gray-700 mb-1">Install Python 3.12</p>
                    <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-win', 'winget install Python.Python.3.12')">
                        $ winget install Python.Python.3.12
                        <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-win' ? 'Copied' : 'Copy'"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">From python.org or winget — not the Microsoft Store version.</p>
                </div>
            </template>

            <button type="button" @click="pythonOk = true; direction = 1; step = 4" class="cursor-pointer mt-4 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition">Done, continue →</button>
        </div>

        <button type="button" @click="direction = -1; step = 2" class="cursor-pointer mt-4 text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>

    {{-- Step 4: token source --}}
    <x-setup.step :n="4">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 4 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Do you already have a token?</h2>

        <div class="grid gap-3 mb-3">
            <button type="button" @click="tokenSource = 'fresh'; direction = 1; step = 5" class="cursor-pointer text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4 transition">
                <div class="text-sm font-semibold text-gray-900">No, this is my first machine</div>
                <div class="text-xs text-gray-500">Next step will create a new token for you</div>
            </button>
            <button type="button" @click="tokenSource = 'here'; direction = 1" class="cursor-pointer text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4 transition">
                <div class="text-sm font-semibold text-gray-900">Yes, reinstalling/updating on this machine</div>
                <div class="text-xs text-gray-500">Check the current status first</div>
            </button>
            <button type="button" @click="tokenSource = 'elsewhere'; direction = 1" class="cursor-pointer text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4 transition">
                <div class="text-sm font-semibold text-gray-900">Yes, but on another machine (installing a 2nd machine)</div>
                <div class="text-xs text-gray-500">No new token — reuse the existing one</div>
            </button>
        </div>

        <div x-show="tokenSource === 'here'" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-gray-700 mb-1">Check whether this machine is already installed</p>
            <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('status-check', 'tok status')">
                $ tok status
                <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'status-check' ? 'Copied' : 'Copy'"></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">Says "command not found"? Treat it as not installed — go back and pick "No, this is my first machine" above.</p>
            <button type="button" @click="direction = 1; step = 5" class="cursor-pointer mt-3 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition">Continue →</button>
        </div>

        <div x-show="tokenSource === 'elsewhere'" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-gray-700 mb-2">The machine holding your token is:</p>
            <div class="flex gap-2 mb-3">
                <button type="button" @click="sourcePlatform = 'unix'" class="cursor-pointer flex-1 border-2 rounded-lg py-2 text-sm font-semibold transition" :class="sourcePlatform === 'unix' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700'">macOS / Linux</button>
                <button type="button" @click="sourcePlatform = 'windows'" class="cursor-pointer flex-1 border-2 rounded-lg py-2 text-sm font-semibold transition" :class="sourcePlatform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700'">Windows</button>
            </div>

            <div x-show="sourcePlatform === 'unix'" x-cloak>
                <p class="text-xs text-gray-500 mb-1">Run this on that machine:</p>
                <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('retrieve-unix', 'cat ~/.config/{{ $namespace }}/token')">
                    $ cat ~/.config/{{ $namespace }}/token
                    <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'retrieve-unix' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>
            <div x-show="sourcePlatform === 'windows'" x-cloak>
                <p class="text-xs text-gray-500 mb-1">Run this on that machine (PowerShell):</p>
                <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('retrieve-win', 'Get-Content $HOME\\.config\\{{ $namespace }}\\token')">
                    $ Get-Content $HOME\.config\{{ $namespace }}\token
                    <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'retrieve-win' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>

            <template x-if="sourcePlatform">
                <div class="mt-3">
                    <label class="text-xs text-gray-500 block mb-1">Paste the token you just retrieved here:</label>
                    <input type="text" x-model="pastedToken" placeholder="paste your token here" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
                    <button type="button" @click="direction = 1; step = 5" x-bind:disabled="!pastedToken" class="cursor-pointer mt-3 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition disabled:opacity-40 disabled:cursor-not-allowed">Continue →</button>
                </div>
            </template>
        </div>

        <button type="button" @click="direction = -1; step = 3" class="cursor-pointer mt-4 text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
    </x-setup.step>

    {{-- Step 5: install --}}
    <x-setup.step :n="5">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 5 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Install</h2>

        <template x-if="tokenSource === 'fresh' && !plainTokenGenerated">
            <button type="button" @click="$wire.generateToken().then(() => plainTokenGenerated = true)" class="cursor-pointer bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg mb-4 hover:bg-gray-700 transition">Generate token</button>
        </template>

        <template x-if="tokenSource === 'here' && (platform === 'macos' || platform === 'linux')">
            <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('install-here', 'curl -fsSL {{ route('install-script') }} | sh')">
                curl -fsSL {{ route('install-script') }} | sh
                <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install-here' ? 'Copied' : 'Copy'"></span>
            </div>
        </template>
        <template x-if="tokenSource === 'here' && platform === 'windows'">
            <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('install-here-ps', 'irm {{ route('install-script-ps1') }} | iex')">
                irm {{ route('install-script-ps1') }} | iex
                <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install-here-ps' ? 'Copied' : 'Copy'"></span>
            </div>
        </template>

        <template x-if="tokenSource !== 'here' && (platform === 'macos' || platform === 'linux')">
            <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('install', tokenSource === 'elsewhere' ? '{{ addslashes($installUnix) }}'.replace('<your-token>', pastedToken) : '{{ addslashes($installUnix) }}')">
                {{ $installUnix }}
                <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install' ? 'Copied' : 'Copy'"></span>
            </div>
        </template>
        <template x-if="tokenSource !== 'here' && platform === 'windows'">
            <div>
                <p class="text-xs text-gray-500 mb-1">PowerShell:</p>
                <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('install-ps', tokenSource === 'elsewhere' ? '{{ addslashes($installWinPs) }}'.replace('<your-token>', pastedToken) : '{{ addslashes($installWinPs) }}')">
                    {{ $installWinPs }}
                    <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install-ps' ? 'Copied' : 'Copy'"></span>
                </div>
                <p class="text-xs text-gray-500 mb-1">cmd.exe (don't mix with the PowerShell command above):</p>
                <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer" @click="copy('install-cmd', tokenSource === 'elsewhere' ? '{{ addslashes($installWinCmd) }}'.replace('<your-token>', pastedToken) : '{{ addslashes($installWinCmd) }}')">
                    {{ $installWinCmd }}
                    <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install-cmd' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>
        </template>

        <p class="text-xs text-gray-500 mt-2">A few seconds of silence after Enter is normal — it's installing, not frozen.</p>

        <details class="mt-4">
            <summary class="text-sm font-medium text-gray-600 cursor-pointer">Want to configure manually instead of running the script?</summary>
            <div class="mt-3 space-y-3">
                <div>
                    <p class="text-sm text-gray-600 mb-1">1. Save the token to <code>{{ $tokenPath }}</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $tokenSaveCommand }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">2. Paste into the top level of <code>~/.claude/settings.json</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $claudeSnippet }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">3. Paste/merge into <code>~/.codex/hooks.json</code>, then run <code>/hooks</code> inside Codex once to trust it:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $codexSnippet }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">4. Paste/merge into <code>~/.gemini/config/hooks.json</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $antigravitySnippet }}</pre>
                </div>
            </div>
        </details>

        <div class="flex items-center justify-between mt-4">
            <button type="button" @click="direction = -1; step = 4" class="cursor-pointer text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
            <button type="button" @click="direction = 1; step = 6" class="cursor-pointer bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-700 transition">Continue →</button>
        </div>
    </x-setup.step>

    {{-- Step 6: verify --}}
    <x-setup.step :n="6">
        <p class="text-xs font-semibold text-orange-600 mb-1">Step 6 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Verify & next steps</h2>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('verify', 'tok status')">
            $ tok status
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'verify' ? 'Copied' : 'Copy'"></span>
        </div>

        <p class="text-sm text-gray-500 mb-3">Every command here also has the full form <code>token-slayer</code>, in case <code>tok</code> isn't on PATH yet.</p>
        <p class="text-sm text-gray-500 mb-3">Have a company account? Run <code>tok setup</code>. Multiple personal accounts? <code>tok switch NAME</code>.</p>
        <div class="flex items-center justify-between">
            <button type="button" @click="direction = -1; step = 5" class="cursor-pointer text-sm font-semibold text-gray-500 hover:text-orange-600 transition inline-flex items-center gap-1">← Back</button>
            <a href="{{ route('guide') }}" class="text-sm text-orange-600 underline font-medium hover:text-orange-700">See the full tok command reference →</a>
        </div>
    </x-setup.step>
</div>
