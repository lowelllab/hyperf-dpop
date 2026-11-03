# Packagist 分发后独立 Hyperf 深度测试

日期：2026-11-02（Asia/Shanghai）。本轮针对用户要求“重新在 Hyperf 项目中深度测试”，从 Packagist 新建安装链路后完整复跑独立宿主验收。适用检查全部通过，既有两个原始重复认证头部署限制保留；没有修改扩展功能源码，不将开发分支验收写成稳定版或生产部署验收完成。

## 1. 安装来源与隔离

- 新宿主位于同级 `hyperf-dpop-packagist-consumer`，复用已验证的官方 Hyperf 3.2 骨架及独立宿主测试代码；没有从旧项目复制 vendor、composer.lock、.env、运行密钥或证据。
- 根 Composer 不含 repositories，只要求 `lowelllab/hyperf-dpop: ^0.1@dev`，配置 minimum-stability=dev、prefer-stable=true。默认通过 Packagist 重新解析并下载 dist ZIP，核心由传递依赖自动安装。
- Compose 不挂载开发工作区，也不使用 path/VCS 替代源；项目名 hyperf-dpop-packagist-test，MySQL/Redis 无宿主端口，数据库使用 tmpfs。使用已有测试镜像与 Composer 下载缓存，不复用旧业务数据库。
- 已通过公开 Packagist JSON 元数据核对两个包；安装引用如下。核心17文件、适配31文件的SHA256均与开发包一致；所有第三方依赖版本与上轮消费锁文件一致。

| 包 | 安装版本 | 提交引用 |
| --- | --- | --- |
| lowelllab/dpop-core | dev-main，别名0.1.x-dev | cb477548cea9ac63d557c04e6f999735dc6f339e |
| lowelllab/hyperf-dpop | dev-main，别名0.1.x-dev | e0fd48c0df9fb90417dcbbfab395674e7898c339 |

实际运行 PHP 8.5.12、Swoole 6.2.4、Hyperf Framework 3.2.0、DI 3.2.2。新锁共88个生产依赖、55个开发依赖；后半轮实际移除全部55个开发依赖，剩余88个生产包，没有工作区包、旧acme包或测试包混入。PHP命名空间仍使用既有Acme。

## 2. 六组真实 HTTPS 测试

| 运行标签 | 重放驱动 | 开发依赖 | 扫描缓存 | PASS | FAIL | LIMITATION |
| --- | --- | --- | --- | --- | --- | --- |
| mysql | mysql | 有 | false | 85 | 0 | 2 |
| redis | redis | 有 | false | 85 | 0 | 2 |
| mysql-production-cold | mysql | 无 | true | 85 | 0 | 2 |
| mysql-production-warm | mysql | 无 | true | 85 | 0 | 2 |
| redis-production-cold | redis | 无 | true | 85 | 0 | 2 |
| redis-production-warm | redis | 无 | true | 85 | 0 | 2 |

每组执行同一套85项通过检查，六组累计510次通过断言，不能称为510个不同功能场景；两个部署限制同样在六组重复观察。生产冷启动前实际移走扫描缓存，随后热启动复用该驱动生成的缓存；不是只修改运行标签。

覆盖真实TLS CA/主机名检查、nonce挑战及用途隔离、opaque/JWT签发及可信绑定、资源访问、租户隔离、绑定密钥错误、Bearer降级、签名篡改、JSON重复字段、JWK元数据、非法算法/typ/crit/jku、iat/exp/nbf、ath、URI编码/大小写/方法、跨路径重放、宿主撤销、错误状态/挑战头、业务403/500及事务回滚后proof仍消费、observer异常隔离，以及刷新轮换。

各组的并发断言均实际通过：

- 64个并发请求经过2个Worker，主体和租户保持隔离。
- 同一proof的24路竞争：1次成功、23次重放拒绝。
- 同一refresh的8路竞争：1次成功、7次invalid_grant；行锁和轮换由测试宿主实现。

延迟仅保存为本机样本观察，不推导生产容量或SLO。

## 3. CLI、预算和真实故障

| 范围 | 本轮结果 |
| --- | --- |
| CLI | 8项通过；冷/热缓存doctor、缺nonce密钥/不安全origin/缺绑定Resolver拒绝，DDL发布、保留宿主配置、零认证账本写入 |
| 来源预算 | 8项通过；跨Worker共享、伪造XFF不能绕过、429及Retry-After、OPTIONS放行 |
| 全局预算 | 7项通过；token/resource/refresh类别共享、429及Retry-After、OPTIONS放行 |
| 故障与恢复 | 11项通过；真实停止Redis、断开MySQL网络、恢复后同proof首次重试成功再重放拒绝，错误响应不含测试access token |
| 元信息与环境 | Composer strict、平台依赖检查及41个宿主PHP文件语法通过 |
| 依赖公告 | composer audit --locked通过，advisories及abandoned均为空 |

