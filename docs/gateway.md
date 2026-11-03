# 网关接入与重复认证头防护

本页描述0.1.0-beta.1测试版的入口契约；公开版本及验证见[发布记录](release-0.1.0-beta.1.md)。此前e0fd48c开发快照不含gateway发布项，需升级至本次版本。升级时先部署网关规则、固定后端HTTP/1.1并限制后端访问，再更新适配包，以免旧的原生HTTP/2访问直接进入503保护。

Swoole 6.2.4在HTTP/1中覆盖Authorization，但保留原始报文及DPoP多值；HTTP/2中两种字段均覆盖，getData不可用。扩展会检查PSR多值及HTTP/1原始字段，对无法核验原始头的原生Swoole HTTP/2受保护请求返回503 dpop_security_unavailable。客户端仍可通过下述网关使用HTTP/2。

## 1. 入口与后端

客户端HTTPS（HTTP/1.1或HTTP/2）进入HAProxy，网关在任何字段改写前计算Authorization/DPoP完整字段数，重复或逗号列表返回400 invalid_request及no-store；合法请求固定以HTTP/1.1转发到Hyperf。

网关必须是最早能看见完整字段列表的入口。如果CDN、Ingress或其他代理先行丢弃重复字段，必须在那里增加同等检查；后续无法恢复。后端绑定loopback或独立私有网络，仅允许网关访问，不向客户端暴露后端端口。不存在依赖客户端自报“已校验”头的旁路。

网关拒绝在Trailer声明Authorization/DPoP，并通过`option http-drop-request-trailers`剥离实际请求尾部字段，包括未声明的HTTP/1尾部及HTTP/2 trailing HEADERS。普通请求体字节保留；需要在尾部传输校验值的业务应调整协议，由宿主在常规header/body中独立校验，不能依赖本模板继续转发尾部字段。DPoP本身不提供body完整性保证。

## 2. 发布与启动

安装可选hyperf/devtool后，在宿主执行：

```bash
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=gateway
```

只将[模板](../packages/hyperf-dpop/publish/haproxy.cfg)复制为应用根haproxy-dpop.cfg，不创建证书、不改变路由或启动服务；已有文件无force不覆盖。也可手动复制模板。先配置Hyperf在127.0.0.1:9501提供HTTP/1.1服务，并保持公开origin为实际HTTPS地址，示例为https://api.example。根据业务配置来源预算的精确trusted_proxies；网关覆盖X-Real-IP，扩展默认仍不信任代理。

HAProxy3.2示例（证书路径为你部署的完整证书链和私钥合并PEM文件）：

```bash
export DPOP_GATEWAY_BIND='0.0.0.0:443'
export DPOP_GATEWAY_CERT='/etc/haproxy/tls/api.example.pem'
export DPOP_BACKEND_ADDR='127.0.0.1:9501'
haproxy -c -f haproxy-dpop.cfg
haproxy -W -db -f haproxy-dpop.cfg
```

正式服务由宿主进程管理器管理，环境变量也应注入该服务。证书私钥仅授权网关进程读取，不写入源码或镜像。本机loopback后端使用HTTP；跨主机部署应使用受限网络并配置后端TLS及证书校验，模板的默认loopback配置不能直接视为跨主机安全部署。

网关删除客户端Forwarded/X-Forwarded-*并覆盖X-Real-IP。公开URI仍由扩展的固定origin和前缀配置计算，不根据这些头自动改变origin。字段重复检查覆盖所有方法及token/refresh/resource入口；应用层OPTIONS原有无认证预检行为保持不变。

## 3. 应用行为与升级

- HTTP/1中重复认证字段，即使大小写不同或值完全相同，也返回400 invalid_request；body中的同名文本不会被当成请求头。
- 标准`Hyperf\HttpServer\Request`代理同样进行底层原始头检查，不改变当前协程的请求对象。对于附带原生Swoole对象的请求，解析后的认证字段须与起始原始字段一致；不要用withHeader替换凭据。
- 分块载荷按长度跳过，真正的认证尾部字段被拒绝；纯PSR请求也拒绝认证Trailer声明。自定义请求适配器仍应保留多值及原始语义。
- 原生Swoole HTTP/2、缺失或不可读原始报文，受保护请求返回503 dpop_security_unavailable。不要重试同一原生HTTP/2链路或清除令牌；先配置网关转发HTTP/1.1。没有跳过检查的客户端请求头或配置开关。
- HTTP/1原始头块上限64KiB，拒绝obsolete folding及字段名冒号前空白；proof及Authorization各自的既有长度和语法限制不变。
- 纯PSR请求继续使用其多值字段契约。自定义运行时适配器若先行折叠，将丢失检测依据，必须保留字段或在更早入口拒绝。

实测Swoole6.2.4原生HTTP/1网络入口可能等待含尾部字段的报文而不调用PHP处理器；这类等待无法在中间件内修复，也不算认证检查通过。网关剥离尾部字段后的链路已实测正常，因此应保持后端网络隔离，不将Swoole直接暴露给客户端。

## 4. 可复跑的隔离验证

本仓库提供真实Hyperf和HAProxy叠加配置，不开放宿主端口，数据库仅为Compose隔离测试库；先按维护指南准备测试镜像与Composer依赖。

```bash
docker compose -f compose.yaml -f compose.gateway.yaml up -d --wait gateway
docker compose -f compose.yaml -f compose.gateway.yaml exec -T gateway-app php tests/gateway-e2e.php client
docker compose -f compose.yaml -f compose.gateway.yaml run --rm --no-deps gateway-wire
docker compose -f compose.yaml -f compose.gateway.yaml down --remove-orphans
```

固定网关镜像为HAProxy3.2.26及配置中的SHA256。临时CA和私钥只在忽略的build/gateway中生成；测试同时验证TLS证书/主机名与实际协商协议，分别覆盖HTTP/1.1、HTTP/2、三个认证入口、重复值/不同值/顺序/大小写/空值/逗号列表、nonce/签发/刷新/资源访问/重放及未产生副作用。测试网关与后端共享网络命名空间，后端只绑定127.0.0.1；该本地拓扑结果不替代宿主真实CDN/Ingress/网络策略验收。

实际结果见[修复验证报告](verification-duplicate-headers-20261102.md)。

2026-11-03再次审核补充了标准请求代理和真实尾部帧的验证，见[最新复审报告](review-deep-20261103.md)。gateway-wire使用锁定SHA256的Node24测试容器，通过原生crypto独立签名并发送真正的HTTP/2 trailing HEADERS；不是生产依赖，也不会写出令牌或私钥。

依据：[Swoole6.2.4请求实现](https://github.com/swoole/swoole-src/blob/v6.2.4/ext-src/swoole_http_request.cc)、[HAProxy3.2完整字段计数](https://docs.haproxy.org/3.2/configuration.html#7.3.6-req.fhdr_cnt)。
