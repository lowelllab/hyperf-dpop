<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\Http\HeaderReader;
use Hyperf\Context\{Context, RequestContext};
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpServer\Request as RequestProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class IngressBoundaryTest extends TestCase
{
    private static function request(array $headers, string $body = '', bool $chunked = false): Request
    {
        $native = \Swoole\Http\Request::create();
        $headers = array_merge(['Host: fixture.example'], $headers,
            [$chunked ? 'Transfer-Encoding: chunked' : 'Content-Length: ' . strlen($body)]);
        $native->parse("POST /items HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
        self::assertTrue($native->isCompleted());
        return Request::loadFromSwooleRequest($native);
    }

    protected function tearDown(): void { Context::destroy(ServerRequestInterface::class); }

    public static function readers(): array { return [['access'], ['proof']]; }

    #[DataProvider('readers')]
    public function testHyperfRequestProxyCannotSkipDuplicateCheck(string $reader): void
    {
        $request = self::request(['Authorization: DPoP first', 'Authorization: DPoP last', 'DPoP: a.b.c']);
        RequestContext::set($request);
        $this->expectExceptionMessage('invalid_request');
        $reader === 'access' ? HeaderReader::access(new RequestProxy()) : HeaderReader::proof(new RequestProxy(), 8192);
    }

    #[DataProvider('readers')]
    public function testHyperfRequestProxyCannotSkipNativeHttp2Guard(string $reader): void
    {
        $request = self::request(['Authorization: DPoP access', 'DPoP: a.b.c']);
        $request->getSwooleRequest()->server['server_protocol'] = 'HTTP/2';
        RequestContext::set($request);
        $this->expectExceptionMessage('dpop_security_unavailable');
        $reader === 'access' ? HeaderReader::access(new RequestProxy()) : HeaderReader::proof(new RequestProxy(), 8192);
    }

    public function testProxyReadsDoNotChangeCoroutineRequest(): void
    {
        $request = self::request(['Authorization: DPoP access', 'DPoP: a.b.c']);
        RequestContext::set($request);
        self::assertSame('access', HeaderReader::access(new RequestProxy()));
        self::assertSame('a.b.c', HeaderReader::proof(new RequestProxy(), 8192));
        self::assertSame($request, RequestContext::get());
    }

    public static function forbiddenTrailers(): iterable
    {
        foreach (['access', 'proof'] as $reader) {
            foreach (['Authorization: DPoP access', 'Authorization: DPoP different', 'dPoP: a.b.c', 'DPoP: x.y.z'] as $trailer) {
                foreach ([false, true] as $declared) { yield [$reader, $trailer, $declared]; }
            }
        }
    }

    #[DataProvider('forbiddenTrailers')]
    public function testAuthenticationFieldsInTrailersAreRejected(string $reader, string $trailer, bool $declared): void
    {
        $headers = ['Authorization: DPoP access', 'DPoP: a.b.c'];
        if ($declared) { $headers[] = 'Trailer: ' . explode(':', $trailer)[0]; }
        $request = self::request($headers, "2\r\n{}\r\n0\r\n" . $trailer . "\r\n\r\n", true);
        $this->expectExceptionMessage('invalid_request');
        $reader === 'access' ? HeaderReader::access($request) : HeaderReader::proof($request, 8192);
    }

    public function testUndeclaredTrailerOnlyCredentialsAreRejected(): void
    {
        $request = self::request([], "2\r\n{}\r\n0\r\nAuthorization: DPoP access\r\nDPoP: a.b.c\r\n\r\n", true);
        $this->expectExceptionMessage('invalid_request');
        HeaderReader::access($request);
    }

    public function testChunkPayloadHeaderDecoysAndHarmlessTrailersRemainValid(): void
    {
        $payload = "Authorization: DPoP body\r\nDPoP: body\r\n0\r\n";
        $body = dechex(strlen($payload)) . ";fixture=yes\r\n" . $payload . "\r\n0;end=yes\r\nX-Checksum: synthetic\r\n\r\n";
        $request = self::request(['Authorization: DPoP access', 'DPoP: a.b.c', 'Trailer: X-Checksum'], $body, true);
        self::assertSame('access', HeaderReader::access($request));
        self::assertSame('a.b.c', HeaderReader::proof($request, 8192));
    }

    #[DataProvider('readers')]
    public function testReplacingParsedCredentialsCannotHideRawSource(string $reader): void
    {
        $request = self::request(['Authorization: DPoP original', 'DPoP: a.b.c']);
        $request = $request->withHeader('Authorization', 'DPoP replacement');
        $this->expectExceptionMessage('invalid_request');
        $reader === 'access' ? HeaderReader::access($request) : HeaderReader::proof($request, 8192);
    }
}
