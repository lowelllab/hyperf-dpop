# 双包发布指南

本项目维护 `lowelllab/dpop-core` 与 `lowelllab/hyperf-dpop` 两个 Composer 包；PHP 命名空间保持 `Acme\Dpop`、`Acme\HyperfDpop`。正式版本为 Hyperf 适配 **0.2.1** 和协议核心 **0.1.0**。[0.1.0 发布记录](release-0.1.0.md)保留首次正式发行证据。[首次beta](release-0.1.0-beta.1.md)与[本地验收](acceptance-beta-20261103.md)保留历史；正式分发不等于独立审计或目标生产环境验收已完成。

## 三个仓库的用途

| 用途 | GitHub 仓库 | Composer 名称 | 本次状态 |
| --- | --- | --- | --- |
| 开发、测试与文档 | [hyperf-dpop](https://github.com/lowelllab/hyperf-dpop) | lowelllab/hyperf-dpop-workspace（project） | 不作为扩展包安装入口、不打本次扩展标签 |
| 核心包分发 | [dpop-core](https://github.com/lowelllab/dpop-core) | lowelllab/dpop-core（library） | v0.1.0，f8fae12，18个文件（含SECURITY.md） |
| Hyperf适配分发 | [hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | lowelllab/hyperf-dpop（library） | v0.2.1，d98d7e8，36 个文件（含 SECURITY.md） |

开发时改主仓对应子包；发布时只将该包白名单文件同步至对应发布仓库。业务项目安装扩展时只访问两个包的公开分发，不需要克隆三个Git仓库。已经登记的workspace条目不用于扩展安装，本次未删除。

Packagist读取仓库根composer.json并从Git标签推导版本，因此不能把主仓URL再次提交为扩展，也不填写composer.json的version字段。[官方发布及版本规则](https://packagist.org/about#managing-package-versions)

## 安装正式版

```bash
composer require "lowelllab/hyperf-dpop:^0.2.1"
```

默认 Packagist 当前只提供适配包 v0.2.1 作为正式版本。适配包依赖核心 `^0.1.0`，默认稳定性下自动安装核心 v0.1.0。Composer 版本由标签推导。

无需path或自定义VCS仓库；安装后的lock应指向上表两个提交，不应继续选择dev-main。开发工作区使用 path 及各包的开发别名，仅供开发测试。

## 本地导出与验证

```bash
docker compose run --rm --no-deps test php tools/prepare-packages.php
docker compose run --rm --no-deps test php tools/verify-package-exports.php
```

每次准备新批次，输出 `build/packagist/<批次>/dpop-core` 与 `hyperf-dpop`，指针为build/packagist-latest.json。只复制composer.json、README、LICENSE、SECURITY.md、src及适用publish模板，manifest保存逐文件SHA256；不包含测试目录、vendor、.env、runtime、私钥或主仓配置。

验证器在全新导出副本建立本地main分支，执行strict及第三个干净VCS消费，确认别名/依赖和PHP API；拒绝重置已有验证仓库。它不会添加远端、推送、打公开标签或提交Packagist。正式发布还要用计划标签测试准确的预发布依赖解析。

## 后续发行顺序

1. 在主仓修复并回归；维护CHANGELOG、兼容/升级说明及实施记录。检查导出白名单、许可与敏感文件。
2. 核对两个发布检出无无关改动、远端main及目标标签；从新导出批次同步文件并比对SHA256。
3. 先提交/推送核心，再提交/推送适配；创建未使用的新标签。两个包独立版本，核心源码不变时后续可以复用已满足约束的既有核心版，无须机械地同时加版本。
4. 创建与发行阶段一致的GitHub Release；beta设置prerelease。禁止移动已发布标签来覆盖修复。
5. 两仓已有Packagist push钩子；逐项核对 `https://repo.packagist.org/p2/lowelllab/<包名>.json` 的版本和source.reference，不能只凭Git推送成功宣称索引完成。
6. 新建无path/VCS覆盖、无开发工作区挂载、独立lock/vendor的Hyperf宿主，从默认Packagist安装目标版本，逐文件核对并复跑真实认证/存储/并发/网关验证，记录远端CI结果。

升级本次适配必须先按[网关接入](gateway.md)部署重复头检查、认证Trailer声明拒绝、实际尾部剥离及后端隔离，固定HTTP/1.1后端。仅更新Composer不能替代网关配置。发布项默认保留现有文件，应人工合并已有配置，勿无故使用force。

## 正式分发与目标部署验收

真实CDN/Ingress/网络策略、容量SLO、HA/状态丢失/恢复演练、独立安全审计及维护/漏洞渠道继续按[维护指南](maintenance.md)验收。0.1.0的正式标签、Packagist索引与本地通过结果不能关闭这些事项；发行决策依据用户最新正式版指令，见DEC-009。原生Swoole HTTP/2的503保护及HTTP/1尾部等待限制保留。

## 升级到 0.2.1

适配包 v0.2.0、v0.1.0 和 v0.1.0-beta.1 已下架。若宿主固定了旧版本或使用本地仓库覆盖，先移除覆盖，再按上面的安装命令更新适配包约束与锁文件。宿主若直接依赖核心 beta，需将其约束更新为 `^0.1.0`。 [当前适配包](https://packagist.org/packages/lowelllab/hyperf-dpop#v0.2.1)

把新增配置键合并到已有 `config/autoload/dpop.php`。mTLS 默认关闭。启用时先准备客户端 CA 和受信网关地址，再用 `dpop:gateway-config --output haproxy-dpop.next.cfg` 生成配置，通过 `haproxy -c` 后部署。宿主继续使用原有日志配置。 [配置步骤](configuration.md) · [v0.2.1 公开安装与验收](release-0.2.1.md)

维护与私密漏洞报告见[SECURITY.md](../SECURITY.md)，三个仓库已启用报告功能。
