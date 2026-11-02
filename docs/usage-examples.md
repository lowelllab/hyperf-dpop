# Hyperf DPoP 使用示例

下面以现有 Hyperf 应用接入不透明令牌为例，串联配置、登录签发、受保护资源、刷新和客户端 nonce 重试。JWT 接入方式见第 7 节。

服务端需要 PHP 8.5、Hyperf 3.2、OpenSSL、PDO MySQL 和 Redis。两个包名为 `lowelllab/hyperf-dpop`、`lowelllab/dpop-core`，尚未提交公共 Packagist；以下使用本地 Composer path 仓库。PHP 类仍使用 `Acme` 命名空间。账号校验、会话和权限使用宿主自己的业务服务。

## 1 安装开发包

假设宿主目录 `my-api` 与本扩展目录 `hyperf-dpop` 同级。在宿主 `composer.json` 中合并以下配置，保留原有依赖和自动加载设置：

```json
{
  "require": {
    "lowelllab/hyperf-dpop": "^0.1@dev",
    "hyperf/redis": "~3.2.0"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../hyperf-dpop/packages/*",
      "options": {
        "symlink": false,
        "versions": {
          "lowelllab/dpop-core": "0.1.x-dev",
          "lowelllab/hyperf-dpop": "0.1.x-dev"
        }
      }
    }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

在宿主目录执行：

```bash
composer update lowelllab/hyperf-dpop hyperf/redis --with-all-dependencies
```

核心包会自动引入。这里使用文件副本安装；修改扩展源码后，需要在宿主执行 `composer reinstall lowelllab/dpop-core lowelllab/hyperf-dpop` 更新副本。单独把当前 GitHub 工作区根目录配置为 VCS 仓库，不能替代两个子包的安装。

## 2 配置与建表

宿主安装 `hyperf/devtool ~3.2.0` 时，可以发布模板：

```bash
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=config
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=schema
```

也可以手动复制[配置模板](../packages/hyperf-dpop/publish/dpop.php)和[防重放 DDL](../packages/hyperf-dpop/publish/dpop-proofs.sql)。DDL 需要由宿主迁移流程显式执行；安装和发布命令不会自动建表。

配置模板读取以下环境变量；将占位值替换为实际部署值：

```dotenv
DPOP_ORIGIN=https://api.example
DPOP_SECURITY_DOMAIN=my-api-production
DPOP_NONCE_KEY=<独立生成的随机密钥>
DPOP_MYSQL_DSN=mysql:host=mysql;dbname=dpop_security;charset=utf8mb4
DPOP_MYSQL_USER=dpop
DPOP_MYSQL_PASSWORD=<专用数据库密码>
DPOP_REDIS_NAMESPACE=my-api-production
```

生成一次随机 nonce 密钥，例如执行 `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`，再通过宿主的密钥管理注入。共享同一安全域的所有 Worker 和实例应使用一致的配置；不要每次启动随机更换密钥，也不要将真实 `.env` 提交到仓库。

`DPOP_ORIGIN` 是客户端访问的公开 HTTPS origin，不带路径或末尾斜杠。若网关改写路径，在 `config/autoload/dpop.php` 中配置 `internal_prefix` / `external_prefix`；例如宿主收到 `/api/me`、外部访问 `/gateway/api/me`，可设置空 `internal_prefix` 和 `/gateway` 的 `external_prefix`。

宿主 `config/autoload/redis.php` 示例：

```php
<?php
declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'host' => env('REDIS_HOST', 'redis'),
        'port' => (int) env('REDIS_PORT', 6379),
        'auth' => env('REDIS_AUTH', null),
        'db' => 0,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 32,
            'connect_timeout' => 1.0,
            'wait_timeout' => 1.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ],
];
```

顶层 `timeout` / `read_timeout` 与连接池等待超时都需要设置。DNS 和框架重连会影响总耗时，示例中的 1 秒不是请求全链路时限。

## 3 接入宿主会话和令牌绑定

后面的 `App\Auth\AccountService`、`App\Auth\SessionService` 是宿主需要实现的业务服务，扩展不提供这两个类。它们的约定如下：

| 宿主方法 | 职责与返回值 |
| --- | --- |
| `AccountService::authenticate($username, $password): string` | 校验账号、密码及账号状态，返回用户标识；失败抛宿主自己的认证异常 |
| `SessionService::issueBound($subject, $jkt): array` | 生成访问和刷新令牌，原子保存两者与 subject、jkt 的绑定；返回 access_token、refresh_token、expires_in |
| `SessionService::findActiveAccess($token): ?array` | 从权威数据读取未过期、未撤销的会话，返回 subject、jkt；凭据失效返回 null |
| `SessionService::findActiveRefresh($token): ?array` | 只读定位有效刷新绑定，返回 subject、jkt；凭据失效返回 null |
| `SessionService::rotateBound($refresh, $jkt): array` | 在行锁或 CAS 下重新核对有效期、撤销状态和原 jkt，再原子轮换两类令牌；返回新令牌，失效或竞争失败抛 invalid_grant |

数据库保存令牌摘要即可。存储不可用时抛出依赖异常，不能把查询失败伪装为“没有找到会话”。多租户应用还需使用当前请求的可信租户上下文参与查询与授权，不能只信任客户端自报的租户标识。

新建宿主 `app/Auth/DpopBindingResolver.php`：

```php
<?php
declare(strict_types=1);

