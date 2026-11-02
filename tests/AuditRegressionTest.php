<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Authenticator;
use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\{JwkThumbprint, ProofVerifier};
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};
use Acme\HyperfDpop\Store\MySqlReplayStore;
use DpopTest\Support\{Environment, FrozenClock, ProofFactory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditRegressionTest extends TestCase
{
    public static function setUpBeforeClass(): void { Environment::schema(); }

    public static function unsafeSchemas(): array
    {
        return [
            ['MODIFY expires_at SMALLINT UNSIGNED NOT NULL'],
            ['MODIFY accepted_at VARCHAR(40) NOT NULL'],
            ['MODIFY jti_hash BINARY(16) NOT NULL'],
            ['DROP PRIMARY KEY, ADD UNIQUE KEY weak (security_domain(1),jkt(1),jti_hash)'],
            ['ADD UNIQUE KEY unintended_key_limit (jkt)'],
        ];
    }

    #[DataProvider('unsafeSchemas')]
    public function testSchemaProbeRejectsTruncatingAndPrefixSchemas(string $alter): void
    {
        $pdo=Environment::pdo();$table='audit_schema_'.bin2hex(random_bytes(4));
        $pdo->exec("CREATE TABLE `{$table}` LIKE dpop_proofs");
        try {
            $pdo->exec("ALTER TABLE `{$table}` {$alter}");
            $this->expectExceptionMessage('dpop_security_unavailable');
            (new MySqlReplayStore(Environment::pdo(...),$table))->probe();
        } finally { $pdo->exec("DROP TABLE `{$table}`"); }
    }

    public function testLenientSqlCannotInsertAndCleanAnUnexpiredProof():void
    {
        $pdo=Environment::pdo();$table='audit_lenient_'.bin2hex(random_bytes(4));$pdo->exec("CREATE TABLE `{$table}` LIKE dpop_proofs");
        try{
            $pdo->exec("ALTER TABLE `{$table}` MODIFY expires_at SMALLINT UNSIGNED NOT NULL");
            $store=new MySqlReplayStore(static function(){ $connection=Environment::pdo();$connection->exec("SET SESSION sql_mode=''");return $connection;},$table);
            try{$store->reserve('audit:lenient',new VerifiedProof(str_repeat('a',43),bin2hex(random_bytes(16)),time()),time(),new SecurityPolicy());self::fail('Unsafe schema accepted a live proof');}
            catch(DpopException $e){self::assertSame('dpop_security_unavailable',$e->error);self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn());}
        }finally{$pdo->exec("DROP TABLE `{$table}`");}
    }

    public function testActualDisconnectedMysqlConnectionFailsClosed():void
    {
        $disconnected=Environment::pdo();$id=(int)$disconnected->query('SELECT CONNECTION_ID()')->fetchColumn();
        Environment::pdo()->exec('KILL CONNECTION '.$id);
        try{(new MySqlReplayStore(static fn()=>$disconnected))->reserve('audit:disconnect',new VerifiedProof(str_repeat('a',43),bin2hex(random_bytes(16)),time()),time(),new SecurityPolicy());self::fail();}
        catch(DpopException $e){self::assertSame('dpop_security_unavailable',$e->error);self::assertNull($e->getPrevious());}
    }

    public function testCommittedButUnconfirmedProofSurvivesCleanupFailure():void
    {
        $connection=new class((string)getenv('DPOP_TEST_MYSQL_DSN'),(string)getenv('DPOP_TEST_MYSQL_USER'),(string)getenv('DPOP_TEST_MYSQL_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]) extends \PDO {
            private bool $uncertain=false;
            public function commit():bool{parent::commit();$this->uncertain=true;throw new \RuntimeException('synthetic commit acknowledgement loss');}
            public function inTransaction():bool{if($this->uncertain){throw new \RuntimeException('synthetic cleanup connection failure');}return parent::inTransaction();}
        };
        $proof=new VerifiedProof(str_repeat('a',43),bin2hex(random_bytes(16)),time());
        try{(new MySqlReplayStore(static fn()=>$connection))->reserve('audit:uncertain',$proof,time(),new SecurityPolicy());self::fail();}
        catch(DpopException $e){self::assertSame('dpop_security_unavailable',$e->error);self::assertNull($e->getPrevious());}
        $this->expectExceptionMessage('invalid_dpop_proof');Environment::store('mysql')->reserve('audit:uncertain',$proof,time(),new SecurityPolicy());
    }

    public static function drivers(): array { return [['mysql'],['redis']]; }

    #[DataProvider('drivers')]
    public function testStoreRechecksNotBeforeEvenWithFreshIat(string $driver):void
    {
        $now=time();$proof=new VerifiedProof(str_repeat('a',43),bin2hex(random_bytes(16)),$now,null,$now+30);
        $this->expectExceptionMessage('invalid_dpop_proof');
        Environment::store($driver)->reserve('audit:nbf',$proof,$now,new SecurityPolicy());
    }

    public static function iatBoundaries():array { return [[-61,false],[-60,true],[0,true],[30,true],[31,false]]; }

    #[DataProvider('iatBoundaries')]
    public function testExactIatWindowBoundaries(int $offset,bool $accepted):void
    {
        $f=new ProofFactory();$now=1800000000;
        if(!$accepted){$this->expectExceptionMessage('invalid_dpop_proof');}
        [$proof]=(new ProofVerifier(new SecurityPolicy()))->verify($f->make(['iat'=>$now+$offset]),'GET','https://api.example/items',$now);
        if($accepted){self::assertSame($now+$offset,$proof->issuedAt);}
    }

    #[DataProvider('drivers')]
    public function testProofExpiryIsRecheckedAtStoreAcceptance(string $driver): void
    {
        $f=new ProofFactory();$now=time();$policy=new SecurityPolicy();$nonces=new HmacNonceManager(str_repeat('n',32));
        $nonce=$nonces->issue($f->jkt(),'audit:api:token|https://api.example',$now);
        $store=new class(Environment::store($driver)) implements ReplayStoreInterface {
            public function __construct(private ReplayStoreInterface $delegate) {}
            public function reserve(string $domain,VerifiedProof $proof,int $verifiedAt,SecurityPolicy $policy):void {
                usleep(2100000); // Simulate pool/network wait within the allowed clock budget.
                $this->delegate->reserve($domain,$proof,$verifiedAt,$policy);
            }
        };
        $a=new Authenticator($policy,new ProofVerifier($policy),$nonces,$store,new FrozenClock($now),'audit:api');
        $this->expectExceptionMessage('invalid_dpop_proof');
        $a->issuance($f->make(['nonce'=>$nonce,'iat'=>$now,'exp'=>$now+2]),'GET','https://api.example/items');
    }

    public static function invalidMetadata():array
    {
        return [['alg',null],['use',null],['key_ops',null],['kid',[]],['ext','true']];
    }

    #[DataProvider('invalidMetadata')]
    public function testPresentJwkMetadataMustHaveCorrectTypes(string $key,mixed $value):void
    {
        $jwk=(new ProofFactory())->publicJwk();$jwk[$key]=$value;
        $this->expectExceptionMessage('invalid_dpop_proof');JwkThumbprint::calculate((object)$jwk);
    }
}
