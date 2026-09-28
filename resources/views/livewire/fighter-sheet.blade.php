<div>
    @include('livewire.fighter-sheet.attribution')

    {{-- Always in the page: shows the moment the nav asks for the sheet (the
         runner loader before the first render, the last-loaded sheet after
         that) and plays its own enter/exit instead of popping in and out with
         the server's round trip. --}}
    <div
        x-data="sheetFrame()"
        wire:ignore.self
        x-show="shown"
        x-cloak
        @open-fighter-sheet.window="show()"
        @fighter-sheet-hide.window="hide()"
        @fighter-sheet-ready.window="finish()"
        x-transition:enter="fs-frame-enter"
        x-transition:enter-start="fs-frame-from"
        x-transition:enter-end="fs-frame-to"
        x-transition:leave="fs-frame-leave"
        x-transition:leave-start="fs-frame-to"
        x-transition:leave-end="fs-frame-from"
    >
    {{-- The first open's loader: outside the @if, so the first render's morph
         can't swap it out mid-run; it gives way once the runner reaches the end. --}}
    <div x-show="loading" wire:ignore>
        @include('livewire.fighter-sheet.skeleton')
    </div>
    @if ($open)
        {{-- Markup ported from the approved mockup (docs/superpowers/mockups/
             2026-09-27-battlefield-redesign/fighter-sheet.html); its CSS lives
             in resources/css/fighter-sheet.css, scoped under .fs. --}}
        <div
            x-data="fighterSheetShell({{ auth()->id() }})"
            x-show="!loading"
            x-transition:enter="fs-content-enter"
            x-transition:enter-start="fs-content-from"
            x-transition:enter-end="fs-content-to"
            class="fs {{ $newbie ? 'newbie' : '' }}"
        >
            <div class="overlay">
                <section class="sheet" aria-label="Your fighter" role="dialog" aria-modal="true">
                    <span class="rivet tl"></span><span class="rivet tr"></span><span class="rivet bl"></span><span class="rivet br"></span>

                    @include('livewire.fighter-sheet.header')

                    <div class="tab-panel {{ $tab === 'profile' ? 'is-active' : '' }}" id="tab-profile" role="tabpanel" wire:loading.class="refreshing" wire:target="refresh">
                        <div class="sheet-body">
                            @include('livewire.fighter-sheet.side')

                            <div class="main">
                                @include('livewire.fighter-sheet.alerts')
                                @include('livewire.fighter-sheet.empty')
                                @include('livewire.fighter-sheet.accounts')
                                @include('livewire.fighter-sheet.damage')
                                @include('livewire.fighter-sheet.tokens')
                                @include('livewire.fighter-sheet.legacy-stats')
                            </div>
                        </div>
                    </div>

                    <div class="tab-panel {{ $tab === 'character' ? 'is-active' : '' }}" id="tab-character" role="tabpanel">
                        @include('livewire.fighter-sheet.character')
                    </div>
                </section>
            </div>
        </div>
    @endif
    </div>

    {{-- Outside the @if: Equip's Undo toast must outlive the sheet, which
         folds away onto the battlefield before the swap persists. --}}
    <div class="fs" wire:ignore>
        <div class="toast" id="toast"></div>
        <div class="tipbox" id="tipbox"></div>
    </div>
</div>
