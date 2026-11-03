<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Acme\Dpop\Exception\DpopException;
use Hyperf\HttpMessage\Server\Request;
use Psr\Http\Message\ServerRequestInterface;

final class HeaderReader
{
    public static function proof(ServerRequestInterface $request, int $max): string
    {
        self::assertUnambiguous($request);
        $proof = $request->getHeaderLine('DPoP');
        if ($proof === '' || strlen($proof) > $max || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $proof) !== 1) { throw new DpopException('invalid_dpop_proof'); }
        return $proof;
    }

    public static function access(ServerRequestInterface $request): string
    {
        self::assertUnambiguous($request);
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') { throw new DpopException('missing_authorization'); }
        if (strlen($header) > 8192 || preg_match('~^DPoP +([A-Za-z0-9._\~+/=-]+)$~iD', $header, $match) !== 1) {
            if (preg_match('/^Bearer /i', $header)) { throw new DpopException('invalid_token'); }
            throw new DpopException('invalid_request');
        }
        if (preg_match('~^[A-Za-z0-9._\~+/-]+=*$~D', $match[1]) !== 1) { throw new DpopException('invalid_request'); }
        return $match[1];
    }

    private static function assertUnambiguous(ServerRequestInterface $request): void
    {
        if ($request instanceof \Hyperf\HttpServer\Request) {
            // The standard coroutine proxy delegates immutable PSR operations
            // to the underlying request; this does not mutate RequestContext.
            try { $request = $request->withProtocolVersion($request->getProtocolVersion()); }
            catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
            if ($request instanceof \Hyperf\HttpServer\Request) { throw new DpopException('dpop_security_unavailable'); }
        }
        foreach (['Authorization', 'DPoP'] as $name) {
            if (count($request->getHeader($name)) > 1) { throw new DpopException('invalid_request'); }
        }
        foreach ($request->getHeader('Trailer') as $declaration) {
            foreach (explode(',', $declaration) as $name) {
                if (in_array(strtolower(trim($name)), ['authorization', 'dpop'], true)) { throw new DpopException('invalid_request'); }
            }
        }
        if (!$request instanceof Request) { return; }
        try { $native = $request->getSwooleRequest(); }
        catch (\TypeError) {
            // A manually constructed PSR request has no native Swoole request.
            return;
        }
        // Swoole HTTP/2 overwrites both fields and exposes no raw header list.
        // Terminate HTTP/2 at a checking gateway and forward over HTTP/1.1.
        if (!in_array($native->server['server_protocol'] ?? '', ['HTTP/1.0', 'HTTP/1.1'], true)) {
            throw new DpopException('dpop_security_unavailable');
        }
        try { $raw = $native->getData(); }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
        if (!is_string($raw) || $raw === '') { throw new DpopException('dpop_security_unavailable'); }
        $end = strpos($raw, "\r\n\r\n");
        if ($end === false || $end > 65536) { throw new DpopException('invalid_request'); }
        $lines = explode("\r\n", substr($raw, 0, $end));
        array_shift($lines);
        [$fields, $chunked] = self::readFields($lines);
        foreach (['authorization', 'dpop'] as $name) {
            if (count($request->getHeader($name)) !== (isset($fields[$name]) ? 1 : 0)
                || (isset($fields[$name]) && trim($request->getHeaderLine($name), " \t") !== $fields[$name])) {
                throw new DpopException('invalid_request');
            }
        }
        if ($chunked) { self::assertNoAuthenticationTrailers($raw, $end + 4); }
    }

    /** @param list<string> $lines
     *  @return array{array<string, string>, bool}
     */
    private static function readFields(array $lines, bool $trailers = false): array
    {
        $fields = []; $chunked = false;
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            // Reject obsolete folding and whitespace before a field's colon.
            if ($colon === false || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", substr($line, 0, $colon)) !== 1) {
                throw new DpopException('invalid_request');
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1), " \t");
            if (in_array($name, ['authorization', 'dpop'], true)) {
                if ($trailers || isset($fields[$name])) { throw new DpopException('invalid_request'); }
                $fields[$name] = $value;
            }
            if ($name === 'transfer-encoding' && preg_match('/(?:^|,)\s*chunked\s*(?:,|$)/i', $value) === 1) { $chunked = true; }
        }
        return [$fields, $chunked];
    }

    private static function assertNoAuthenticationTrailers(string $raw, int $offset): void
    {
        // Walk framing, skipping payload by size; header-looking body text is
        // never scanned as a trailer. Offsets advance within the existing data.
        $length = strlen($raw);
        while ($offset < $length) {
            $end = strpos($raw, "\r\n", $offset);
            if ($end === false) { throw new DpopException('invalid_request'); }
            $line = substr($raw, $offset, $end - $offset);
            $hex = trim(explode(';', $line, 2)[0], " \t");
            if (preg_match('/^[0-9a-fA-F]+$/D', $hex) !== 1) { throw new DpopException('invalid_request'); }
            $size = hexdec($hex); $offset = $end + 2;
            if (!is_int($size) || $size < 0) { throw new DpopException('invalid_request'); }
            if ($size === 0) {
                if (substr($raw, $offset, 2) === "\r\n") { return; }
                $end = strpos($raw, "\r\n\r\n", $offset);
                if ($end === false || $end - $offset > 65536) { throw new DpopException('invalid_request'); }
                self::readFields(explode("\r\n", substr($raw, $offset, $end - $offset)), true);
                return;
            }
            if ($size > $length - $offset - 2 || substr($raw, $offset + $size, 2) !== "\r\n") {
                throw new DpopException('invalid_request');
            }
            $offset += $size + 2;
        }
        throw new DpopException('invalid_request');
    }
}
