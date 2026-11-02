<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
DpopTest\Support\Environment::schema();
DpopTest\Support\Environment::pdo()->exec('CREATE TABLE IF NOT EXISTS example_sessions(access_hash CHAR(64) PRIMARY KEY,refresh_hash CHAR(64) UNIQUE NOT NULL,jkt CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,subject VARCHAR(128) NOT NULL,expires_at BIGINT NOT NULL,revoked TINYINT NOT NULL) ENGINE=InnoDB');
$root=dirname(__DIR__);
if (!is_dir($root.'/build')) { mkdir($root.'/build',0700,true); }
if (!is_dir($root.'/examples/hyperf/runtime')) { mkdir($root.'/examples/hyperf/runtime',0700,true); }
if (!is_file($root.'/build/example-issuer-key.json')) { file_put_contents($root.'/build/example-issuer-key.json',json_encode(Jose\Component\KeyManagement\JWKFactory::createECKey('P-256')->all(),JSON_THROW_ON_ERROR)); chmod($root.'/build/example-issuer-key.json',0600); }
echo "Synthetic example schema and issuer key ready; no production authentication performed\n";
