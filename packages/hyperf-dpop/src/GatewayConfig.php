<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\HyperfDpop\Http\MtlsGuard;

final class GatewayConfig
{
    /** @param array<string, mixed> $options */
    public static function render(array $options, bool $mtlsEnabled): string
    {
        $template = @file_get_contents(__DIR__ . '/../publish/haproxy.cfg');
        if ($template === false) {
            throw new \RuntimeException('Cannot read the packaged HAProxy template');
        }

        $clientAuth = '';
        $forwardVerification = '';
        if ($mtlsEnabled) {
            $ca = self::value($options, 'mtls_client_ca', path: true);
            $crl = self::value($options, 'mtls_client_crl', path: true, optional: true);
            $clientAuth = ' verify required ca-file "' . $ca . '"';
            if ($crl !== '') {
                $clientAuth .= ' crl-file "' . $crl . '"';
            }
            $forwardVerification = '    http-request set-header ' . MtlsGuard::HEADER . ' 1 if { ssl_c_used } { ssl_c_verify 0 }';
        }

        return strtr($template, [
            '${DPOP_GATEWAY_BIND}' => self::value($options, 'gateway_bind'),
            '${DPOP_GATEWAY_CERT}' => self::value($options, 'gateway_cert', path: true),
            '${DPOP_BACKEND_ADDR}' => self::value($options, 'gateway_backend'),
            ' # DPOP_MTLS_BIND' => $clientAuth,
            '    # DPOP_MTLS_HEADER' => $forwardVerification,
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function value(array $options, string $key, bool $path = false, bool $optional = false): string
    {
        $value = $options[$key] ?? '';
        if ($optional && $value === '') {
            return '';
        }
        // Keep the value inside its HAProxy argument and forbid environment expansion.
        $format = $path ? '~^/[A-Za-z0-9_./ -]+$~D' : '~^[A-Za-z0-9.*_:\[\]-]+:[0-9]{1,5}$~D';
        if (!is_string($value) || preg_match($format, $value) !== 1) {
            throw new \InvalidArgumentException('Invalid or missing DPoP gateway setting: ' . $key);
        }
        return $value;
    }
}
