<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Contract;

use Acme\Dpop\Exception\DpopException;
use Psr\Http\Message\ResponseInterface;

interface ErrorResponseFactoryInterface
{
    public function create(DpopException $error, bool $tokenEndpoint = false): ResponseInterface;
}
