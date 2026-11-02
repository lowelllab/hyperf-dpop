<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\Contract\ErrorResponseFactoryInterface;
use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Message\ResponseInterface;

final class ErrorResponseFactory implements ErrorResponseFactoryInterface
{
    public function create(DpopException $error, bool $tokenEndpoint = false): ResponseInterface
    {
        $category = $error->error;
        $status = match ($category) {
            'invalid_request', 'invalid_grant' => 400,
            'dpop_security_unavailable' => 503,
            'rate_limited' => 429,
            default => $tokenEndpoint ? 400 : 401,
        };
        $data = $category === 'missing_authorization' ? new \stdClass() : ['error' => $category];
        $response = (new Response())->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withContent(json_encode($data, JSON_THROW_ON_ERROR));
        if (!$tokenEndpoint && $status === 401) {
            $challenge = 'DPoP algs="ES256"';
            if ($category !== 'missing_authorization') { $challenge .= ', error="' . $category . '"'; }
            $response = $response->withHeader('WWW-Authenticate', $challenge);
        }
        if ($error->nonce() !== null) { $response = $response->withHeader('DPoP-Nonce', $error->nonce()); }
        if ($status === 429) { $response = $response->withHeader('Retry-After', (string) $error->retryAfter); }
        return $response;
    }
}
