<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\Http\HeaderReader;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderReaderTest extends TestCase
{
    private static function native(array $headers, string $body = ''): Request
    {
        $native = \Swoole\Http\Request::create();
        $native->parse("POST /items HTTP/1.1\r\nHost: fixture.example\r\n" . implode("\r\n", $headers)
            . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        self::assertTrue($native->isCompleted());
        return Request::loadFromSwooleRequest($native);
    }

    public static function duplicates(): iterable
    {
        foreach (['access', 'proof'] as $reader) {
            foreach ([
                'auth-same' => ['Authorization: DPoP access', 'Authorization: DPoP access', 'DPoP: a.b.c'],
                'auth-valid-last' => ['Authorization: DPoP bad', 'Authorization: DPoP access', 'DPoP: a.b.c'],
                'auth-valid-first' => ['Authorization: DPoP access', 'Authorization: DPoP bad', 'DPoP: a.b.c'],
                'auth-case' => ['authorization: DPoP access', 'AUTHORIZATION: DPoP access', 'DPoP: a.b.c'],
                'auth-empty' => ['Authorization:', 'Authorization: DPoP access', 'DPoP: a.b.c'],
                'proof-same' => ['Authorization: DPoP access', 'DPoP: a.b.c', 'DPoP: a.b.c'],
                'proof-valid-last' => ['Authorization: DPoP access', 'DPoP: bad', 'DPoP: a.b.c'],
                'proof-case' => ['Authorization: DPoP access', 'dpop: a.b.c', 'DPoP: a.b.c'],
            ] as $name => $headers) { yield $reader . '-' . $name => [$reader, $headers]; }
        }
    }

    #[DataProvider('duplicates')]
    public function testNativeDuplicatesRejectedBeforeCollapsedValuesAreUsed(string $reader, array $headers): void
    {
        $request = self::native($headers);
        $this->expectException(DpopException::class);
        $this->expectExceptionMessage('invalid_request');
        $reader === 'access' ? HeaderReader::access($request) : HeaderReader::proof($request, 8192);
    }

    public function testBodyHeaderDecoysAndUnrelatedDuplicateHeadersAreIgnored(): void
    {
        $request = self::native(['Authorization: DPoP access', 'DPoP: a.b.c', 'X-Test: one', 'X-Test: two'],
            "Authorization: body\r\nAuthorization: body\r\nDPoP: body\r\nDPoP: body");
        self::assertSame('access', HeaderReader::access($request));
        self::assertSame('a.b.c', HeaderReader::proof($request, 8192));
    }

    public function testNativeHttp2WithoutRawHeadersFailsClosed(): void
    {
        $request = self::native(['Authorization: DPoP access', 'DPoP: a.b.c']);
        $request->getSwooleRequest()->server['server_protocol'] = 'HTTP/2';
        foreach (['access', 'proof'] as $reader) {
            try {
                $reader === 'access' ? HeaderReader::access($request) : HeaderReader::proof($request, 8192);
                self::fail('Cannot trust a collapsed native HTTP/2 field');
            } catch (DpopException $e) { self::assertSame('dpop_security_unavailable', $e->error); }
        }
    }

    public function testMissingNativeRawDataFailsClosed(): void
    {
        $native = \Swoole\Http\Request::create();
        $native->server = ['server_protocol' => 'HTTP/1.1'];
        $request = (new Request('GET', 'https://fixture.example/items'))->setSwooleRequest($native)
            ->withHeader('Authorization', 'DPoP access')->withHeader('DPoP', 'a.b.c');
        $this->expectException(DpopException::class);
        $this->expectExceptionMessage('dpop_security_unavailable');
        HeaderReader::access($request);
    }

    public function testManuallyConstructedPsrRequestRetainsExistingSupport(): void
    {
        $request = (new Request('GET', 'https://fixture.example/items'))->withHeader('Authorization', 'DPoP access')->withHeader('DPoP', 'a.b.c');
        self::assertSame('access', HeaderReader::access($request));
        self::assertSame('a.b.c', HeaderReader::proof($request, 8192));
    }
}
