{{-- resources/views/partials/setup/chat-track.blade.php --}}
<div x-show="track === 'chat'" x-cloak>
    {{-- Step 2: install steps (no platform branch — browser-based) --}}
    <div x-show="step === 2">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 2 / 3</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Cài đặt</h2>
        <ol class="text-sm text-gray-700 list-decimal ml-5 space-y-2">
            <li>Cài <a href="https://chromewebstore.google.com/detail/tampermonkey/dhdgffkkebhmkfjojejmpbldmpobfkfo" target="_blank" rel="noopener" class="text-orange-600 underline">Tampermonkey</a> (hoặc Violentmonkey) trên trình duyệt.</li>
            <li><span class="font-medium">Chrome 138+:</span> mở <code>chrome://extensions</code> → Tampermonkey → <em>Details</em> → bật <span class="font-medium">Allow user scripts</span>. Thiếu bước này thì tracker cài xong nhưng không chạy.</li>
            <li><a href="{{ $userscriptUrl }}" class="text-orange-600 underline">Cài userscript tracker</a> — trình quản lý userscript sẽ hỏi xác nhận.</li>
        </ol>
        <button type="button" @click="step = 3" class="mt-4 bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-lg">Tiếp tục →</button>
    </div>

    {{-- Step 3: verify --}}
    <div x-show="step === 3">
        <p class="text-xs font-semibold text-orange-600 mb-1">Bước 3 / 3</p>
        <h2 class="text-xl font-bold text-gray-900 mb-4">Kiểm tra</h2>
        <p class="text-sm text-gray-600">Mở <a href="https://claude.ai" target="_blank" rel="noopener" class="text-orange-600 underline">claude.ai</a>, gửi 1 tin nhắn, đợi vài phút xem có được hỏi paste token không (chỉ hỏi 1 lần).</p>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mt-3">
            Claude Desktop chỉ được theo dõi khi <a href="https://claude.ai" target="_blank" rel="noopener" class="underline">claude.ai</a> đang mở trong trình duyệt này (đồng bộ ~1 phút/lần qua userscript). Chat ngay trên tab trình duyệt thì ghi nhận trong vài giây.
        </div>
    </div>
</div>
