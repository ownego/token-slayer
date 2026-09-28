@php
    use App\Support\CompactNumber;
@endphp
{{-- The whole team's usage (ported from the old Profile page) — not in the
     mockup, so it sits at the bottom of .main in its side-panel language.
     The player's own rolling totals and attribution note were dropped: the
     hero, ledger and header already say both. --}}
<div class="legacy has-data">
    <div class="side">
        <h3>All users</h3>
        <dl class="stat-grid three">
            <div class="stat-tile"><dt>Hourly</dt><dd title="{{ number_format($globalUsage['hourly']) }}">{{ CompactNumber::format($globalUsage['hourly']) }}</dd></div>
            <div class="stat-tile"><dt>Daily</dt><dd title="{{ number_format($globalUsage['daily']) }}">{{ CompactNumber::format($globalUsage['daily']) }}</dd></div>
            <div class="stat-tile"><dt>Monthly</dt><dd title="{{ number_format($globalUsage['monthly']) }}">{{ CompactNumber::format($globalUsage['monthly']) }}</dd></div>
        </dl>
    </div>
</div>
