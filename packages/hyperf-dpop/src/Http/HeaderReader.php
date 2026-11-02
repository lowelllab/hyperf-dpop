<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Acme\Dpop\Exception\DpopException;
use Psr\Http\Message\ServerRequestInterface;

final class HeaderReader
{
    public static function proof(ServerRequestInterface $request, int $max): string
    {
        foreach (['Authorization', 'DPoP'] as $header) {
            if (count($request->getHeader($header)) > 1) { throw new DpopException('invalid_request'); }
        }
        $proof = $request->getHeaderLine('DPoP');
        if ($proof === '' || strlen($proof) > $max || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $proof) !== 1) { throw new DpopException('invalid_dpop_proof'); }
        return $proof;
    }

    public static function access(ServerRequestInterface $request): string
    {
        if (count($request->getHeader('Authorization')) > 1 || count($request->getHeader('DPoP')) > 1) { throw new DpopException('invalid_request'); }
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') { throw new DpopException('missing_authorization'); }
        if (strlen($header) > 8192 || preg_match('~^DPoP +([A-Za-z0-9._\~+/=-]+)$~iD', $header, $match) !== 1) {
            if (preg_match('/^Bearer /i', $header)) { throw new DpopException('invalid_token'); }
            throw new DpopException('invalid_request');
        }
        if (preg_match('~^[A-Za-z0-9._\~+/-]+=*$~D', $match[1]) !== 1) { throw new DpopException('invalid_request'); }
        return $match[1];
    }
}