配置有限Redis连接/读取超时后，Redis停止约5516.52ms返回503，MySQL断网约3012.70ms返回503。恢复后未消费的proof可用一次。该结果是单机中断与恢复，不是主从切换、状态丢失或备份恢复；单次socket超时也不等于全链路期限。

Composer对骨架installer/resources中的6个模板类输出PSR-4跳过提示；它们是生成模板，未纳入运行时自动加载。安装和全部实际启动通过；本轮未把该提示列为扩展缺陷。

## 4. 保留的限制

Swoole在应用收到请求前丢弃原始重复Authorization/DPoP头，只保留最后值。本轮再次观察到：重复Authorization无效值在后为401、有效值在后为200；重复DPoP有效值在后为200。两项按LIMITATION单列，没有计入PASS。生产网关必须在折叠前拒绝原始重复认证头，并在实际拓扑验证。

目标硬件容量/SLO、真实网关、HA/状态丢失/备份恢复、独立安全审计、稳定版本标签安装仍未验收；本轮不创建标签、Release、Packagist条目，也不删除误登记的工作区条目。

## 5. 证据、复跑和收尾

[结构化结果](packagist-consumer-acceptance-20261102.json)保存包引用、安装来源、锁文件摘要、六组结果计数及证据SHA256、完整HTTP用例目录、并发结果、CLI、预算、故障及未验项。原始脱敏结果与日志保留在新宿主evidence，随机私钥和临时token只在忽略的runtime中。

本轮实际执行：

```powershell
# 在新建、尚无vendor和composer.lock的独立宿主目录中
# 根composer.json无repositories；只要求适配包，允许dev稳定性

docker compose run --rm --no-deps app composer install --no-interaction --no-scripts --prefer-dist
./acceptance/retest.ps1 -SkipInstall
```

两步均成功；这次已从头执行完整复跑脚本，不是只做语法检查。脚本覆盖六组、CLI、预算、故障和审计，并在finally关闭自身测试服务；末尾查询确认没有本项目容器或网络遗留，其他业务项目未操作。没有修改根README，继续保持功能介绍。

本地源码归档为build/packagist-consumer-project-20261102.zip，包含宿主源码、独立锁、Compose和实际执行的脚本，排除vendor、.env、runtime私钥和原始日志；归档保留空runtime/evidence目录。重复复跑前应保存既有证据与缓存快照，以免追加旧故障结果或误用扫描缓存。扩展镜像仍由开发主仓docker/Dockerfile构建。


## 6. 后续重复头可行性诊断：按协议精化结论

针对用户追问两项限制能否解决，本轮使用临时本地TLS诊断端点，分别强制HTTP/1.1和HTTP/2，发送重复Authorization、重复DPoP及混合大小写DPoP，共6个检查，实际读取Swoole请求及Hyperf PSR请求，未修改包代码或部署网关。诊断端点200仅表示返回检查数据，不能当作业务认证接受；所有值均为合成占位字符串。

| 协议 | 重复Authorization | 重复DPoP | getData原始报文 |
| --- | --- | --- | --- |
| HTTP/1.1 | PSR只保留最后1值 | PSR保留2值，含混合大小写 | 可读，原始两行均保留 |
| HTTP/2 | PSR只保留最后1值 | PSR只保留最后1值 | 不可用，返回false |

因此，前文把“Swoole丢弃原始重复头”作为统一描述过于宽泛，以本节的协议差异为准。HTTP/1.1重复DPoP已能被现有PSR多值检查拒绝，重复Authorization可通过补查原始头处理；HTTP/2丢失的信息不能在现有PSR中间件中恢复。原六组业务用例未记录协商协议，保留历史计数，不追写为逐请求均已确认HTTP/2。

覆盖两种协议的建议是：在最早可见重复头的网关处统一拒绝重复Authorization/DPoP；后端只接受该网关流量，避免绕过；适配包可另加HTTP/1.1原始头检查作为纵深防护。验收需分别强制HTTP/1.1与HTTP/2，测试相同值、不同值、顺序、大小写和正常单头请求；前置网关上游若已折叠，也无法恢复，必须在更早入口拒绝。该修复方案本轮仅完成可行性诊断，尚未实施或部署，原LIMITATION不能改成PASS。

依据：[Swoole6.2.4请求源码](https://github.com/swoole/swoole-src/blob/v6.2.4/ext-src/swoole_http_request.cc)中的getData在HTTP/2分支明确返回false；[HAProxy官方配置手册](https://docs.haproxy.org/2.0/configuration.html)提供req.fhdr_cnt用于完整头字段计数。具体网关版本及配置须在实施时固定并实测。
