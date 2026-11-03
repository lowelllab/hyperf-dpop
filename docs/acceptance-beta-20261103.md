# 已发布beta的本地独立Hyperf验收

日期：2026-11-03（Asia/Shanghai）。验收对象：**lowelllab/dpop-core + lowelllab/hyperf-dpop 0.1.0-beta.1**。结论：**本地独立宿主验收通过**，12类验收目标通过；未发现需要修改扩展包的问题。稳定版及真实生产环境验收继续开放。

## 宿主与执行基线

- 独立项目：`C:/ProjectArchiving/GegoreGos/hyperf-dpop-beta-consumer`。使用官方Hyperf3.2骨架的独立应用、数据库、Redis、lock及vendor，无开发工作区源码挂载。
- 保留上一轮vendor、evidence与容器缓存目录快照；本轮从固定锁文件重新安装88个生产包。没有path/VCS覆盖或minimum-stability=dev，生产vendor没有hyperf/devtool、PHPUnit或主仓测试自动加载。
- 核心提交`503fbfed2410c06682997edf0f345a77dac88d1c`，适配提交`c92cd27b23beae51c205341a338628acc6810db9`；49个包文件与固定发行证据逐字节一致，文件集合无额外源码；宿主锁文件SHA256未变。
- 实测PHP8.5.12、Swoole6.2.4、Hyperf Framework3.2.0、DI3.2.2；HAProxy3.2与Node24测试容器均锁定既有镜像摘要。HTTPS校验CA/主机名及实际协商协议，后端也使用证书校验的TLS/HTTP1.1。
- 不修改已发布包、标签、GitHub Release或固定发行证据；本轮未执行Git暂存、提交、推送或生产部署。

## 验收矩阵

| 编号 | 验收内容 | 本轮实测结果 | 状态 |
| --- | --- | --- | --- |
| A01 | 发布物、依赖与宿主隔离 | 88个生产包、49文件一致、双包标签提交准确、锁未变 | 通过 |
| A02 | 协议正反例与nonce | 四组各87项中的ES256/JWK/严格JSON、htm/htu/iat/exp/nbf/ath、错误密钥/nonce/用途、编码路径及重放检查 | 通过 |
| A03 | 宿主认证、绑定与授权 | opaque/JWT、issuer/audience/过期/cnf、租户绑定、刷新同密钥/CAS、撤销、业务403/500及回滚不释放proof | 通过 |
| A04 | 多Worker与并发一致性 | 每组64请求跨2 Worker身份隔离；24路同proof仅1成功；8路刷新仅1成功 | 通过 |
| A05 | 只读诊断与错误配置 | 冷/热生产缓存doctor、空nonce密钥、不安全origin、缺resolver拒绝，6项；认证账本零写入 | 通过 |
| A06 | 显式模板发布 | 独立工具宿主真实安装hyperf/devtool，schema/gateway精确复制、已有文件保留、config显式force，7项；认证表及schema均未变 | 通过 |
| A07 | 使用文档客户端 | 实际完成nonce协商、登录、资源访问、刷新及新token访问；两次资源身份正确，TLS验证开启 | 通过 |
| A08 | 共享预算与伪造来源 | 来源预算8项、全局跨入口类别7项；耗尽429、Retry-After有界、XFF不能绕过、OPTIONS可预检 | 通过 |
| A09 | 真实入口与尾部字段 | Node原生crypto独立签名；29项H1/H2尾部/正文保持/认证字段不替换/重放/声明拒绝及原生H2保护 | 通过 |
| A10 | 安全依赖故障与恢复 | Redis停止、MySQL网络断开均503；响应不泄露token；恢复后原proof首次接受、再次重放拒绝，11项 | 通过 |
| A11 | 静态与依赖质量 | 43份独立宿主PHP语法；生产宿主与可选工具宿主各自Composer strict及锁定依赖公告审计通过 | 通过 |
| A12 | 清理、证据与复现 | 本项目容器/网络清空、临时故障认证状态删除；其他5个业务容器ID未变；源码归档完整 | 通过 |

