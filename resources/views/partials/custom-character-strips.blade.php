{{-- A fighter version's four strips, each a row of 100 px frames stepped by CSS (custom-character-editor.css).
     $strips: PreviewStrips::execute(); $prefix keeps wire:key unique when two are on the page. --}}
<div class="cce-stage">
    @foreach ($strips as $strip)
        <figure class="cce-fig" wire:key="{{ $prefix }}-strip-{{ $strip['name'] }}">
            <div class="cce-sprite" style="background-image: url('{{ $strip['url'] }}'); --frames: {{ $strip['frames'] }}; --rate: {{ $strip['rate'] }};"></div>
            <figcaption>{{ $strip['label'] }}</figcaption>
        </figure>
    @endforeach
</div>
