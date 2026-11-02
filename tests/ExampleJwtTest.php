<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Exception\DpopException;
use DpopExample\JwtTokens;
use DpopTest\Support\ProofFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExampleJwtTest extends TestCase
{
    public static function unavailableKeys():array { return [[null],['{broken'],['{}']]; }

    #[DataProvider('unavailableKeys')]
    public function testMissingOrCorruptTrustedKeyIsDependencyFailure(?string $contents):void
    {
        $path=sys_get_temp_dir().'/dpop-jwt-audit-'.bin2hex(random_bytes(8));
        if($contents!==null){file_put_contents($path,$contents);}
        try{
            $this->expectExceptionMessage('dpop_security_unavailable');
            (new JwtTokens($path))->resolve('synthetic-invalid-token');
        }finally{if(is_file($path)){unlink($path);}}
    }

    public function testWorkingIssuerAcceptsOwnBindingAndRejectsForgedToken():void
    {
        $path=sys_get_temp_dir().'/dpop-jwt-audit-'.bin2hex(random_bytes(8));$proof=new ProofFactory();
        file_put_contents($path,json_encode($proof->key->all(),JSON_THROW_ON_ERROR));chmod($path,0600);
        try{
            $issuer=new JwtTokens($path);$binding=$issuer->resolve($issuer->issue('fixture-subject',$proof->jkt()));
            self::assertSame('fixture-subject',$binding->subject);self::assertSame($proof->jkt(),$binding->jkt);
            try{$issuer->resolve('synthetic-invalid-token');self::fail();}
            catch(DpopException $e){self::assertSame('invalid_token',$e->error);self::assertNull($e->getPrevious());}
        }finally{unlink($path);}
    }
}