namespace App\Auth;

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\TokenBinding;
use Acme\HyperfDpop\Contract\RefreshBindingResolverInterface;
use Acme\HyperfDpop\Contract\TokenBindingResolverInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DpopBindingResolver implements
    TokenBindingResolverInterface,
    RefreshBindingResolverInterface
{
    public function __construct(private SessionService $sessions) {}

    public function resolve(string $accessToken, ServerRequestInterface $request): TokenBinding
    {
        $session = $this->sessions->findActiveAccess($accessToken);
        if ($session === null) {
            throw new DpopException('invalid_token');
        }
        return new TokenBinding($session['jkt'], $session['subject']);
    }

    public function resolveRefresh(string $refreshToken, ServerRequestInterface $request): TokenBinding
    {
        $session = $this->sessions->findActiveRefresh($refreshToken);
        if ($session === null) {
            throw new DpopException('invalid_grant');
        }
        return new TokenBinding($session['jkt'], $session['subject']);
    }
}
```

在宿主 `config/autoload/dependencies.php` 中合并以下绑定：

```php
<?php
declare(strict_types=1);

return [
    Acme\HyperfDpop\Contract\TokenBindingResolverInterface::class
        => App\Auth\DpopBindingResolver::class,
    Acme\HyperfDpop\Contract\RefreshBindingResolverInterface::class
        => App\Auth\DpopBindingResolver::class,
];
```

如果使用自己的业务接口，还要为 AccountService / SessionService 配置实际实现。Resolver 不保存当前用户或请求状态到共享属性。

## 4 实现签发 刷新和资源接口

新建宿主 `app/Controller/DpopController.php`：

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\Contract\ErrorResponseFactoryInterface;
use Acme\HyperfDpop\DpopContext;
use Acme\HyperfDpop\TokenEndpoint;
use App\Auth\AccountService;
use App\Auth\DpopBindingResolver;
use App\Auth\SessionService;
use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DpopController
{
    public function __construct(
        private TokenEndpoint $tokens,
        private ErrorResponseFactoryInterface $errors,
        private AccountService $accounts,
        private SessionService $sessions,
        private DpopBindingResolver $bindings,
    ) {}

    private function json(array $data): ResponseInterface
    {
        return (new Response())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withContent(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // 证明成功之前不创建会话或发放令牌。
            $proof = $this->tokens->issuance($request);
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_string($body['username'] ?? null)
                || !is_string($body['password'] ?? null)) {
                throw new DpopException('invalid_request');
            }
            $subject = $this->accounts->authenticate($body['username'], $body['password']);
            $session = $this->sessions->issueBound($subject, $proof->jkt);
            return $this->json(array_replace($session, ['token_type' => 'DPoP']));
        } catch (DpopException $error) {
            return $this->errors->create($error, true);
        }
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $request->getParsedBody();
            $refresh = is_array($body) ? ($body['refresh_token'] ?? null) : null;
            if (!is_string($refresh) || $refresh === '') {
                throw new DpopException('invalid_request');
            }
            $proof = $this->tokens->refresh($request, $refresh, $this->bindings);
            // rotateBound 必须在事务中再次核对原绑定，不能自动改绑新密钥。
            $session = $this->sessions->rotateBound($refresh, $proof->jkt);
            return $this->json(array_replace($session, ['token_type' => 'DPoP']));
        } catch (DpopException $error) {
            return $this->errors->create($error, true);
        }
    }

    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $context = $request->getAttribute(DpopContext::ATTRIBUTE);
        if (!$context instanceof DpopContext) {
            throw new \LogicException('DPoP middleware is required');
        }
        // 此处继续执行宿主的账号状态检查和业务权限校验。
        return $this->json(['subject' => $context->binding->subject]);
    }
}
```