四组分别为MySQL+HTTP1冷缓存、MySQL+HTTP2热缓存、Redis+HTTP1冷缓存、Redis+HTTP2热缓存；均为APP_ENV=prod、SCAN_CACHEABLE=true、无开发依赖，**各87 PASS、0 FAIL**。87项是同一集合在四种模式重复，不称为348个独立场景。脚本内0 LIMITATION仅描述其断言集合，不表示下列运行时限制消失。

## 入口和故障的补充核实

HTTP/2实际发送trailing HEADERS，并校验发送标记；HTTP/1通过HTTPS客户端的chunked请求发送尾部，在TLSSocket.write层检查实际序列化的chunk终止与尾部字段字节，仅将布尔发送标记写入结果。有效起始凭据不被恶意尾部破坏，无效或缺失起始凭据也不能被尾部替换；被拒请求不消费proof，成功后重放被拒，JSON正文保持。

完整脚本首次协议专项27项通过后，补强上述HTTP/1发送证据，并只重跑协议专项，最终29项通过；其他通过集合没有无理由重复执行。补跑就绪检查另外3项保留在原始faults.json尾部，主故障结果提取为faults-main.json的11项，不重复累计为新故障场景。

- Redis停止后返回503，实测约 **5.51秒**。
- MySQL断网后返回503，实测约 **3.01秒**。
- 两者恢复后均验证同一proof可成功一次、随后重放被拒。此为本地依赖中断及网络恢复，不是安全状态丢失/HA/备份恢复验收；客户端超时没有被当作成功拒绝。

只读CLI和发布检查在认证服务启动前串行执行，避免并发认证写入干扰零副作用断言。生产宿主不安装devtool；另在`runtime/publishing-host`使用独立Composer根、锁和vendor，从Packagist安装可选命令并验收发布功能。

## 保留限制与未验事项

| 项目 | 本轮结论 |
| --- | --- |
| 原生Swoole HTTP2 | 直接TLS/ALPN实测受保护入口503，符合失败关闭契约；客户端HTTP2通过已检查网关正常工作 |
| 原生HTTP1异常尾部等待 | 保留此前运行时限制，本轮未将原生异常报文重新当作可用路径；验证的是网关剥离后的链路，不能宣称底层已修复 |
| 生产入口和网络隔离 | 本地后端绑定loopback且无宿主暴露端口；真实CDN/Ingress/防火墙部署不在本次环境 |
| 稳定版条件 | 目标容量/SLO、HA/安全状态丢失/备份恢复、独立安全审计及稳定发行验收未执行 |

本轮不重跑开发工作区153测试/281断言；那些结果属于[发行记录](release-0.1.0-beta.1.md)与对应远端CI，不冒充本次独立宿主新执行结果。

## 复现与交付

在独立项目根目录执行：

```powershell
pwsh -NoProfile -File acceptance/accept-release.ps1 -FreshVendor
```

脚本保留旧vendor/证据/缓存快照，准备本次合成TLS与隔离schema，完整执行并在finally移除本项目服务/网络。固定版本源码、测试路由与客户端均在该独立项目，可直接检查；应用新增POST /items及正文回显仅用于验收报文保持，不属于扩展包变更。

- [结构化结果、逐项断言、49文件摘要与输入摘要](acceptance-beta-20261103.json)。
- 原始日志：`build/acceptance-beta-20261103/full.log`；独立宿主结果位于`evidence/`，之前结果以snapshot目录保留。
- 源码复现包：`build/hyperf-dpop-beta-local-acceptance-20261103.zip`，78个文件，包含独立锁文件；排除vendor、runtime、evidence、.env及私钥，ZIP完整性检查通过。
- 源码复现包SHA256：`edbe3c1e67fa2be64ea756c44c90172a86c9eed7a55e3a13731f56ac4ea5809d`。
- 原有固定发行证据SHA256仍为`464a4ef2081ef0fff4024a4803485cbd893b2fd969f988035750ee0d64ee7747`，未覆盖或重新上传。
