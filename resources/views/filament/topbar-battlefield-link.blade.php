{{-- Sits immediately before the user menu (avatar) in the panel topbar: the
     panel's only way back to the app.

     Styling is self-contained rather than Tailwind utilities: the panel serves
     its own Tailwind build holding only the utilities Filament itself uses, so
     `hidden`, `sr-only` and friends are absent there and silently do nothing.
     Inline SVG for the same reason the icon isn't a component — the panel runs
     DisableBladeIconComponents. --}}
<style>
    .ts-battlefield-link {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.625rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        line-height: 1.25rem;
        color: inherit;
        opacity: 0.75;
        transition: opacity 75ms, background-color 75ms;
    }
    .ts-battlefield-link:hover {
        opacity: 1;
        background-color: rgb(0 0 0 / 5%);
    }
    .ts-battlefield-link:focus-visible {
        outline: 2px solid rgb(245 158 11);
        outline-offset: 2px;
    }
    @media (prefers-color-scheme: dark) {
        .ts-battlefield-link:hover {
            background-color: rgb(255 255 255 / 8%);
        }
    }
    .ts-battlefield-link svg {
        width: 1.25rem;
        height: 1.25rem;
        flex-shrink: 0;
    }
    @media (max-width: 639px) {
        .ts-battlefield-link span {
            display: none;
        }
    }
</style>
<a href="{{ route('battlefield') }}" class="ts-battlefield-link" title="Battlefield">
    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" />
    </svg>
    <span>Battlefield</span>
</a>