账号认证失败由宿主的异常处理器转换为自己的登录错误；不能把账号不存在、密码错误或数据库故障统一当成 proof 错误。业务认证、异常响应和幂等策略需要与上述业务服务一同接入。

刷新业务的核心顺序是：读取原绑定 → 校验证明并登记防重放 → 在宿主事务中重新读取并校验原刷新状态和 jkt → 轮换 access / refresh → 提交。并发刷新只能有一次成功，旧 refresh 在提交后失效。后续业务失败也不能释放已经登记的 proof。

## 5 注册路由并启动

宿主 `config/routes.php`：

```php
<?php
declare(strict_types=1);

use Acme\HyperfDpop\Middleware\DpopMiddleware;
use App\Controller\DpopController;
use Hyperf\HttpServer\Router\Router;

Router::post('/auth/token', [DpopController::class, 'token']);
Router::post('/auth/refresh', [DpopController::class, 'refresh']);
Router::addGroup('/api', static function (): void {
    Router::get('/me', [DpopController::class, 'me']);
}, ['middleware' => [DpopMiddleware::class]]);
```

`/auth/token` 和 `/auth/refresh` 使用 TokenEndpoint 辅助器，不再挂载资源 DpopMiddleware，避免重复消费证明。

完成业务服务、DDL、密钥和存储配置后运行：

```bash
php bin/hyperf.php dpop:doctor
php bin/hyperf.php start
```

公开地址需要通过 HTTPS 访问。CORS 中间件由宿主配置：允许请求头 `Authorization`、`DPoP`，暴露响应头 `DPoP-Nonce`、`WWW-Authenticate`，并为 OPTIONS 注册预检处理；DPoP 中间件会跳过 OPTIONS，但不会替你创建预检路由或开放跨域来源。

## 6 PHP 客户端完整请求示例

下面的 CLI 示例依赖本项目已安装的 JOSE 库和 PHP curl 扩展。保存为宿主 `tools/dpop-client.php`，让 `dirname(__DIR__) . '/vendor/autoload.php'` 指向宿主自动加载文件。

示例每次运行生成一对 P-256 密钥，并在同一进程中完成登录、资源请求和刷新。实际客户端应在整个绑定会话期间保留同一私钥；私钥丢失后不能靠新密钥刷新旧会话，也不能发送私钥给服务端。

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Acme\Dpop\Protocol\Base64Url;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

