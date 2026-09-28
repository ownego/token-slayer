<div>
    @include('livewire.fighter-sheet.attribution')

    @if ($open)
        {{-- Markup ported from the approved mockup (docs/superpowers/mockups/
             2026-09-27-battlefield-redesign/fighter-sheet.html); its CSS lives
             in resources/css/fighter-sheet.css, scoped under .fs. --}}
        <div
            x-data="fighterSheetShell({{ auth()->id() }})"
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

    {{-- Outside the @if: Equip's Undo toast must outlive the sheet, which
         folds away onto the battlefield before the swap persists. --}}
    <div class="fs" wire:ignore>
        <div class="toast" id="toast"></div>
        <div class="tipbox" id="tipbox"></div>
    </div>
</div>
