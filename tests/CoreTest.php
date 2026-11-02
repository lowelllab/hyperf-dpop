<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Authenticator;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\{Base64Url, StrictJson, UriCanonicalizer, ProofVerifier, JwkThumbprint};
use Acme\Dpop\ValueObject\{SecurityPolicy, TokenBinding};
use DpopTest\Support\{ProofFactory, FrozenClock, MemoryReplay};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public static function uris(): array
    {
        return [
            ['https://API.EXAMPLE:443', 'https://api.example/'],
            ['https://api.example/%7euser', 'https://api.example/~user'],
            ['https://api.example/a/%2e%2e/B', 'https://api.example/B'],
            ['https://api.example/a/%40/%2f', 'https://api.example/a/%40/%2F'],
            ['https://api.example/a//b?x=1#f', 'https://api.example/a//b'],
            ['https://api.example/a/.', 'https://api.example/a/'],
        ];
    }

    #[DataProvider('uris')]
    public function testUriNormalization(string $input, string $expected): void { self::assertSame($expected, UriCanonicalizer::canonicalize($input)); }

    public static function invalidJson(): array
    {
        return [['{"a":1,"a":2}'], ['{"a":1,"\\u0061":2}'], ['{"jwk":{"x":1,"x":2}}'], ['[]'], ['{"a":01}'], ['{"a":NaN}'], ['{"a":true,}'], ['{"a":"\\uD800"}'], ['{"a":1} trailing']];
    }

    #[DataProvider('invalidJson')]
    public function testStrictJsonRejectsAmbiguity(string $json): void { $this->expectException(DpopException::class); StrictJson::object($json); }

    public function testJsonSupportsOrdinaryClaimsAndNumericTypes(): void
    {
        $json = <<<'JSON'
{"a":1,"b":true,"c":[null,{"d":"\""}],"n":1.25}
JSON;
        $object = StrictJson::object($json);
        self::assertSame(1, $object->a); self::assertTrue($object->b); self::assertSame('"', $object->c[1]->d); self::assertSame(1.25, $object->n);
    }

    public function testNonceBindingExpiryAndRotation(): void
    {
        $factory = new ProofFactory(); $old = new HmacNonceManager(str_repeat('a', 32));
        $nonce = $old->issue($factory->jkt(), 'env:resource|https://api.example', 1000);
        self::assertTrue($old->verify($nonce, $factory->jkt(), 'env:resource|https://api.example', 1001));
        self::assertFalse($old->verify($nonce, $factory->jkt(), 'env:token|https://api.example', 1001));
        self::assertFalse($old->verify($nonce, $factory->jkt(), 'env:resource|https://api.example', 1300));
        self::assertTrue((new HmacNonceManager(str_repeat('b', 32), 300, str_repeat('a', 32)))->verify($nonce, $factory->jkt(), 'env:resource|https://api.example', 1001));
        self::assertFalse((new HmacNonceManager(str_repeat('b', 32)))->verify($nonce, $factory->jkt(), 'env:resource|https://api.example', 1001));
    }

    public function testChallengeThenResourceThenReplay(): void
    {
        $f = new ProofFactory(); $now = time(); $policy = new SecurityPolicy(); $nonces = new HmacNonceManager(str_repeat('n', 32)); $replay = new MemoryReplay();
        $auth = new Authenticator($policy, new ProofVerifier($policy), $nonces, $replay, new FrozenClock($now), 'test:api');
        $claims = ['ath' => Base64Url::encode(hash('sha256', 'access', true)), 'iat' => $now];
        try { $auth->resource($f->make($claims), 'GET', 'https://api.example/items', 'access', new TokenBinding($f->jkt(), 'user')); self::fail('Expected nonce challenge'); }
        catch (DpopException $e) { self::assertSame('use_dpop_nonce', $e->error); $claims['nonce'] = $e->nonce(); }
        self::assertSame([], $replay->accepted);
        $proof = $f->make($claims);
        self::assertSame($f->jkt(), $auth->resource($proof, 'GET', 'https://api.example/items?limit=1', 'access', new TokenBinding($f->jkt(), 'user'))->jkt);
        self::assertCount(1, $replay->accepted);
        $this->expectExceptionMessage('invalid_dpop_proof');
        $auth->resource($proof, 'GET', 'https://api.example/items', 'access', new TokenBinding($f->jkt(), 'user'));
    }

    public static function invalidClaims(): array
    {
        return [
            [['htm' => 'get']], [['htu' => 'https://api.example/Items']], [['htu' => 'https://api.example/items?x=1']],
            [['htu' => 'https://api.example/items#x']], [['htu' => 'http://api.example/items']],
            [['iat' => 0]], [['iat' => '123']], [['jti' => 'tiny']], [['jti' => []]], [['ath' => 'wrong']], [['exp' => 1]], [['exp' => null]], [['nbf' => PHP_INT_MAX]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidProofsFail(array $changes): void
    {
        $f = new ProofFactory(); $claims = array_replace(['ath' => Base64Url::encode(hash('sha256', 'access', true))], $changes);
        $this->expectExceptionMessage('invalid_dpop_proof');
        (new ProofVerifier(new SecurityPolicy()))->verify($f->make($claims), 'GET', 'https://api.example/items', time(), $f->jkt(), 'access');
    }

    public function testWrongKeyDistinguishesResourceAndRefresh(): void
    {
        $f = new ProofFactory(); $other = new ProofFactory(); $verifier = new ProofVerifier(new SecurityPolicy());
        $proof = $f->make(['ath' => Base64Url::encode(hash('sha256', 'access', true))]);
        try { $verifier->verify($proof, 'GET', 'https://api.example/items', time(), $other->jkt(), 'access'); self::fail(); }
        catch (DpopException $e) { self::assertSame('invalid_token', $e->error); }
        $this->expectExceptionMessage('invalid_dpop_proof');
        $verifier->verify($proof, 'GET', 'https://api.example/items', time(), $other->jkt());
    }

    public function testDuplicateClaimsInValidSignatureAreRejected(): void
    {
        $f = new ProofFactory(); $payload = '{"htm":"GET","htm":"GET","htu":"https://api.example/items","iat":' . time() . ',"jti":"0123456789abcdef"}';
        $this->expectExceptionMessage('invalid_dpop_proof');
        (new ProofVerifier(new SecurityPolicy()))->verify($f->make(payload: $payload), 'GET', 'https://api.example/items', time());
    }

    public function testPrivateJwkAndNoncanonicalBase64AreRejected(): void
    {
        $f = new ProofFactory(); $public = $f->publicJwk(); $public['d'] = 'not-public';
        try { JwkThumbprint::calculate((object) $public); self::fail(); } catch (DpopException) { self::assertTrue(true); }
        $this->expectException(DpopException::class); Base64Url::decode('AB');
    }

    public function testSignatureTamperingRejected(): void
    {
        $f = new ProofFactory(); $parts = explode('.', $f->make());
        $signature = Base64Url::decode($parts[2]); $signature[0] = chr(ord($signature[0]) ^ 1); $parts[2] = Base64Url::encode($signature);
        $this->expectExceptionMessage('invalid_dpop_proof');
        (new ProofVerifier(new SecurityPolicy()))->verify(implode('.', $parts), 'GET', 'https://api.example/items', time());
    }

    public function testRefreshKeepsBindingAndTokenNonceDomain(): void
    {
        $f = new ProofFactory(); $p = new SecurityPolicy(); $now = time(); $n = new HmacNonceManager(str_repeat('n', 32));
        $a = new Authenticator($p, new ProofVerifier($p), $n, new MemoryReplay(), new FrozenClock($now), 'test:api');
        $nonce = $n->issue($f->jkt(), 'test:api:token|https://api.example', $now);
        $proof = $f->make(['htm' => 'POST', 'htu' => 'https://api.example/refresh', 'nonce' => $nonce]);
        self::assertSame($f->jkt(), $a->issuance($proof, 'POST', 'https://api.example/refresh', $f->jkt())->jkt);
    }

    public function testUnsafePolicyRejected(): void { $this->expectException(\InvalidArgumentException::class); new SecurityPolicy(replayTtl: 100); }

    public static function malformedEnvelopes(): array
    {
        return [['header-array'], ['header-duplicate'], ['crit'], ['jku'], ['symmetric'], ['wrong-typ'], ['private-jwk'], ['coordinate'], ['padding'], ['oversize']];
    }

    #[DataProvider('malformedEnvelopes')]
    public function testMalformedEnvelopesAndPublicKeyPolicy(string $case): void
    {
        $factory=new ProofFactory();$parts=explode('.',$factory->make());$header=json_decode(Base64Url::decode($parts[0]),true,16,JSON_THROW_ON_ERROR);
        switch($case){
            case 'header-array':$parts[0]=Base64Url::encode('[]');break;
            case 'header-duplicate':$parts[0]=Base64Url::encode('{"typ":"dpop+jwt","typ":"dpop+jwt"}');break;
            case 'crit':$header['crit']=['custom'];break;
            case 'jku':$header['jku']='https://untrusted.invalid/keys';break;
            case 'symmetric':$header['alg']='HS256';break;
            case 'wrong-typ':$header['typ']='JWT';break;
            case 'private-jwk':$header['jwk']['d']='private';break;
            case 'coordinate':$header['jwk']['x']='invalid';break;
            case 'padding':$parts[0].='=';break;
            case 'oversize':$parts[1]=str_repeat('a',9000);break;
        }
        if(!in_array($case,['header-array','header-duplicate','padding','oversize'],true)){$parts[0]=Base64Url::encode(json_encode($header,JSON_THROW_ON_ERROR));}
        $this->expectExceptionMessage('invalid_dpop_proof');
        (new ProofVerifier(new SecurityPolicy()))->verify(implode('.',$parts),'GET','https://api.example/items',time());
    }

    public function testDeepJsonAndInvalidUriAreRejected(): void
    {
        try{StrictJson::object('{"x":'.str_repeat('[',20).'1'.str_repeat(']',20).'}');self::fail();}catch(DpopException){self::assertTrue(true);}
        $this->expectExceptionMessage('invalid_dpop_proof');UriCanonicalizer::canonicalize('https://user:pass@api.example/items');
    }
}
