{{-- The Minions tab (Character > Fighter | Minions): the pool on the left, the stage and the one thing you can do with the
     selected cell on the right (stacked on a phone). Built from the fighter editor's pieces (.cce upload form, .alert,
     .confirm-row) plus the pool classes at the end of fighter-sheet.css; listed in .ai/design/components.md.
     Picking a cell, ticking a look, Reset and the hotbar run in the browser (`minionPool` in sheet/minion-pool.js and
     `minionStage` in sheet/minion-stage.js), like the Fighter tab's roster: no request, no re-render. Every cell's panel
     is rendered here and shown by the pick; the stage is one element that follows it. Save, Remove, Replace, the upload
     and the preview actions are wire:click actions of this component. A change to the pool is announced as
     `custom-minions-changed {pool, changed}`; a result line as `sheet-toast`. --}}
{{-- No Alpine state on this root, for the reason given in custom-character-editor.blade.php: the browser state lives on
     .cme-body inside it (display: contents, so the root's grid still places the two sections). --}}
@use('App\Enums\MinionStageMode')
@php($id = $this->getId())
<div class="cce cme" id="cme-{{ $id }}">
    @error('action')
        <div class="alert crit" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <div class="cme-body" x-data="minionPool(@js(['selected' => $selected]))">
        <section class="cme-pool" aria-label="Your minion pool">
            <div class="cme-group">
                <div class="cce-head"><h3>Default</h3></div>
                <ul class="pool-grid" aria-label="Default minions">
                    @foreach ($pool['defaults'] as $cell)
                        <li class="pool-cell {{ $selected === $cell['look'] ? 'is-selected' : '' }} {{ $cell['ticked'] ? '' : 'is-off' }}" style="--i: {{ $loop->index }}" wire:key="cell-{{ $cell['look'] }}"
                            x-bind:class="{ 'is-selected': sel === @js($cell['look']), 'is-off': ! ticked(@js($cell['look'])) }">
                            <button type="button" class="pool-pick" id="cme-pick-{{ $cell['look'] }}-{{ $id }}" x-on:click="pick(@js($cell['look']))" aria-current="{{ $selected === $cell['look'] ? 'true' : 'false' }}" x-bind:aria-current="String(sel === @js($cell['look']))" aria-label="Show {{ $cell['label'] }} on the stage">
                                <span class="pool-thumb" aria-hidden="true"><span class="pool-sprite" wire:key="thumb-{{ $cell['look'] }}" x-data="minionThumb(@js($cell['idle']))" x-bind:style="style"></span></span>
                                <span class="pool-name">{{ $cell['label'] }}</span>
                            </button>
                            <button type="button" class="pool-tick" aria-pressed="{{ $cell['ticked'] ? 'true' : 'false' }}" x-bind:aria-pressed="String(ticked(@js($cell['look'])))" aria-label="{{ $cell['label'] }} in the pool" x-on:click="toggle(@js($cell['look']))">
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
                        @php($held = $cell['idle'] !== null && ! $preview)
                        <li class="pool-cell {{ $selected === $cell['look'] ? 'is-selected' : '' }} {{ $changed === $cell['look'] ? 'is-lit' : '' }} {{ $preview ? 'is-preview' : '' }} {{ $cell['idle'] === null && ! $preview ? 'is-empty' : '' }} {{ $held && ! $cell['ticked'] ? 'is-off' : '' }}" style="--i: {{ 5 + $loop->index }}" wire:key="cell-{{ $cell['look'] }}"
                            x-bind:class="{ 'is-selected': sel === @js($cell['look']) @if ($held), 'is-off': ! ticked(@js($cell['look'])) @endif }">
                            <button type="button" class="pool-pick" id="cme-pick-{{ $cell['look'] }}-{{ $id }}" x-on:click="pick(@js($cell['look']))" aria-current="{{ $selected === $cell['look'] ? 'true' : 'false' }}" x-bind:aria-current="String(sel === @js($cell['look']))"
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
                            @if ($held)
                                <button type="button" class="pool-tick" aria-pressed="{{ $cell['ticked'] ? 'true' : 'false' }}" x-bind:aria-pressed="String(ticked(@js($cell['look'])))" aria-label="{{ $cell['name'] }} in the pool" x-on:click="toggle(@js($cell['look']))">
                                    <span class="pool-tick-box" aria-hidden="true"></span>
                                    <span aria-hidden="true">In the pool</span>
                                </button>
                            @endif
                        </li>
                    @endforeach
                    @if ($pool['overflowDraft'])
                        @php($preview = $pool['overflowDraft'])
                        <li class="pool-cell is-preview {{ $selected === 'overflow' ? 'is-selected' : '' }}" style="--i: 8" wire:key="cell-overflow" x-bind:class="{ 'is-selected': sel === 'overflow' }">
                            <button type="button" class="pool-pick" id="cme-pick-overflow-{{ $id }}" x-on:click="pick('overflow')" aria-current="{{ $selected === 'overflow' ? 'true' : 'false' }}" x-bind:aria-current="String(sel === 'overflow')" aria-label="Preview {{ $preview['name'] }}">
                                <span class="pool-thumb" aria-hidden="true"><span class="pool-sprite" wire:key="thumb-overflow-{{ $preview['hash'] }}" x-data="minionThumb(@js($preview['spec']))" x-bind:style="style"></span></span>
                                <span class="pool-name">{{ $preview['name'] }}</span>
                                <span class="pool-badge">Preview</span>
                            </button>
                        </li>
                    @endif
                </ul>
            </div>

            <p class="cce-note" role="status" @if (! $pool['nothingTicked']) style="display: none" @endif x-show="nothingTicked">Nothing ticked: subagents summon the defaults.</p>

            <div class="equip-row">
                <span class="equip-note" id="cme-save-note-{{ $id }}" role="status" x-text="unsaved ? 'Not saved yet.' : 'Saved.'">Saved.</span>
                <button type="button" class="btn" id="cme-reset-{{ $id }}" style="display: none" x-show="unsaved" x-on:click="reset()" x-bind:disabled="saving">Reset</button>
                <button type="button" class="btn primary" id="cme-save-{{ $id }}" x-on:click="saveChoice()" disabled x-bind:disabled="! unsaved || saving" x-bind:aria-busy="String(saving)" aria-describedby="cme-save-note-{{ $id }}">
                    <span x-text="saving ? 'Saving…' : 'Save pool'">Save pool</span>
                </button>
            </div>
        </section>

        <section class="cme-panel" aria-labelledby="cme-stage-title-{{ $id }}">
            {{-- One stage for every cell: it follows the pick and the board without being drawn again by the server. --}}
            <div
                class="ms"
                wire:ignore
                @if ($stage->mode === MinionStageMode::Empty) style="display: none" @endif
                x-show="hasStage"
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
                    <span class="fx-circle"></span>
                    <span class="fx-burst"></span>
                    <span class="ms-sprite" x-bind:class="{ summon: summoning }" x-bind:style="spriteStyle"></span>
                </div>
                <div class="ms-moves" role="toolbar" x-bind:aria-label="(lookName || 'Minion') + ' moves'" aria-label="{{ $stage->name }} moves">
                    <template x-for="m in moves" x-bind:key="m.id">
                        <button type="button" class="btn ms-move" x-on:click="play(m.id)" x-bind:aria-pressed="move === m.id ? 'true' : 'false'" x-text="m.label"></button>
                    </template>
                </div>
                <div class="ms-field" role="group" x-bind:aria-label="(lookName || 'Minion') + ' at field size, beside your fighter'">
                    <span class="ms-field-cap" aria-hidden="true">At field size</span>
                    <span class="ms-sprite ms-fighter" aria-hidden="true" x-show="field.fighter" x-bind:style="field.fighter"></span>
                    <span class="ms-sprite ms-minion" aria-hidden="true" x-bind:style="field.minion"></span>
                </div>
            </div>

            @foreach ($stages as $look => $cellStage)
                @php($replacingThis = $cellStage->mode === MinionStageMode::Minion && $replacing === $cellStage->slot)
                <div class="cme-cell" wire:key="panel-{{ $look }}" @if ($look !== $selected) style="display: none" @endif x-show="sel === @js($look)">
                    <div class="ms-info" aria-live="polite">
                        <strong @if ($look === $selected) id="cme-stage-title-{{ $id }}" @endif>{{ $cellStage->mode === MinionStageMode::Empty ? 'Empty slot '.$cellStage->slot : $cellStage->name }}</strong>
                        @if ($cellStage->isPreview())
                            <span class="show-state previewing">Preview</span>
                        @elseif ($cellStage->isTickable())
                            <span class="show-state" x-bind:class="{ previewing: ! inPool(@js($look)) }" x-text="(inPool(@js($look)) ? 'In the pool' : 'Not in the pool') + (isChanged(@js($look)) ? ' · not saved' : '')">{{ $cellStage->ticked ? 'In the pool' : 'Not in the pool' }}</span>
                        @endif
                    </div>

                    @if ($cellStage->mode === MinionStageMode::Minion && ! $replacingThis)
                        @if ($confirming === 'slot-'.$cellStage->slot)
                            <div class="alert crit confirm-row" role="alertdialog" aria-label="Remove {{ $cellStage->name }}" wire:keydown.escape.stop.prevent="dismissConfirm">
                                <span>Remove {{ $cellStage->name }}? Your subagents stop summoning it.</span>
                                <button type="button" class="btn" x-init="$el.focus()" wire:click="dismissConfirm">Cancel</button>
                                <button type="button" class="btn blood" wire:click="removeMinion({{ $cellStage->slot }}, '{{ $pool['hashes'][$cellStage->slot] }}')" wire:loading.attr="disabled" wire:target="removeMinion">
                                    <span wire:loading.remove wire:target="removeMinion">Remove</span><span wire:loading wire:target="removeMinion">Removing…</span>
                                </button>
                            </div>
                        @else
                            <div class="cce-row ms-actions">
                                <button type="button" class="btn" id="cme-remove-open-{{ $cellStage->slot }}-{{ $id }}" wire:click="askToConfirm('slot-{{ $cellStage->slot }}')">Remove</button>
                                <button type="button" class="btn primary" id="cme-replace-open-{{ $cellStage->slot }}-{{ $id }}" wire:click="startReplace({{ $cellStage->slot }})">Replace</button>
                            </div>
                        @endif
                    @endif

                    @if ($replacingThis)
                        <div class="cce-head">
                            <h3>Replace {{ $cellStage->name }}</h3>
                            <button type="button" class="cce-link" wire:click="cancelReplace">Cancel</button>
                        </div>
                    @endif

                    @if ($cellStage->mode === MinionStageMode::Draft)
                        @php($draft = $cellStage->draft)
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

                    @if ($cellStage->mode === MinionStageMode::Overflow)
                        @php($draft = $cellStage->draft)
                        @php($heldCells = collect($pool['slots'])->filter(fn (array $cell): bool => $cell['idle'] !== null)->values())
                        <p class="cce-note" role="status">Your set filled up. Pick a minion to replace, or discard this preview.</p>
                        <div class="cce-row" role="group" aria-label="Minions you can replace">
                            @foreach ($heldCells as $cell)
                                <button type="button" class="btn" @if ($loop->first) id="cme-primary-{{ $id }}" @endif wire:click="retarget('{{ $draft['hash'] }}', {{ $cell['slot'] }})" wire:loading.attr="disabled" wire:target="retarget" aria-label="Replace {{ $cell['name'] }} with this preview">{{ $cell['name'] }}</button>
                            @endforeach
                        </div>
                        <div class="cce-row ms-actions">
                            <button type="button" class="btn" wire:click="discard('{{ $draft['hash'] }}')" wire:loading.attr="disabled" wire:target="discard">Discard</button>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- One upload form, for an empty slot or the minion being replaced. --}}
            @php($makeLooks = collect($stages)->filter(fn ($cellStage): bool => $cellStage->mode === MinionStageMode::Empty || ($cellStage->mode === MinionStageMode::Minion && $replacing === $cellStage->slot))->keys()->values()->all())
            <div class="cme-make" @if (! in_array($selected, $makeLooks, true)) style="display: none" @endif x-show="@js($makeLooks).includes(sel)">
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
        </section>
    </div>
</div>
