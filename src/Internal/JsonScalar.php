<?php

// src/Internal/JsonScalar.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A JSON scalar that is not a string: number, true, false, or null. The raw
 * literal is preserved (no decoding to int/float), so big integers keep their
 * original lexical form — round-tripping through json_decode/json_encode would
 * lose precision on platforms with 32-bit or float-coerced numbers, which is
 * exactly why the spec §9.3 forbids tree-rebuild for masking.
 */
final class JsonScalar extends JsonValue
{
    /**
     * @param string $scalarKind One of: 'number', 'true', 'false', 'null'.
     */
    public function __construct(
        int $start,
        int $end,
        int $depth,
        public readonly string $scalarKind,
    ) {
        parent::__construct($start, $end, $depth);
    }

    public function kind(): string
    {
        return $this->scalarKind;
    }

    /** Raw bytes of the literal (e.g. '123', '9223372036854775808', 'true'). */
    public function rawLiteral(string $json): string
    {
        return \substr($json, $this->start, $this->end - $this->start);
    }
}
