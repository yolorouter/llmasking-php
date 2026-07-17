<?php

// src/Region.php

namespace Yolorouter\Llmasking;

/** Geographic tag on a built-in rule. Universal is always active and is not a WithRegions argument. */
enum Region: string
{
    case Universal = 'UNIVERSAL';
    case CN = 'CN';
    case US = 'US';
}
