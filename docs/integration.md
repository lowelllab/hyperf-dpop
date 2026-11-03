# 接入指南

需要完整接入代码和客户端请求流程时，先看[使用示例](usage-examples.md)。

状态：两个正式包的正式发行版本为0.1.0；安装与更新状态见[发布指南](publishing.md)。Composer包名为`lowelllab/dpop-core`和`lowelllab/hyperf-dpop`。PHP命名空间继续使用`Acme\Dpop`和`Acme\HyperfDpop`，与Composer包名独立，现有接入代码无需改名。

## 1. 包与运行环境

业务项目安装`lowelllab/hyperf-dpop`即可引入核心。开发工作区安装与检查命令见[维护指南](maintenance.md)。包使用PHP `^8.5`，Hyperf组件`~3.2.0`；已验证的实际组合以[实施记录](../spec/feature_20261102_F001_hyperf-dpop-extension/implementation-progress.md)为准。

正式接入使用`composer require "lowelllab/hyperf-dpop:^0.1"`，核心自动引入，保持宿主默认稳定性。开发工作区仍使用0.1.x-dev path别名；它是开发检查入口，不作为业务安装来源。发行与干净公开安装证据见[0.1.0发布记录](release-0.1.0.md)。

默认方案需要pdo_mysql及共享Redis：MySQL负责权威防重放，Redis负责跨Worker/实例认证预算。切换Redis重放驱动不会消除预算，也不会获得数据库同等的恢复保证。

## 2. 配置与显式DDL

Hyperf从Composer extra读取ConfigProvider。宿主安装可选`hyperf/devtool ~3.2.0`后，可以显式发布模板：

```bash
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=config
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=schema
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=gateway
```

schema只复制至应用根目录的`dpop-proofs.sql`，不执行SQL。已有配置默认保留；仅明确需要覆盖时使用`--force`，并先保存自己的配置。以上命令已在生产缓存模式验证。也可手动复制：

- [配置模板](../packages/hyperf-dpop/publish/dpop.php)至宿主`config/autoload/dpop.php`。
- [DDL模板](../packages/hyperf-dpop/publish/dpop-proofs.sql)交由宿主迁移流程执行，包不自动建表。
- [网关模板](../packages/hyperf-dpop/publish/haproxy.cfg)复制为宿主`haproxy-dpop.cfg`，只复制、不启动网关；按[网关接入](gateway.md)配置证书、监听地址和后端。客户端HTTP/2由网关终止并转为HTTP/1.1；Swoole原生HTTP/2无法确认认证头唯一性，受保护接口返回503。

必需配置：HTTPS公开origin、独立环境安全域、至少32字节随机nonce密钥、MySQL专用连接凭据、Redis连接和独立namespace。nonce密钥通过部署密钥管理注入，不写入源码。受信代理默认空；使用内部/外部前缀映射时，按组件模板显式填写，保留原始路径编码。

在宿主`config/autoload/redis.php`中显式配置Redis连接的顶层`timeout`和`read_timeout`为有限正值，同时设置连接池`wait_timeout`；连接池参数不能替代套接字连接/读取超时。按部署的DNS、框架重连和HTTP请求期限验收总耗时，不把单次超时当成全链路上限。客户端HTTP超时不等于服务端协程已取消，不能据此推断proof或业务操作尚未发生。独立宿主的实际中断结果见[消费项目测试报告](verification-consumer-20261102.md)。

MySQL表要求InnoDB，必需列非空且不是生成列：security_domain为VARCHAR(96) ascii_bin，jkt为CHAR(43) ascii_bin，jti_hash为BINARY(32)，两个时间列为BIGINT UNSIGNED。唯一索引必须完整且按security_domain、jkt、jti_hash顺序覆盖，不接受前缀索引或其他唯一限制。以发布DDL为准，不能缩短时间/摘要字段。驱动在每个进程首次登记时核验schema；doctor同样只读核验。管理员修改DDL后重新运行doctor并重启Worker。

默认URI解析器只使用固定公开origin和显式前缀映射。复杂网关可实现`Acme\HyperfDpop\Contract\ExternalUriResolverInterface`并在宿主DI绑定，资源和token入口共用该接口。实现只能使用可信部署信息，必须保留原始保留字符编码；不能对完整路径执行urldecode或信任客户端自报origin。

## 3. 实现令牌绑定Resolver

实现`Acme\HyperfDpop\Contract\TokenBindingResolverInterface`，在宿主DI中绑定。方法接收access token和当前PSR请求，返回核心`TokenBinding(jkt, subject)`。

