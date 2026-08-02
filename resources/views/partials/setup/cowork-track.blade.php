{{-- resources/views/partials/setup/cowork-track.blade.php --}}
<div x-show="track === 'cowork'" x-cloak x-data="{ platform: null, plainTokenGenerated: false }">
    {{-- Step 2: platform (macOS/Windows only — Cowork has no Linux build) --}}
    <div x-show="step === 2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 2 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Chọn nền tảng</h2>
        <div class="grid grid-cols-2 gap-3">
            <button type="button" @click="platform = 'macos'; step = 3" class="border-2 rounded-lg py-4 text-sm font-semibold" :class="platform === 'macos' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">macOS</button>
            <button type="button" @click="platform = 'windows'; step = 3" class="border-2 rounded-lg py-4 text-sm font-semibold" :class="platform === 'windows' ? 'border-orange-500 text-orange-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'">Windows</button>
        </div>
    </div>

    {{-- Step 3: python note (no branch — cowork just needs any python3/python) --}}
    <div x-show="step === 3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 3 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Yêu cầu Python</h2>
        <p class="text-sm text-gray-600 mb-4">Watcher Cowork chỉ cần <b>bất kỳ</b> bản Python 3 nào đã cài sẵn trên máy — không đòi bản 3.10+ như track CLI, hầu hết máy đều có sẵn.</p>
        <button type="button" @click="step = 4" class="bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg">Tiếp tục →</button>
    </div>

    {{-- Step 4: install + verify + attribution caveat --}}
    <div x-show="step === 4">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 4 / 4</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Cài đặt</h2>

        <template x-if="!plainTokenGenerated">
            <button type="button" @click="$wire.generateToken().then(() => plainTokenGenerated = true)" class="bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg mb-2">Generate token</button>
        </template>
        <p class="text-xs text-amber-700 mb-4">Tạo token mới ở đây sẽ làm mất hiệu lực token cũ trên mọi máy khác (kể cả track Claude CLI trên chính máy này) đang dùng token đó.</p>

        <div class="bg-gray-900 text-amber-300 rounded-lg p-3 pr-24 relative font-mono text-sm cursor-pointer mb-2" @click="copy('cowork-install', '{{ addslashes($installCowork) }}')">
            {{ $installCowork }}
            <span class="absolute right-2 top-2 bg-gray-800 text-gray-300 text-xs font-semibold px-2 py-1 rounded" x-text="copied === 'cowork-install' ? 'Copied' : 'Copy'"></span>
        </div>
        <p x-show="platform === 'windows'" x-cloak class="text-xs text-amber-700 mb-4">Windows: tự động lên lịch chạy watcher chưa được hỗ trợ — bạn cần tự chạy lại lệnh trên theo chu kỳ.</p>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
            Cowork tính đúng token thật (Cowork chạy Claude Code thật trong VM, trừ đúng vào quota 5h/7d của account đó) — nhưng watcher <b>không gắn được account cụ thể</b>, nên usage luôn hiện là cá nhân trên <code>/profile</code> dù bạn dùng account nào trong Cowork.
        </div>
    </div>
</div>
