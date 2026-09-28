{{-- Shown the instant the nav asks for the sheet, until its first render lands
     (later opens show the already-loaded sheet instead). Same frame and size as
     the real sheet, so the content fills in without a jump. --}}
<div class="fs fs-skel" aria-hidden="true">
    <div class="overlay">
        <section class="sheet">
            <header class="sheet-head"><h1>{{ auth()->user()?->displayHandle() }}</h1></header>
            <div class="skel-body">
                <div class="skel-col">
                    <i class="skel" style="height: 190px"></i>
                    <i class="skel" style="height: 120px"></i>
                </div>
                <div class="skel-col">
                    <i class="skel" style="height: 36px"></i>
                    <i class="skel" style="height: 150px"></i>
                    <i class="skel" style="height: 110px"></i>
                    <i class="skel" style="height: 110px"></i>
                </div>
            </div>
        </section>
    </div>
</div>