- JWT：先验证签名、算法、issuer、audience、有效期及宿主撤销规则，再取可信`cnf.jkt`。
- 不透明令牌：查权威会话或受保护introspection，确认到期、撤销和绑定后返回。
- 无效access抛`DpopException('invalid_token')`；依赖故障抛`dpop_security_unavailable`。未知异常默认转换为503，不能伪装成凭据已失效。
- 当前请求或租户状态只从参数读取，不保存在共享resolver属性。不要默认缓存active=true。

示例：[ExampleResolver](../examples/hyperf/app/ExampleResolver.php)、[数据库会话](../examples/hyperf/app/State.php)、[JWT验证](../examples/hyperf/app/JwtTokens.php)。示例JWT的离线验证不提供即时撤销，生产项目需自行接入撤销规则。

## 4. 资源路由

将`Acme\HyperfDpop\Middleware\DpopMiddleware`显式挂载到受保护路由或路由组。中间件只接受DPoP，OPTIONS交由后续预检处理，不给它建立已认证身份。

控制器从当前请求`getAttribute(DpopContext::ATTRIBUTE)`获取`DpopContext`，再进行宿主成员准入及业务授权。不要从共享服务属性读取上一次请求身份。

扩展在进入业务handler前释放验签预算；宿主业务异常正常传播，不会被当作proof错误。CORS允许Authorization、DPoP并暴露DPoP-Nonce、WWW-Authenticate；扩展不自动开放跨域来源。

## 5. 签发及刷新

登录入口先调用`TokenEndpoint::issuance($request)`，成功后才校验账号并创建会话，原子保存所得proof.jkt与新令牌绑定。返回`token_type=DPoP`和no-store。

刷新实现`RefreshBindingResolverInterface`，只读定位原refresh绑定，再调用`TokenEndpoint::refresh($request, $refreshToken, $resolver)`。证明成功后，宿主在事务锁或CAS条件下重新核对refresh状态与原jkt，再轮换凭据。不得用新proof自动改绑。真正无效的refresh以`invalid_grant`返回。

token入口捕获`DpopException`后调用`ErrorResponseFactoryInterface::create($exception, true)`；资源中间件自动处理认证错误。nonce挑战后客户端生成新jti、iat和签名，不能重发原proof；业务重试是否幂等仍由业务系统保证。

## 6. 自检、清理和观测

配置后运行`php bin/hyperf.php dpop:doctor`。命令只读配置、schema和必要连接，不写安全账本，不调用用户认证、不执行迁移，不输出凭据。启动监听器会解析必需依赖，缺失resolver或危险配置拒绝启动。

自定义重放存储同时实现`Acme\HyperfDpop\Store\ReplayStoreProbeInterface`；自定义准入预算同时实现`Acme\HyperfDpop\Admission\AdmissionBudgetProbeInterface`。`probe(): void`只读检查真实依赖，失败抛异常，不调用reserve/enter。未实现探测接口时doctor返回非零并标记health unverified，不将“能从DI取出对象”视为依赖健康。探测接口不替代自定义实现的原子性、共享状态和故障恢复责任。

MySQL驱动`purge($limit)`提供有界过期清理，宿主通过自己的定时器调用；批次范围1至10000，默认1000。时间字段以数据库UTC秒为准。

可替换`AuthenticationObserverInterface`接入指标，回调仅接收固定类别、受控错误类别和耗时，不提供token、proof或完整身份。指标异常不能改变认证结果。默认observer不输出日志。

如需自定义响应信封，实现`ErrorResponseFactoryInterface`，保留[错误契约](errors.md)的HTTP状态及挑战头。自定义预算实现必须保持有界、共享的预认证准入；禁止生产注入测试用内存重放存储或Noop预算。

## 7. 示例的使用边界

示例仅监听测试容器的127.0.0.1，使用fixture身份和临时签发密钥，没有真实账号密码认证。HTTP测试模拟TLS在前置代理终止、公开URI为HTTPS的部署。`tests/tls-e2e.php`临时注入DPOP_EXAMPLE_TLS_CERT/KEY，启用9510直连TLS并验证CA、主机名与编码路径；不等于正式代理拓扑验收。禁止将示例控制器直接公开为生产登录接口。

示例JWT的可信签发密钥缺失/损坏返回安全依赖错误；配置正常但令牌伪造/过期才返回invalid_token。该示例需要私钥签发，生产只验证JWT的resolver通常应使用可信公钥/JWKS并独立处理其可用性。

示例复用本工作区Composer锁和vendor，通过容器/tmp应用根启动Hyperf，不依赖其他业务仓库。数据库凭据仅限独立Compose临时库；生产应替换专用凭据并限制网络。
