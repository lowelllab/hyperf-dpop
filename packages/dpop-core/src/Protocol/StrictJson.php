<?php
declare(strict_types=1);
namespace Acme\Dpop\Protocol;

use Acme\Dpop\Exception\DpopException;

/** Bounded JSON parser that detects duplicate decoded keys at every object level. */
final class StrictJson
{
    private int $offset = 0;
    private function __construct(#[\SensitiveParameter] private readonly string $json) {}

    public static function object(#[\SensitiveParameter] string $json): \stdClass
    {
        try {
            $parser = new self($json);
            $value = $parser->value(0);
            $parser->space();
            if (!$value instanceof \stdClass || $parser->offset !== strlen($json)) { throw new \UnexpectedValueException(); }
            return $value;
        } catch (\Throwable) { throw new DpopException('invalid_dpop_proof'); }
    }

    private function space(): void
    {
        while (isset($this->json[$this->offset]) && str_contains(" \r\n\t", $this->json[$this->offset])) { ++$this->offset; }
    }

    private function value(int $depth): mixed
    {
        if ($depth > 16) { throw new \UnexpectedValueException(); }
        $this->space();
        $char = $this->json[$this->offset] ?? '';
        if ($char === '"') { return $this->string(); }
        if ($char === '{' || $char === '[') {
            ++$this->offset;
            $object = $char === '{';
            $end = $object ? '}' : ']';
            $result = [];
            $this->space();
            if (($this->json[$this->offset] ?? '') === $end) { ++$this->offset; return $object ? (object) $result : $result; }
            do {
                $this->space();
                $key = $object ? $this->string() : count($result);
                if ($object) {
                    if (array_key_exists($key, $result)) { throw new \UnexpectedValueException(); }
                    $this->space();
                    if (($this->json[$this->offset++] ?? '') !== ':') { throw new \UnexpectedValueException(); }
                }
                $result[$key] = $this->value($depth + 1);
                $this->space();
                $next = $this->json[$this->offset++] ?? '';
                if ($next === $end) { return $object ? (object) $result : $result; }
                if ($next !== ',') { throw new \UnexpectedValueException(); }
            } while (true);
        }
        if (!preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $this->json, $m, 0, $this->offset)) {
            throw new \UnexpectedValueException();
        }
        $this->offset += strlen($m[0]);
        return json_decode($m[0], false, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    private function string(): string
    {
        if (($this->json[$this->offset] ?? '') !== '"') { throw new \UnexpectedValueException(); }
        $start = $this->offset++;
        $length = strlen($this->json);
        while ($this->offset < $length) {
            $char = $this->json[$this->offset++];
            if ($char === '\\') { ++$this->offset; continue; }
            if ($char === '"') {
                $decoded = json_decode(substr($this->json, $start, $this->offset - $start), false, 16, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) { throw new \UnexpectedValueException(); }
                return $decoded;
            }
        }
        throw new \UnexpectedValueException();
    }
}
