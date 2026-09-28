{{-- Shown the instant the nav asks for the sheet, until its first render lands
     (later opens show the already-loaded sheet instead): the real sheet's frame
     and size with the viewer's own fighter running in it, so the content fills
     in without a jump. --}}
<div class="fs fs-skel" aria-hidden="true">
    <div class="overlay">
        <section class="sheet">
            <header class="sheet-head"><h1>{{ auth()->user()?->displayHandle() }}</h1></header>
            <div class="skel-runner">
                @include('partials.runner', ['char' => auth()->user()?->equipped_character?->value])
            </div>
        </section>
    </div>
</div>
