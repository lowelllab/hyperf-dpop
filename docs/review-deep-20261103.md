# 2026-11-03 深度审核与维护

本轮继续审核本地未发布的修复候选，覆盖入口原始头、标准Hyperf代理、HTTP/1分块与HTTP/2尾帧、签发/刷新/绑定、nonce与URI、两种防重放存储、预算/取消及发布产物。发现并修复两项入口问题；验签、防重放和刷新轮换未发现新的功能缺陷。该结论不是独立密码学审计或生产环境验收。

## 1. 发现与修复

| 编号 | 优先级 | 证据与影响 | 维护结果 |
| --- | --- | --- | --- |
| REV3-001 | P1 | HeaderReader只识别Hyperf原生Request，标准Hyperf\HttpServer\Request代理会被当作普通PSR对象，跳过原始Authorization及原生HTTP/2检查；可绕过头部唯一性检查，签名及持钥校验仍适用 | 通过不可变PSR操作解析到底层请求；不修改协程RequestContext；真实HTTP/1、HTTP/2代理路径及上下文不变回归通过 |
| REV3-002 | P1 | Swoole Request::parse可将尾部Authorization覆盖到起始字段，旧逻辑不比较原始/解析凭据；网关也未丢弃尾部字段。旧HTTP/2到HTTP/1链路遇到真实尾帧还出现等待超时 | 禁止认证Trailer声明/实际字段，核对起始原始字段与PSR字段一致；按chunk长度跳过载荷；HAProxy拒绝认证声明并丢弃全部实际请求尾部字段，普通body保留 |

没有把Swoole原生解析器中的“检查接受”写成真实网络认证200；实际旧网关尾帧测试记录的是超时，二者有明确区别。原生网络解析等待的保留限制见第4节。

## 2. 先失败后修复的证据

首先复跑既有基线：128测试/228断言通过。新增加25项入口回归，保存本地0.7 HeaderReader副本并用临时只读挂载复现，25项中15项失败；修正后25项全部通过。旧副本不覆盖工作树代码。

新增回归覆盖标准代理重复头、原生HTTP/2、协程上下文不变、声明/未声明的认证尾部字段、相同/不同值、只有尾部提供凭据、解析后替换凭据，以及分块载荷中看似头部/零块的文本、分块扩展和普通校验尾部，避免简单body正则误判。

原始证据在build/review3-20261103，包括baseline.log、ingress-before.log、final-check.log、h2-before-post.json、h2-after.json、gateway-after.json及独立宿主复跑日志。证据仅记录合成场景名、状态与计数，不保存真实凭据。

## 3. 最终验证

| 范围 | 结果 |
| --- | --- |
| 完整composer check | 153测试/281断言，PHPStan6无错误，84文件语法通过 |
| 重放竞争/取消 | MySQL、Redis各12进程，1接受11拒绝；取消不进业务且释放预算 |
| 生产CLI | 17项通过，含gateway发布及保留宿主文件 |
| 原HTTP/TLS | HTTP27、TLS12通过，保留并发身份隔离和同proof竞争 |
| 真实网关/原生边界 | 180项通过；1项原生网络等待单列LIMITATION，未计PASS |
| 真实HTTP/2尾帧 | Node24.22.2原生crypto独立ES256签名，17项通过；实际发送trailing HEADERS并验证TLS及h2 ALPN |
| 独立Hyperf生产宿主 | MySQL/Redis各HTTP/1.1冷缓存、HTTP/2热缓存，四组各87 PASS/0 LIMITATION |
| 发布产物 | core17/adapter32，共49文件与独立vendor逐项SHA256一致；两包strict及干净本地VCS消费通过 |
| 锁定依赖公告 | Composer audit通过，advisories/abandoned均为空；未升级第三方依赖 |

独立宿主这次实际完整执行acceptance/retest-gateway.ps1 -SkipInstall，不再只是脚本语法检查；仅88个生产包，没有开发依赖。每组87项含64并发的2Worker身份/租户隔离、24路同proof仅1成功、8路refresh仅1成功。四组重复同一检查集合，不能称为348个不同场景。

候选包使用path镜像，修改后显式composer reinstall再测试；其composer.lock保持不变，因此没有用锁文件未变来推断源码相同，而是逐项核对49文件摘要。核心仍锁定Packagist cb47754，适配为本地候选，不是修复已进入Packagist的证明。

HTTP/2尾帧测试独立生成P-256会话密钥与proof，验证尾部不能替换/破坏起始凭据、未消费proof可正常使用、成功后重放拒绝、只有尾部凭据不能认证、禁止声明及普通body/无害尾部兼容。测试报告不输出令牌或密钥；Node镜像锁定在compose.gateway.yaml，仅为测试依赖。

## 4. 明确保留的运行时限制

真实Swoole6.2.4原生HTTP/1入口收到尾部字段时，可能在调用PHP处理器前继续等待。实测客户端8秒读超时、没有响应；未算作400、认证接受、请求取消或PASS。换用无分块扩展的报文仍复现，不能简单归因于chunk extension。后续新连接的普通原生请求正常。

旧网关不剥离尾部时，HTTP/2尾帧转HTTP/1也出现该等待；使用HAProxy http-drop-request-trailers后，相同公开链路已实测响应且凭据/请求体保持正确。中间件不能修复进入PHP前的网络解析等待，因此生产后端必须只对网关可达，不能将Swoole原生端口公开。原生Swoole HTTP/2也继续返回503保护；客户端HTTP/2通过网关正常使用。

尾部认证字段在完整Request::parse模型中可覆盖字段的风险已修复；对Swoole自身所有原生网络trailers语义没有作支持声明。真正CDN/Ingress/防火墙、目标容量/SLO、HA/恢复及独立审计仍待部署验收。

## 5. 测试工具修正与交付边界

测试过程中修正了空header列表多出CRLF的fixture，以及HTTP/2提前400后继续发送尾帧导致连接关闭的测试方式。最终旧源码15失败来自修正后的fixture；真正未声明的尾帧有独立发送标记与状态断言。未把工具适配、网络超时或观察项误报为通过。

[结构化结果](review-deep-20261103.json)保存发现、旧失败、全部目标验证、源码摘要、两套协议结果和保留限制。本地build/hyperf-dpop-review3-package-20261103.zip及build/hyperf-dpop-review3-consumer-20261103.zip分别为32文件适配包根和独立候选宿主，排除vendor、.env、runtime、.git及原始日志；旧归档保留历史。

两套实验Compose已关闭移除，tmpfs数据清除，其他业务项目未操作。修复仍留本地工作区，没有推送、创建标签、修改Packagist条目或部署生产网关。使用和升级顺序见[网关接入](gateway.md)，本次审核不能替代发布后的实际安装复验。

参考：[Swoole6.2.4请求源码](https://github.com/swoole/swoole-src/blob/v6.2.4/ext-src/swoole_http_request.cc)、[HAProxy3.2尾部丢弃选项](https://docs.haproxy.org/3.2/configuration.html#4.2-option%20http-drop-request-trailers)。Hyperf代理实现及PSR转接同时核对了本地安装的3.2源码。
