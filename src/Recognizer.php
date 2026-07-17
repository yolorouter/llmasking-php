<?php

// src/Recognizer.php

namespace Yolorouter\Llmasking;

interface Recognizer
{
    public function name(): string;
    /** @return list<Finding> */
    public function recognize(string $text): array;
}
