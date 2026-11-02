<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Middleware;

use Acme\Dpop\Authenticator;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\HyperfDpop\Admission\AdmissionBudgetInterface;
use Acme\HyperfDpop\Contract\{TokenBindingResolverInterface, ErrorResponseFactoryInterface, AuthenticationObserverInterface, ExternalUriResolverInterface};
use Acme\HyperfDpop\DpopContext;
use Acme\HyperfDpop\Http\{HeaderReader, ClientSource};
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final readonly class DpopMiddleware implements MiddlewareInterface
{
    public function __construct(private Authenticator $auth, private SecurityPolicy $policy, private TokenBindingResolverInterface $resolver, private ExternalUriResolverInterface $uris, private ClientSource $source, private AdmissionBudgetInterface $budget, private ErrorResponseFactoryInterface $responses, private AuthenticationObserverInterface $observer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') { return $handler->handle($request); }
        $release = null; $started = hrtime(true); $outcome = 'dpop_security_unavailable';
        try {
            $release = $this->budget->enter($this->source->resolve($request), 'resource');
            $token = HeaderReader::access($request);
            $jwt = HeaderReader::proof($request, $this->policy->proofMaxBytes);
            $binding = $this->resolver->resolve($token, $request);
            \Acme\HyperfDpop\Cancellation::assertActive();
            $proof = $this->auth->resource($jwt, $request->getMethod(), $this->uris->resolve($request), $token, $binding);
            \Acme\HyperfDpop\Cancellation::assertActive();
            $context = new DpopContext($binding, $proof);
            $outcome = 'accepted';
        } catch (DpopException $e) { $outcome = $e->error; return $this->responses->create($e); }
        catch (\Throwable) { return $this->responses->create(new DpopException('dpop_security_unavailable')); }
        finally {
            if ($release !== null) { $release(); }
            try { $this->observer->record('resource', $outcome, (hrtime(true) - $started) / 1e6); } catch (\Throwable) { /* Metrics cannot alter authentication. */ }
        }
        // Host business exceptions must propagate; admission capacity is already released.
        return $handler->handle($request->withAttribute(DpopContext::ATTRIBUTE, $context));
    }
}
