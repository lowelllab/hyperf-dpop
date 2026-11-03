# 双包发布指南

本项目维护 `lowelllab/dpop-core` 与 `lowelllab/hyperf-dpop` 两个 Composer 包；PHP 命名空间保持 `Acme\Dpop`、`Acme\HyperfDpop`。首次测试版为 **0.1.0-beta.1**，详见[本次发布记录](release-0.1.0-beta.1.md)。测试版不表示生产部署与独立安全审计已完成。

## 三个仓库的用途

| 用途 | GitHub 仓库 | Composer 名称 | 本次状态 |
| --- | --- | --- | --- |
| 开发、测试与文档 | [hyperf-dpop](https://github.com/lowelllab/hyperf-dpop) | lowelllab/hyperf-dpop-workspace（project） | 不作为扩展包安装入口、不打本次扩展标签 |
| 核心包分发 | [dpop-core](https://github.com/lowelllab/dpop-core) | lowelllab/dpop-core（library） | v0.1.0-beta.1，503fbfe，17个文件 |
| Hyperf适配分发 | [hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | lowelllab/hyperf-dpop（library） | v0.1.0-beta.1，c92cd27，32个文件 |

开发时改主仓对应子包；发布时只将该包白名单文件同步至对应发布仓库。业务项目安装扩展时只访问两个包的公开分发，不需要克隆三个Git仓库。已经登记的workspace条目不用于扩展安装，本次未删除。

Packagist读取仓库根composer.json并从Git标签推导版本，因此不能把主仓URL再次提交为扩展，也不填写composer.json的version字段。[官方发布及版本规则](https://packagist.org/about#managing-package-versions)

## 安装本次测试版

```bash
composer require "lowelllab/hyperf-dpop:0.1.0-beta.1" "lowelllab/dpop-core:0.1.0-beta.1"
```

两个显式根约束允许本次beta，避免将整个宿主minimum-stability降为dev；其他依赖保持宿主稳定性策略。适配包要求核心 `^0.1.0-beta.1`。依赖包中的稳定性标记不能代替宿主根约束。[Composer稳定性规则](https://getcomposer.org/doc/04-schema.md#minimum-stability)

无需path或自定义VCS仓库；安装后的lock应指向上表两个提交，不应继续选择dev-main。开发工作区仍使用path及0.1.x-dev别名，仅供开发测试。

## 本地导出与验证

```bash
docker compose run --rm --no-deps test php tools/prepare-packages.php
docker compose run --rm --no-deps test php tools/verify-package-exports.php
```

每次准备新批次，输出 `build/packagist/<批次>/dpop-core` 与 `hyperf-dpop`，指针为build/packagist-latest.json。只复制composer.json、README、LICENSE、src及适用publish模板，manifest保存逐文件SHA256；不包含测试目录、vendor、.env、runtime、私钥或主仓配置。

验证器在全新导出副本建立本地main分支，执行strict及第三个干净VCS消费，确认别名/依赖和PHP API；拒绝重置已有验证仓库。它不会添加远端、推送、打公开标签或提交Packagist。正式发布还要用计划标签测试准确的预发布依赖解析。

## 后续发行顺序

1. 在主仓修复并回归；维护CHANGELOG、兼容/升级说明及实施记录。检查导出白名单、许可与敏感文件。
2. 核对两个发布检出无无关改动、远端main及目标标签；从新导出批次同步文件并比对SHA256。
3. 先提交/推送核心，再提交/推送适配；创建未使用的新标签。两个包独立版本，核心源码不变时后续可以复用已满足约束的既有核心版，无须机械地同时加版本。
4. 创建与发行阶段一致的GitHub Release；beta设置prerelease。禁止移动已发布标签来覆盖修复。
5. 两仓已有Packagist push钩子；逐项核对 `https://repo.packagist.org/p2/lowelllab/<包名>.json` 的版本和source.reference，不能只凭Git推送成功宣称索引完成。
6. 新建无path/VCS覆盖、无开发工作区挂载、独立lock/vendor的Hyperf宿主，从默认Packagist安装目标版本，逐文件核对并复跑真实认证/存储/并发/网关验证，记录远端CI结果。

升级本次适配必须先按[网关接入](gateway.md)部署重复头检查、认证Trailer声明拒绝、实际尾部剥离及后端隔离，固定HTTP/1.1后端。仅更新Composer不能替代网关配置。发布项默认保留现有文件，应人工合并已有配置，勿无故使用force。

## 稳定版条件

真实CDN/Ingress/网络策略、容量SLO、HA/状态丢失/恢复演练、独立安全审计及维护/漏洞渠道继续按[维护指南](maintenance.md)验收。测试版的标签、Packagist索引与本地通过结果不能关闭这些事项。原生Swoole HTTP/2的503保护及HTTP/1尾部等待限制保留。
