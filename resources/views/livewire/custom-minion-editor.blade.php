{{-- The Minions tab (Character > Fighter | Minions): the pool on the left, the stage and the one thing you can do with the
     selected cell on the right (stacked on a phone). Built from the fighter editor's pieces (.cce upload form, .alert,
     .confirm-row) plus the pool classes at the end of fighter-sheet.css; listed in .ai/design/components.md.
     Every button is a wire:click of this component, so nothing waits for a browser event to reach a component that
     might not be mounted yet. What is selected, replaced or being confirmed lives in locked component state.
     A change to the pool is announced as `custom-minions-changed {pool, changed}`; a result line as `sheet-toast`.
     Ticking a look only changes the choice held in the component ($unsaved); Save is the one button that stores it,
     with the hash of every cell this view drew ($pool['hashes']) so a tab that drew an older set is turned away. --}}
{{-- No Alpine state on this root, for the reason given in custom-character-editor.blade.php: what is pending
     (a confirm row) lives in $confirming. --}}
@use('App\Enums\MinionStageMode')
@php($id = $this->getId())
<div class="cce cme" id="cme-{{ $id }}">
    @error('action')
        <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <section class="cme-pool" aria-label="Your minion pool">
        <div class="cme-group">
            <div class="cce-head"><h3>Default</h3></div>
            <ul class="pool-grid" aria-label="Default minions">
                @foreach ($pool['defaults'] as $cell)
                    <li class="pool-cell {{ $selected === $cell['look'] ? 'is-selected' : '' }} {{ $cell['ticked'] ? '' : 'is-off' }}" wire:key="cell-{{ $cell['look'] }}">
                        <button type="button" class="pool-pick" id="cme-pick-{{ $cell['look'] }}-{{ $id }}" wire:click="select('{{ $cell['look'] }}')" aria-current="{{ $selected === $cell['look'] ? 'true' : 'false' }}" aria-label="Show {{ $cell['label'] }} on the stage">
                            <span class="pool-thumb" aria-hidden="true"><span class="pool-sprite" wire:key="thumb-{{ $cell['look'] }}" x-data="minionThumb(@js($cell['idle']))" x-bind:style="style"></span></span>
                            <span class="pool-name">{{ $cell['label'] }}</span>
                        </button>
                        <button type="button" class="pool-tick" aria-pressed="{{ $cell['ticked'] ? 'true' : 'false' }}" aria-label="{{ $cell['label'] }} in the pool" wire:click="toggleTick('{{ $cell['look'] }}', {{ $cell['ticked'] ? 'false' : 'true' }})">
                            <span class="pool-tick-box" aria-hidden="true"></span>
                            <span aria-hidden="true">In the pool</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="cme-group">
            <div class="cce-head">
                <h3>Yours</h3>
                <span class="cce-note">{{ $pool['used'] }} of {{ $pool['max'] }}@if ($pool['used'] >= $pool['max']) · Replace one to change it @endif</span>
            </div>
            <ul class="pool-grid" aria-label="Your minions">
                @foreach ($pool['slots'] as $cell)
                    @php($preview = $cell['draft'])
                    <li class="pool-cell {{ $selected === $cell['look'] ? 'is-selected' : '' }} {{ $changed === $cell['look'] ? 'is-lit' : '' }} {{ $preview ? 'is-preview' : '' }} {{ $cell['idle'] === null && ! $preview ? 'is-empty' : '' }} {{ $cell['idle'] !== null && ! $cell['ticked'] && ! $preview ? 'is-off' : '' }}" wire:key="cell-{{ $cell['look'] }}">
                        <button type="button" class="pool-pick" id="cme-pick-{{ $cell['look'] }}-{{ $id }}" wire:click="select('{{ $cell['look'] }}')" aria-current="{{ $selected === $cell['look'] ? 'true' : 'false' }}"
                            aria-label="{{ $preview ? 'Preview '.$preview['name'] : ($cell['idle'] === null ? 'Empty slot '.$cell['slot'] : 'Show '.$cell['name'].' on the stage') }}">
                            <span class="pool-thumb" aria-hidden="true">
                                @if ($preview)
                                    <span class="pool-sprite" wire:key="thumb-{{ $cell['look'] }}-{{ $preview['hash'] }}" x-data="minionThumb(@js($preview['spec']))" x-bind:style="style"></span>
                                @elseif ($cell['idle'] !== null)
                                    <span class="pool-sprite" wire:key="thumb-{{ $cell['look'] }}-{{ $cell['idle']['version'] }}" x-data="minionThumb(@js($cell['idle']))" x-bind:style="style"></span>
                                @else
                                    <span class="pool-plus">+</span>
                                @endif
                            </span>
                            <span class="pool-name">{{ $preview ? $preview['name'] : ($cell['name'] ?? 'Empty slot') }}</span>
                            @if ($preview)
                                <span class="pool-badge">Preview</span>
                            @endif
                        </button>
                        @if ($cell['idle'] !== null && ! $preview)
                            <button type="button" class="pool-tick" aria-pressed="{{ $cell['ticked'] ? 'true' : 'false' }}" aria-label="{{ $cell['name'] }} in the pool" wire:click="toggleTick('{{ $cell['look'] }}', {{ $cell['ticked'] ? 'false' : 'true' }})">
                                <span class="pool-tick-box" aria-hidden="true"></span>
                                <span aria-hidden="true">In the pool</span>
                            </button>
                        @endif
                    </li>
                @endforeach
                @if ($pool['overflowDraft'])
                    @php($preview = $pool['overflowDraft'])
                    <li class="pool-cell is-preview {{ $selected === 'overflow' ? 'is-selected' : '' }}" wire:key="cell-overflow">
                        <button type="button" class="pool-pick" id="cme-pick-overflow-{{ $id }}" wire:click="select('overflow')" aria-current="{{ $selected === 'overflow' ? 'true' : 'false' }}" aria-label="Preview {{ $preview['name'] }}">
                            <span class="pool-thumb" aria-hidden="true"><span class="pool-sprite" wire:key="thumb-overflow-{{ $preview['hash'] }}" x-data="minionThumb(@js($preview['spec']))" x-bind:style="style"></span></span>
                            <span class="pool-name">{{ $preview['name'] }}</span>
                            <span class="pool-badge">Preview</span>
                        </button>
                    </li>
                @endif
            </ul>
        </div>

        @if ($pool['nothingTicked'])
            <p class="cce-note" role="status">Nothing ticked: subagents summon the defaults.@if ($unsaved) Not saved yet. @endif</p>
        @endif

        <div class="equip-row">
            <span class="equip-note" id="cme-save-note-{{ $id }}" role="status">{{ $unsaved ? 'Not saved yet.' : 'Saved.' }}</span>
            @if ($unsaved)
                <button type="button" class="btn" id="cme-reset-{{ $id }}" wire:click="resetPending" wire:loading.attr="disabled" wire:target="save">Reset</button>
            @endif
            <button type="button" class="btn primary" id="cme-save-{{ $id }}" wire:click="save(@js($pool['hashes']))" wire:loading.attr="disabled" wire:target="save" @disabled(! $unsaved) aria-describedby="cme-save-note-{{ $id }}">
                <span wire:loading.remove wire:target="save">Save pool</span><span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </section>

    <section class="cme-panel" aria-labelledby="cme-stage-title-{{ $id }}">
        @if ($stage->mode !== MinionStageMode::Empty)
            <div
                class="ms"
                wire:key="stage-{{ $stage->mode->value }}-{{ $stage->look }}-{{ $stage->spec['version'] ?? $stage->spec['key'] }}"
                x-data="minionStage(@js([
                    'look' => $stage->spec,
                    'lookKey' => $stage->look,
                    'preview' => $stage->isPreview(),
                    'summon' => $changed === $stage->look,
                    'equipped' => $fieldFighter['key'],
                    'custom' => $fieldFighter['custom'],
                ]))"
            >
                <div class="ms-stage" aria-hidden="true">
                    <span class="ms-sprite" x-bind:class="{ summon: summoning }" x-bind:style="spriteStyle"></span>
                </div>
                <div class="ms-moves" role="toolbar" aria-label="{{ $stage->name }} moves">
                    <template x-for="m in moves" x-bind:key="m.id">
                        <button type="button" class="btn ms-move" x-on:click="play(m.id)" x-bind:aria-pressed="move === m.id ? 'true' : 'false'" x-text="m.label"></button>
                    </template>
                </div>
                <div class="ms-field" role="group" aria-label="{{ $stage->name }} at field size, beside your fighter">
                    <span class="ms-field-cap" aria-hidden="true">At field size</span>
                    <span class="ms-sprite ms-fighter" aria-hidden="true" x-show="field.fighter" x-bind:style="field.fighter"></span>
                    <span class="ms-sprite ms-minion" aria-hidden="true" x-bind:style="field.minion"></span>
                </div>
            </div>
        @endif

        <div class="ms-info">
            <strong id="cme-stage-title-{{ $id }}">{{ $stage->mode === MinionStageMode::Empty ? 'Empty slot '.$stage->slot : $stage->name }}</strong>
            @if ($stage->isPreview())
                <span class="show-state previewing">Preview</span>
            @elseif ($stage->isTickable())
                @php($inPool = $stage->ticked || ($stage->mode === MinionStageMode::Default && $pool['nothingTicked']))
                <span class="show-state {{ $inPool ? '' : 'previewing' }}">{{ $inPool ? 'In the pool' : 'Not in the pool' }}@if ($stageUnsaved) · not saved @endif</span>
            @endif
        </div>

        @if ($stage->mode === MinionStageMode::Minion && ! $replacingSelected)
            @if ($confirmingSelected)
                <div class="alert crit confirm-row" role="alertdialog" aria-label="Remove {{ $stage->name }}" wire:keydown.escape.stop.prevent="dismissConfirm">
                    <span>Remove {{ $stage->name }}? Your subagents stop summoning it.</span>
                    <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                    <button type="button" class="btn blood" wire:click="removeMinion({{ $stage->slot }}, '{{ $pool['hashes'][$stage->slot] }}')" wire:loading.attr="disabled" wire:target="removeMinion">
                        <span wire:loading.remove wire:target="removeMinion">Remove</span><span wire:loading wire:target="removeMinion">Removing…</span>
                    </button>
                </div>
            @else
                <div class="cce-row ms-actions">
                    <button type="button" class="btn" id="cme-remove-open-{{ $id }}" wire:click="askToConfirm('slot-{{ $stage->slot }}')">Remove</button>
                    <button type="button" class="btn primary" id="cme-replace-open-{{ $id }}" wire:click="startReplace({{ $stage->slot }})">Replace</button>
                </div>
            @endif
        @endif

        @if ($stage->mode === MinionStageMode::Empty || ($stage->mode === MinionStageMode::Minion && $replacingSelected))
            <div class="cme-make">
                @if ($replacingSelected)
                    <div class="cce-head">
                        <h3>Replace {{ $stage->name }}</h3>
                        <button type="button" class="cce-link" wire:click="cancelReplace">Cancel</button>
                    </div>
                @endif

                {{-- Choosing a file (and sending it to the server) is one step; building the preview from it is the next. --}}
                <form
                    wire:key="upload-form"
                    x-data="{ phase: 'idle', over: false, thumb: null, info: null, baseInfo: null, thumbError: false, progress: 0, busy() { return this.phase === 'uploading' || this.phase === 'building'; }, choose(file) { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = file ? URL.createObjectURL(file) : null; this.baseInfo = file ? file.name + ' · ' + Math.ceil(file.size / 1024) + ' KB' : null; this.info = this.baseInfo; this.thumbError = false; this.phase = file ? 'uploading' : 'idle'; }, drop(event) { this.over = false; if (this.busy() || ! event.dataTransfer.files.length) return; this.$refs.file.files = event.dataTransfer.files; this.$refs.file.dispatchEvent(new Event('change', { bubbles: true })); }, showDimensions(image) { if (this.baseInfo) this.info = this.baseInfo + ' · ' + image.naturalWidth + '×' + image.naturalHeight; }, clearThumb() { if (this.thumb) URL.revokeObjectURL(this.thumb); this.thumb = null; this.baseInfo = null; this.info = null; }, destroy() { this.clearThumb(); } }"
                    x-on:livewire-upload-start="phase = 'uploading'; progress = 0"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                    x-on:livewire-upload-finish="phase = 'ready'"
                    x-on:livewire-upload-error="phase = 'failed'"
                    x-on:submit.prevent="phase = 'building'; $wire.build().finally(() => { phase = 'idle'; clearThumb(); })"
                    class="cce-form"
                    data-minion-add="ready"
                >
                    <input type="file" wire:model="sheet" x-ref="file" x-on:change="choose($event.target.files[0] ?? null)" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1">

                    <div class="cce-pick" x-show="phase === 'idle' || phase === 'failed'">
                        <div
                            class="cce-drop"
                            x-bind:class="{ over: over }"
                            x-on:dragover.prevent="over = true"
                            x-on:dragleave="over = false"
                            x-on:drop.prevent="drop($event)"
                        >
                            <svg class="cce-drop-icon" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" /></svg>
                            <p class="cce-drop-title">Drop a pose sheet here</p>
                            <button type="button" x-on:click="$refs.file.click()" class="btn primary" id="cme-upload-{{ $id }}">Upload sheet</button>
                            <ul class="cce-chips" aria-label="File requirements">
                                <li>PNG · JPG · WebP</li>
                                <li>≤ 4 MB</li>
                                <li>≤ 2048 px</li>
                                <li>magenta or transparent</li>
                            </ul>
                        </div>
                        <a class="panel-link cce-guide" href="{{ route('custom-character') }}" target="_blank" rel="noopener">Need a sheet? Open the prompt guide &#8599;</a>
                    </div>

                    <div class="cce-filecard" x-show="info && (phase === 'uploading' || phase === 'ready' || phase === 'building')" x-cloak>
                        <div class="cce-thumb">
                            <img x-bind:src="thumb" x-on:load="showDimensions($el)" x-on:error="thumbError = true" alt="Selected pose sheet thumbnail">
                        </div>
                        <div class="cce-fileinfo">
                            <span class="cce-file" x-text="info"></span>
                            <span x-show="thumbError" role="status" class="cce-note">This file can't be previewed</span>
                            <div x-show="phase === 'uploading'" role="status">
                                <span class="cce-note">Uploading <span x-text="progress + '%'"></span></span>
                                <div class="upload-bar"><i x-bind:style="{ '--p': progress / 100 }"></i></div>
                            </div>
                            <div x-show="phase === 'building'" role="status">
                                <span class="cce-note">Building…</span>
                                <div class="build-bar"><i></i></div>
                            </div>
                            <button type="button" class="cce-link" x-show="phase === 'ready'" x-on:click="$refs.file.click()">Choose another file</button>
                        </div>
                        <button type="submit" x-bind:disabled="phase !== 'ready'" class="btn primary cce-build">
                            <span x-text="phase === 'building' ? 'Building…' : 'Build preview'">Build preview</span>
                        </button>
                    </div>

                    @error('sheet')
                        <div class="alert crit" role="alert" x-init="phase = 'failed'"><span>{{ $message }}</span></div>
                    @enderror
                </form>

                <div class="cme-or" aria-hidden="true">or</div>

                <div class="cce-row">
                    @if ($fighterOffer['reason'] === null)
                        <button type="button" class="btn" data-minion-add-fighter="ready" wire:click="useFighter" wire:loading.attr="disabled" wire:target="useFighter">
                            <span wire:loading.remove wire:target="useFighter">Use my fighter</span><span wire:loading wire:target="useFighter">Building…</span>
                        </button>
                    @else
                        <button type="button" class="btn" disabled data-minion-add-fighter="disabled" aria-describedby="cme-fighter-reason-{{ $id }}">Use my fighter</button>
                        <span class="cce-note" id="cme-fighter-reason-{{ $id }}">{{ $fighterOffer['reason'] }}</span>
                    @endif
                </div>
            </div>
        @endif

        @if ($stage->mode === MinionStageMode::Draft)
            @php($draft = $stage->draft)
            <p class="cce-note">Not in the pool until you add it.</p>
            <div class="cce-row ms-actions">
                <button type="button" class="btn" wire:click="discard('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="discard">Discard</button>
                <button type="button" class="btn primary" id="cme-primary-{{ $id }}" wire:click="confirm('{{ $draft['hash'] }}', {{ $draft['slot'] ?? 'null' }})" wire:loading.attr="disabled" wire:target="confirm">
                    @if ($draft['replaces'] !== null)
                        <span wire:loading.remove wire:target="confirm">Replace {{ $draft['replaces'] }}</span><span wire:loading wire:target="confirm">Replacing…</span>
                    @else
                        <span wire:loading.remove wire:target="confirm">Add to minions</span><span wire:loading wire:target="confirm">Adding…</span>
                    @endif
                </button>
            </div>
        @endif

        @if ($stage->mode === MinionStageMode::Overflow)
            @php($draft = $stage->draft)
            @php($held = collect($pool['slots'])->filter(fn (array $cell): bool => $cell['idle'] !== null)->values())
            <p class="cce-note" role="status">Your set filled up. Pick a minion to replace, or discard this preview.</p>
            <div class="cce-row" role="group" aria-label="Minions you can replace">
                @foreach ($held as $cell)
                    <button type="button" class="btn" @if ($loop->first) id="cme-primary-{{ $id }}" @endif wire:click="retarget('{{ $draft['hash'] }}', {{ $cell['slot'] }})" wire:loading.attr="disabled" wire:target="retarget" aria-label="Replace {{ $cell['name'] }} with this preview">{{ $cell['name'] }}</button>
                @endforeach
            </div>
            <div class="cce-row ms-actions">
                <button type="button" class="btn" wire:click="discard('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="discard">Discard</button>
            </div>
        @endif
    </section>
</div>
