{{-- resources/views/partials/setup/cli-track.blade.php --}}
<div x-show="track === 'cli'" x-cloak x-data="{ platform: null, pythonOk: null, pyFix: false, tokenSource: null, sourcePlatform: null, pastedToken: '', plainTokenGenerated: false }">
    {{-- Step 2: platform --}}
    <div x-show="step === 2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 2 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Chọn nền tảng</h2>
        <div class="grid grid-cols-3 gap-3">
            <button type="button" @click="platform = 'macos'; step = 3" class="border-2 rounded-lg py-4 text-sm font-semibold" :class="platform === 'macos' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">macOS</button>
            <button type="button" @click="platform = 'linux'; step = 3" class="border-2 rounded-lg py-4 text-sm font-semibold" :class="platform === 'linux' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Linux</button>
            <button type="button" @click="platform = 'windows'; step = 3" class="border-2 rounded-lg py-4 text-sm font-semibold" :class="platform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Windows</button>
        </div>
    </div>

    {{-- Step 3: python check --}}
    <div x-show="step === 3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 3 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Kiểm tra Python</h2>
        <p class="text-sm text-gray-500 mb-3">Copy lệnh, dán vào terminal, xem kết quả rồi chọn bên dưới.</p>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('py-check', 'python3 --version')">
            $ python3 --version
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" :class="copied === 'py-check' && 'bg-emerald-800 text-emerald-300'" x-text="copied === 'py-check' ? 'Copied' : 'Copy'"></span>
        </div>

        <div class="flex gap-3 mb-2">
            <button type="button" @click="pythonOk = true; pyFix = false; step = 4" class="flex-1 border-2 border-gray-200 hover:border-emerald-400 hover:text-emerald-700 rounded-lg py-3 text-sm font-semibold text-gray-700">Có, 3.10–3.13</button>
            <button type="button" @click="pythonOk = false; pyFix = true" class="flex-1 border-2 border-gray-200 hover:border-orange-400 hover:text-orange-700 rounded-lg py-3 text-sm font-semibold text-gray-700">Khác / 3.14 / lỗi</button>
        </div>

        <div x-show="pyFix" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4 mt-2">
            <p class="text-xs font-bold text-gray-500 uppercase mb-3">Fix nhanh</p>

            <template x-if="platform === 'macos'">
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">1. Kiểm tra Homebrew</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('brew-check', 'brew --version')">
                            $ brew --version
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'brew-check' ? 'Copied' : 'Copy'"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Hiện <b>"Homebrew 4.x.x"</b> → có rồi, bỏ qua bước 2. Hiện <b>"command not found"</b> → làm bước 2.</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">2. Cài Homebrew <span class="font-normal text-gray-400">(chỉ khi bước 1 báo "not found")</span></p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('brew-install', '/bin/bash -c &quot;$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)&quot;')">
                            $ /bin/bash -c "$(curl -fsSL .../install.sh)"
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'brew-install' ? 'Copied' : 'Copy'"></span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">3. Cài Python 3.12</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-install', 'brew install python@3.12')">
                            $ brew install python@3.12
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-install' ? 'Copied' : 'Copy'"></span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">4. Check lại</p>
                        <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-recheck', 'python3.12 --version')">
                            $ python3.12 --version
                            <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-recheck' ? 'Copied' : 'Copy'"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Dùng tên có version (<b>python3.12</b>), không phải <b>python3</b> trần — Homebrew không tự đổi <b>python3</b> mặc định, nhưng installer thật tự tìm đúng <b>python3.12</b> nên không cần alias, không cần sửa file cấu hình shell.</p>
                    </div>
                </div>
            </template>

            <template x-if="platform === 'linux'">
                <div>
                    <p class="text-sm font-semibold text-gray-700 mb-1">Cài Python 3.10+ với venv</p>
                    <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-linux', 'sudo apt install python3-venv')">
                        $ sudo apt install python3-venv
                        <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-linux' ? 'Copied' : 'Copy'"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Dùng dnf hoặc pacman thay apt nếu máy bạn không phải Debian/Ubuntu.</p>
                </div>
            </template>

            <template x-if="platform === 'windows'">
                <div>
                    <p class="text-sm font-semibold text-gray-700 mb-1">Cài Python 3.12</p>
                    <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('py-win', 'winget install Python.Python.3.12')">
                        $ winget install Python.Python.3.12
                        <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'py-win' ? 'Copied' : 'Copy'"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Từ python.org hoặc winget, không dùng bản Microsoft Store.</p>
                </div>
            </template>

            <button type="button" @click="pythonOk = true; step = 4" class="mt-4 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg">Xong, tiếp tục →</button>
        </div>
    </div>

    {{-- Step 4: token source --}}
    <div x-show="step === 4">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 4 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Bạn đã có token-slayer token chưa?</h2>

        <div class="grid gap-3 mb-3">
            <button type="button" @click="tokenSource = 'fresh'; step = 5" class="text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4">
                <div class="text-sm font-semibold text-gray-900">Chưa, đây là máy đầu tiên</div>
                <div class="text-xs text-gray-500">Bước tiếp theo sẽ tạo token mới cho bạn</div>
            </button>
            <button type="button" @click="tokenSource = 'here'; step = 5" class="text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4">
                <div class="text-sm font-semibold text-gray-900">Rồi, đang cài lại/update trên máy này</div>
                <div class="text-xs text-gray-500">Check trạng thái hiện tại trước</div>
            </button>
            <button type="button" @click="tokenSource = 'elsewhere'" class="text-left border-2 border-gray-200 hover:border-orange-400 rounded-lg p-4">
                <div class="text-sm font-semibold text-gray-900">Rồi, nhưng ở máy khác (đang cài máy thứ 2)</div>
                <div class="text-xs text-gray-500">Không tạo token mới — dùng lại token cũ</div>
            </button>
        </div>

        <div x-show="tokenSource === 'here'" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-gray-700 mb-1">Kiểm tra máy này đã cài chưa</p>
            <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('status-check', 'token-slayer status')">
                $ token-slayer status
                <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'status-check' ? 'Copied' : 'Copy'"></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">Báo "command not found"? Coi như chưa cài — chọn lại "Chưa, đây là máy đầu tiên" ở trên.</p>
            <button type="button" @click="step = 5" class="mt-3 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg">Tiếp tục →</button>
        </div>

        <div x-show="tokenSource === 'elsewhere'" x-cloak class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-gray-700 mb-2">Máy đang giữ token là:</p>
            <div class="flex gap-2 mb-3">
                <button type="button" @click="sourcePlatform = 'unix'" class="flex-1 border-2 rounded-lg py-2 text-sm font-semibold" :class="sourcePlatform === 'unix' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700'">macOS / Linux</button>
                <button type="button" @click="sourcePlatform = 'windows'" class="flex-1 border-2 rounded-lg py-2 text-sm font-semibold" :class="sourcePlatform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700'">Windows</button>
            </div>

            <div x-show="sourcePlatform === 'unix'" x-cloak>
                <p class="text-xs text-gray-500 mb-1">Chạy trên máy đó:</p>
                <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('retrieve-unix', 'cat ~/.config/{{ $namespace }}/token')">
                    $ cat ~/.config/{{ $namespace }}/token
                    <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'retrieve-unix' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>
            <div x-show="sourcePlatform === 'windows'" x-cloak>
                <p class="text-xs text-gray-500 mb-1">Chạy trên máy đó (PowerShell):</p>
                <div class="bg-gray-900 text-amber-300 rounded-md p-2 pr-20 relative font-mono text-xs cursor-pointer" @click="copy('retrieve-win', 'Get-Content $HOME\\.config\\{{ $namespace }}\\token')">
                    $ Get-Content $HOME\.config\{{ $namespace }}\token
                    <span class="absolute right-2 top-1.5 bg-gray-800 text-gray-300 text-[10px] font-semibold px-1.5 py-0.5 rounded" x-text="copied === 'retrieve-win' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>

            <template x-if="sourcePlatform">
                <div class="mt-3">
                    <label class="text-xs text-gray-500 block mb-1">Dán token vừa lấy được vào đây:</label>
                    <input type="text" x-model="pastedToken" placeholder="token dán vào đây" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
                    <button type="button" @click="step = 5" x-bind:disabled="!pastedToken" class="mt-3 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg disabled:opacity-40">Tiếp tục →</button>
                </div>
            </template>
        </div>
    </div>

    {{-- Step 5: install --}}
    <div x-show="step === 5">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 5 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Cài đặt</h2>

        <template x-if="tokenSource === 'fresh' && !plainTokenGenerated">
            <button type="button" @click="$wire.generateToken().then(() => plainTokenGenerated = true)" class="bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg mb-4">Generate token</button>
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
                <p class="text-xs text-gray-500 mb-1">cmd.exe (không dùng lẫn với PowerShell ở trên):</p>
                <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer" @click="copy('install-cmd', tokenSource === 'elsewhere' ? '{{ addslashes($installWinCmd) }}'.replace('<your-token>', pastedToken) : '{{ addslashes($installWinCmd) }}')">
                    {{ $installWinCmd }}
                    <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'install-cmd' ? 'Copied' : 'Copy'"></span>
                </div>
            </div>
        </template>

        <p class="text-xs text-gray-500 mt-2">Terminal im lặng vài giây sau khi Enter là bình thường — đang cài, không phải treo.</p>

        <details class="mt-4">
            <summary class="text-sm font-medium text-gray-600 cursor-pointer">Muốn cấu hình thủ công thay vì chạy script?</summary>
            <div class="mt-3 space-y-3">
                <div>
                    <p class="text-sm text-gray-600 mb-1">1. Lưu token vào <code>{{ $tokenPath }}</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $tokenSaveCommand }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">2. Dán vào <code>~/.claude/settings.json</code> ở cấp cao nhất:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $claudeSnippet }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">3. Thêm vào <code>~/.codex/config.toml</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $codexSnippet }}</pre>
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-1">4. Dán/merge vào <code>~/.gemini/config/hooks.json</code>:</p>
                    <pre class="bg-gray-900 text-amber-300 rounded-lg p-3 text-xs overflow-x-auto">{{ $antigravitySnippet }}</pre>
                </div>
            </div>
        </details>

        <button type="button" @click="step = 6" class="mt-4 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg">Tiếp tục →</button>
    </div>

    {{-- Step 6: verify --}}
    <div x-show="step === 6">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 6 / 6</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Kiểm tra & bước tiếp theo</h2>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-3" @click="copy('verify', 'token-slayer status')">
            $ token-slayer status
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'verify' ? 'Copied' : 'Copy'"></span>
        </div>

        <p class="text-sm text-gray-500 mb-3">Bản mới có alias ngắn <code>tok</code> — nếu gõ <code>tok</code> không ra gì, dùng <code>token-slayer</code> đầy đủ.</p>
        <p class="text-sm text-gray-500 mb-3">Có account công ty? Chạy <code>token-slayer setup</code>. Nhiều account cá nhân? <code>token-slayer switch NAME</code>.</p>
        <a href="{{ route('guide') }}" class="text-sm text-orange-600 underline font-medium">Xem toàn bộ lệnh token-slayer →</a>
    </div>
</div>
