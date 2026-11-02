<?php
declare(strict_types=1);
namespace Acme\Dpop\Protocol;

use Acme\Dpop\Exception\DpopException;

final class UriCanonicalizer
{
    public static function canonicalize(string $uri, bool $proof = false): string
    {
        $parts = parse_url($uri);
        if (strlen($uri) > 8192 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $uri)
            || preg_match('/%(?![a-fA-F0-9]{2})/', $uri) || !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || ($proof && (isset($parts['query']) || isset($parts['fragment'])))) {
            throw new DpopException('invalid_dpop_proof');
        }
        $host = strtolower($parts['host']);
        if (preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/D', $host) !== 1) { throw new DpopException('invalid_dpop_proof'); }
        $path = preg_replace_callback('/%([a-fA-F0-9]{2})/', static function (array $m): string {
            $char = chr(hexdec($m[1]));
            return preg_match('/^[A-Za-z0-9._~-]$/D', $char) ? $char : '%' . strtoupper($m[1]);
        }, $parts['path'] ?? '/') ?: '/';
        // RFC3986 section 5.2.4: remove dot segments without decoding reserved slashes.
        $input = $path; $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) { $input = substr($input, 3); }
            elseif (str_starts_with($input, './')) { $input = substr($input, 2); }
            elseif (str_starts_with($input, '/./')) { $input = substr($input, 2); }
            elseif ($input === '/.') { $input = '/'; }
            elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = $input === '/..' ? '/' : substr($input, 3);
                $output = substr($output, 0, (int) strrpos($output, '/'));
            } elseif ($input === '.' || $input === '..') { $input = ''; }
            else {
                $next = strpos($input, '/', str_starts_with($input, '/') ? 1 : 0);
                $length = $next === false ? strlen($input) : $next;
                $output .= substr($input, 0, $length);
                $input = substr($input, $length);
            }
        }
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '';
        return 'https://' . $host . $port . ($output ?: '/');
    }

    public static function origin(string $uri): string
    {
        $canonical = self::canonicalize($uri);
        $end = strpos($canonical, '/', 8);
        return $end === false ? $canonical : substr($canonical, 0, $end);
    }
}
