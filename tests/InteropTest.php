<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Protocol\ProofVerifier;
use Acme\Dpop\ValueObject\SecurityPolicy;
use PHPUnit\Framework\TestCase;

final class InteropTest extends TestCase
{
    public function testNativeNodeSignatureAndThumbprintInteroperate():void
    {
        $vector=json_decode(file_get_contents(__DIR__.'/Fixtures/node-interop.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($vector['synthetic']);
        [$proof]=(new ProofVerifier(new SecurityPolicy()))->verify($vector['proof'],'GET','https://api.example/items',$vector['now'],$vector['jkt'],$vector['access']);
        self::assertSame($vector['jkt'],$proof->jkt);self::assertSame('node-interop-synthetic-vector',$proof->jti);
    }
}
