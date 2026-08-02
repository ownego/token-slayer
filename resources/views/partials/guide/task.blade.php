{{-- resources/views/partials/guide/task.blade.php --}}
<div class="border border-gray-200 rounded-lg mb-2 overflow-hidden">
    <button
        type="button"
        @click="openTask = (openTask === '{{ $topicKey }}-{{ $i }}') ? null : '{{ $topicKey }}-{{ $i }}'"
        class="cursor-pointer w-full flex items-center justify-between px-4 py-3 text-left text-sm font-semibold text-gray-800 hover:bg-gray-50 transition"
    >
        <span>{{ $task['q'] }}</span>
        <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0 transition-transform" :class="openTask === '{{ $topicKey }}-{{ $i }}' && 'rotate-90 text-orange-600'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
    </button>
    <div
        x-show="openTask === '{{ $topicKey }}-{{ $i }}'"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="px-4 py-3 bg-gray-50 border-t border-gray-200"
    >
        <pre class="bg-gray-900 text-amber-300 rounded-md p-2 text-xs overflow-x-auto mb-2 font-mono">{{ $task['cmd'] }}</pre>
        <p class="text-xs text-gray-600 leading-relaxed mb-2">{!! $task['desc'] !!}</p>
        <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Example</p>
        <pre class="bg-white border border-gray-200 rounded-md p-2 text-xs overflow-x-auto font-mono text-gray-800">{{ $task['example'] }}</pre>
        @if (! empty($task['tip']))
            <p class="text-[11px] text-blue-800 bg-blue-50 border border-blue-200 rounded-md px-2 py-1.5 mt-2">💡 {!! $task['tip'] !!}</p>
        @endif
    </div>
</div>
