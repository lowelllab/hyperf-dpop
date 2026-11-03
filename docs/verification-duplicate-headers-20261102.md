# 重复认证头修复验证

日期：2026-11-02（Asia/Shanghai）。用户确认修复前一轮识别的重复Authorization/DPoP限制。本地实现及目标测试全部通过；修复未推送公开仓库、未创建标签或部署生产网关。

## 1. 根因与修改

Swoole6.2.4在HTTP/1中覆盖Authorization，但原始getData报文仍包含两行；DPoP保留多值。HTTP/2中两者均覆盖且getData返回false。旧HeaderReader仅检查PSR多值，无法检查HTTP/1已覆盖的Authorization，也会接受HTTP/2最后一个有效字段。旧业务报告的“统一丢弃原始头”描述已在后续诊断精化，历史结果不改写。

- HeaderReader在resource/token/refresh共用检查：PSR多值拒绝；附带Swoole原生对象时，HTTP/1额外读取原始头，按字段名不区分大小写计数，重复为400 invalid_request。只检查首个CRLFCRLF前的头部，忽略body中的同名文本；64KiB头块上限，拒绝折叠行和冒号前空白。
- Swoole原生HTTP/2或原始数据不可读时，受保护入口返回503 dpop_security_unavailable，不能静默使用覆盖后的最后值。没有客户端可控制的跳过标志；手工纯PSR请求保留既有多值接口契约。
- 新增publish/haproxy.cfg及gateway发布项。HAProxy3.2在转发前检查完整字段数、拒绝逗号列表，两种客户端协议均适用；向后端强制HTTP/1.1。复制模板不启动网关，无force不覆盖宿主配置。
- 后端只允许网关访问；本地验证使用同网络命名空间和loopback。实际生产CDN/Ingress若更早折叠字段，必须在那里拒绝；不能用后端代码恢复已经丢失的数据。

这修复了已验证部署链路中的安全缺口，并没有使Swoole原生HTTP/2恢复多值头能力。部署步骤和升级顺序见[网关接入](gateway.md)。

## 2. 先失败、后通过

新增20项native请求回归，将HEAD旧HeaderReader挂载到同一测试环境执行：20测试、46断言、12处失败，证据保留在build/duplicate-header-before/phpunit.log；临时旧文件只用于复现，未覆盖工作树源码。运行器旧参数产生一次弃用提示，与12处功能失败分开记录。

修复后的完整composer check通过：

| 检查 | 结果 |
| --- | --- |
| PHPUnit | 128测试、228断言通过 |
| PHPStan | level6，无错误 |
| PHP语法 | 83文件通过 |
| MySQL与Redis竞争 | 各12进程，1接受、11拒绝 |
| Swoole取消 | 不进入业务，认证预算释放 |
| 生产CLI | 17项通过，含gateway发布、保留宿主已有网关和零认证写入 |
| 原HTTP回归 | 27场景通过，32并发跨2Worker主体隔离 |
| 原TLS回归 | 12场景通过，CA/主机名验证及12路同proof仅1成功 |

## 3. HAProxy与真实Hyperf

运行时为PHP8.5.12、Swoole6.2.4、Hyperf3.2.0；HAProxy3.2.26-3e0f11a，镜像固定为haproxy@sha256:5893840576071e6217cd52ebdcc14f454c6f7a631056fa6f4e0f40d3cac4215b。haproxy -c配置检查通过。

compose.gateway.yaml加载实际发布模板，客户端TLS HTTP/1.1及HTTP/2分别强制协商并检查CURLINFO_HTTP_VERSION，后端为loopback HTTP/1.1；不是仅设置标签或模拟PSR字段。171项全部通过：

- resource/token/refresh三个入口分别检查Authorization和DPoP：相同值、有效值前后顺序、大小写变化、空值和逗号列表；全部400 invalid_request、no-store。
- 正常nonce挑战、签发、资源访问、刷新、新令牌资源访问和旧令牌失效正常；重复token头没有产生新会话，被网关拒绝的proof尚可正确使用一次，成功后重放被拒。
- 另起原生Swoole TLS诊断端点验证HeaderReader：HTTP/1单值可用、重复被拒；HTTP/2单值及重复均503。诊断单值成功仅表示头检查通过，不冒称完整认证成功。

CI增加同一网关链路命令；本轮实际在本地执行，未读取或声称GitHub远端CI已经通过。

## 4. 独立Hyperf候选包消费

新建同级hyperf-dpop-gateway-consumer，使用自己的锁/vendor、数据库和Redis，不挂载工作区包源码。修复适配包以本地候选镜像安装，symlink=false；核心仍来自Packagist cb477548cea9ac63d557c04e6f999735dc6f339e。不是修复已发布到Packagist的证明。

49个包文件（core17、adapter32）SHA256全部与当前源码一致；第三方版本没有漂移。实际无开发依赖，仅88个生产包。HAProxy使用原模板检查规则，仅为测试后端添加TLS证书校验/SNI和HTTP/1.1 ALPN；网关及后端使用专属临时CA，不改变系统信任库。公开origin仍为consumer.example:9543，后端仅loopback:9545。

| 组别 | 客户端协议 | 重放驱动 | 生产扫描缓存 | PASS | FAIL | LIMITATION |
| --- | --- | --- | --- | --- | --- | --- |
| mysql-http1-cold | HTTP/1.1 | MySQL | 冷启动 | 87 | 0 | 0 |
| mysql-http2-warm | HTTP/2 | MySQL | 热启动 | 87 | 0 | 0 |
| redis-http1-cold | HTTP/1.1 | Redis | 冷启动 | 87 | 0 | 0 |
| redis-http2-warm | HTTP/2 | Redis | 热启动 | 87 | 0 | 0 |

原85通过项及两个重复头项均按强断言执行；重复头任何一次未返回400都使测试失败，不再以LIMITATION继续。四组重复同一87项集合，不称为348个不同场景。每组均包括64并发跨2Worker主体/租户隔离、同proof24路竞争1成功23拒绝、refresh8路竞争1成功7拒绝。原生OpenSSL独立客户端保持，不借用工作区ProofFactory。

独立宿主Composer strict、37PHP语法及锁文件依赖公告审计通过，advisories/abandoned均为空。新汇总retest-gateway.ps1仅做语法检查；其底层命令本轮已逐项实际执行，不额外声称整段汇总脚本已复跑。

## 5. 交付与边界

[结构化证据](duplicate-header-acceptance-20261102.json)保存版本、旧失败、128/228回归、171项网关结果、四组87项结果、49文件摘要与未验事项；原始脱敏日志在build及新宿主evidence。

build/duplicate-header-consumer-project-20261102.zip保存新宿主、候选包及锁文件；build/lowelllab-hyperf-dpop-header-fix-20261102.zip为32文件的适配包根目录。归档不含vendor、.env、runtime私钥、.git或原始日志，不包含生产凭据。

两套Compose服务、容器和网络已关闭移除，tmpfs数据清除；未操作其他业务项目。公开适配main仍是e0fd48c，本轮只交付本地修复候选；同步发布后还需从Packagist重新安装确认。生产实际CDN/Ingress/防火墙、目标容量/SLO、HA/恢复及独立审计继续单独验收。