function makeProof(JWK $key, string $method, string $url, ?string $nonce, ?string $access): string
{
    $uri = parse_url($url);
    if ($uri === false || ($uri['scheme'] ?? '') !== 'https' || !isset($uri['host'])
        || isset($uri['user']) || isset($uri['pass'])) {
        throw new InvalidArgumentException('An HTTPS URL without userinfo is required');
    }
    // 从实际请求目标取路径，保留百分号编码，不把 query/fragment 写入 htu。
    $htu = 'https://' . $uri['host']
        . (isset($uri['port']) ? ':' . $uri['port'] : '')
        . ($uri['path'] ?? '/');
    $claims = [
        'jti' => bin2hex(random_bytes(16)),
        'iat' => time(),
        'htm' => $method,
        'htu' => $htu,
    ];
    if ($nonce !== null) {
        $claims['nonce'] = $nonce;
    }
    if ($access !== null) {
        $claims['ath'] = Base64Url::encode(hash('sha256', $access, true));
    }
    $jws = (new JWSBuilder(new AlgorithmManager([new ES256()])))
        ->create()
        ->withPayload(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->addSignature($key, [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $key->toPublic()->all(),
        ])
        ->build();
    return (new CompactSerializer())->serialize($jws, 0);
}

function sendRequest(string $method, string $url, string $proof, ?string $access, ?array $body): array
{
    $responseHeaders = [];
    $headers = ['Content-Type: application/json', 'DPoP: ' . $proof];
    if ($access !== null) {
        $headers[] = 'Authorization: DPoP ' . $access;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $responseHeaders[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException('HTTPS request failed with curl code ' . curl_errno($curl));
    }
    return [
        'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => $responseHeaders,
        'data' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
    ];
}

function requestDpop(JWK $key, array &$nonces, string $method, string $url, ?string $access = null, ?array $body = null): array
{
    $method = strtoupper($method);
    // 本示例只有一个 origin 和一把客户端密钥，用 token/resource 区分缓存。
    $purpose = $access === null ? 'token' : 'resource';
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $proof = makeProof($key, $method, $url, $nonces[$purpose] ?? null, $access);
        $response = sendRequest($method, $url, $proof, $access, $body);
        if ($attempt === 0 && in_array($response['status'], [400, 401], true)
            && ($response['data']['error'] ?? null) === 'use_dpop_nonce'
            && isset($response['headers']['dpop-nonce'])) {
            $nonces[$purpose] = $response['headers']['dpop-nonce'];
            continue; // 重新生成 jti、iat 和签名，不能重发原 proof。
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('DPoP endpoint returned HTTP ' . $response['status']);
        }
        return $response['data'];
    }
    throw new RuntimeException('Nonce negotiation failed');
}

$base = rtrim($argv[1] ?? 'https://api.example', '/');
$username = getenv('DEMO_USERNAME');
$password = getenv('DEMO_PASSWORD');
if ($username === false || $password === false || $username === '' || $password === '') {
    throw new RuntimeException('Set DEMO_USERNAME and DEMO_PASSWORD first');
}
$key = JWKFactory::createECKey('P-256');
$nonces = [];

$session = requestDpop($key, $nonces, 'POST', $base . '/auth/token', body: [
    'username' => $username,
    'password' => $password,
]);
$me = requestDpop($key, $nonces, 'GET', $base . '/api/me?view=basic', $session['access_token']);
echo json_encode($me, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;

$session = requestDpop($key, $nonces, 'POST', $base . '/auth/refresh', body: [
    'refresh_token' => $session['refresh_token'],
]);
$me = requestDpop($key, $nonces, 'GET', $base . '/api/me', $session['access_token']);
echo json_encode($me, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
```

为进程设置自己的测试账号 `DEMO_USERNAME` / `DEMO_PASSWORD`，再运行：

```bash
php tools/dpop-client.php https://api.example
```

预期输出两次当前用户信息，分别来自初始 access 和刷新后的 access；示例不打印令牌、proof 或私钥。客户端机器需要信任目标 HTTPS 证书。

签发和刷新 proof 不设置 `ath`，刷新令牌只放在请求 body；资源 proof 的 `ath` 是实际 access token 的 SHA-256 再 base64url 编码。示例只自动处理一次 nonce 挑战，不自动重试网络异常、429 或 503；这些情况需要结合业务幂等和重试策略处理。多 origin / 多密钥客户端应按 origin、公钥指纹和用途隔离 nonce 缓存。

## 7 使用 JWT 访问令牌

签发 JWT 时，由宿主自己的签发器在已签名 claims 中放入 `cnf.jkt`，其值来自 `TokenEndpoint::issuance()` 返回的 proof：

```json
{
  "iss": "https://issuer.example",
  "aud": "my-api",
  "sub": "user-123",
  "cnf": {"jkt": "<verified-proof-jkt>"}
}
```

这是 claims 结构示意，实际令牌还需有效时间等完整字段。Resolver 先验证令牌签名、允许算法、issuer、audience、时间及撤销状态，再返回 `new TokenBinding($claims['cnf']['jkt'], $claims['sub'])`。不要只 base64 解码 JWT 就读取绑定，也不要使用请求额外提交的 `jkt`。客户端的请求方式与不透明令牌相同。

## 8 常见响应与排查

| 响应 | 客户端或宿主应做什么 |
| --- | --- |
| 400 / 401 use_dpop_nonce | 读取 DPoP-Nonce，以同一私钥重新生成完整 proof 后重试 |
| 401 invalid_dpop_proof | 检查签名、目标方法/URI、时钟、ath 和 jti 是否重用，不能直接重发旧 proof |
| 401 invalid_token | 检查 access 有效期、撤销状态、绑定密钥及 Authorization 是否用了 DPoP |
| 400 invalid_grant | 检查 refresh 是否已轮换、撤销或过期，交由宿主决定是否重新登录 |
| 429 rate_limited | 参考 Retry-After 并结合自己的请求期限退避 |
| 503 dpop_security_unavailable | 检查共享存储、超时及时间偏差；不要据此清除刷新凭据 |

资源入口 proof 错误通常是 401，token 入口对应 proof 错误是 400，完整映射见[错误契约](errors.md)。

上线入口必须在重复头被折叠前拒绝重复 `Authorization` / `DPoP`。DPoP 不签名 body 和 query，HTTPS、业务权限、刷新事务及业务幂等仍由宿主负责。详细约束见[接入指南](integration.md)与[安全说明](security.md)。仓库中的[示例控制器](../examples/hyperf/app/ExampleController.php)、[会话实现](../examples/hyperf/app/State.php)和[JWT 示例](../examples/hyperf/app/JwtTokens.php)可以辅助理解接口，但其中的合成身份不能直接用于生产登录。
