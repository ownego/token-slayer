<?php

namespace App\Support;

use App\Models\Boss;

class DamageResult
{
    /**
     * @param  array<int, Boss>  $killedBosses  Defeated bosses in kill order (oldest first).
     */
    public function __construct(public Boss $boss, public array $killedBosses = []) {}
}
