<?php

// src/Strategy.php

namespace Yolorouter\Llmasking;

interface Strategy
{
    public function apply(Finding $finding, int $sequence): string;
}
