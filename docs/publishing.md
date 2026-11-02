# 双包发布指南

本项目发布两个 Composer 包：`lowelllab/dpop-core` 和 `lowelllab/hyperf-dpop`。PHP 命名空间保持 `Acme\Dpop`、`Acme\HyperfDpop`，Composer 包名与 PHP 命名空间相互独立。

## 开发仓库与发布仓库

当前 `lowelllab/hyperf-dpop` GitHub 仓库是双包开发工作区，根 `composer.json` 的名称为 `lowelllab/hyperf-dpop-workspace`，类型为 `project`。不要把这个 URL 作为扩展包提交给 Packagist，否则提交的将是工作区。

Packagist 读取提交仓库根目录的 `composer.json`；包名中的 vendor 需要使用你有权发布的命名空间，不能使用已被其他账号持有的 `acme`。[Packagist 提交规则](https://packagist.org/about)

保留当前开发仓库，两个发布仓库各自只放对应包内容。两仓库均由用户创建并已同步源码。Composer 包名以各自根配置为准：

| Composer 包 | GitHub 发布仓库 | 状态与仓库根内容 |
| --- | --- | --- |
| `lowelllab/dpop-core` | [lowelllab/dpop-core](https://github.com/lowelllab/dpop-core) | 已推送 main，提交 cb47754；根目录为核心包 |
| `lowelllab/hyperf-dpop` | [lowelllab/hyperf-dpop-package](https://github.com/lowelllab/hyperf-dpop-package) | 已推送 main，提交 e0fd48c；根目录为适配包 |

第二个发布仓库使用 `-package` 后缀，是因为 `lowelllab/hyperf-dpop` 已用于开发工作区。两个发布仓库当前只有开发分支，本助手未提交 Packagist 或创建版本标签；Packagist 登记状态需以提交页面为准。

## 准备本地发布目录

在本工作区执行：

```bash
docker compose run --rm --no-deps test php tools/prepare-packages.php
```

输出位于 `build/packagist/<批次>/dpop-core` 和 `build/packagist/<批次>/hyperf-dpop`；最新批次记录在 `build/packagist-latest.json`。每个目录的根配置均为 `library`，只包含自己的 composer.json、README、LICENSE、src，以及适用的 publish 模板，不包含工作区 vendor、测试凭据、运行目录或根项目依赖。

`manifest.json` 保存每个导出文件的 SHA256。导出命令只生成本地目录，不提交代码、不创建远端、不创建版本标签，也不向 Packagist 提交。

验证新导出的两个目录：

```bash
docker compose run --rm --no-deps test php tools/verify-package-exports.php
```

验证器需要 Git。测试镜像已包含 Git；旧镜像先执行 `docker compose build test`。验证器对两个包执行 Composer strict 校验，为导出副本分别建立本地 main 分支，再在第三个干净消费项目中从两个 VCS 仓库安装扩展，验证核心自动引入、分支别名、既有 PHP 接口及 ConfigProvider。它不会给本地副本添加远端或推送。

每次验证应使用一个新导出批次，已有导出 Git 仓库不会被重置。验证结果写入该批次的 `verification.json` 和 `verification.log`；正式提交时可重新生成一份没有验证用 `.git` 的干净批次。

## 公开提交的顺序

两个仓库的首次源码推送已完成；Packagist 登记按以下顺序处理，准备工具不自动执行这些外部操作。

1. 两个发布仓库的创建、包根配置及首次推送已完成。后续维护从对应子包同步内容，不能把开发工作区推送到发布仓库。
2. 确保公开 Packagist 可以访问这两个仓库，且提交账号有权使用 `lowelllab` vendor。
3. 先提交核心包仓库 URL，确认 Packagist 将其识别为 `lowelllab/dpop-core`。
4. 再提交适配包仓库 URL，确认识别为 `lowelllab/hyperf-dpop`，并能解析对核心包的依赖。
5. 配置各仓库的 Packagist 同步；维护后从相应子包重新同步内容，不把开发工作区或另一个包提交进去。

两个包已声明 `dev-main` 到 `0.1.x-dev` 的分支别名，允许当前 `^0.1@dev` 依赖在尚未打版本标签时解析。消费项目仍需显式允许开发稳定性，不能把 main 分支称为稳定发行版。

公共包提交成功后，可以在消费项目中使用以下开发版本配置，而不再使用本地 path 仓库：

```json
{
  "require": {"lowelllab/hyperf-dpop": "^0.1@dev"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

## 已有开发项目迁移

安装过 `acme` 占位包的消费项目，应把 Composer require、path versions 映射及命令中的包名改为 `lowelllab`。刷新锁文件和 vendor 后，确认旧包已移除；不要只手改 composer.lock。PHP 类名、ConfigProvider 和 DI 绑定仍使用原有 `Acme` 命名空间。

```bash
composer update --with-all-dependencies
php bin/hyperf.php vendor:publish lowelllab/hyperf-dpop --id=config
php bin/hyperf.php dpop:doctor
```

发布配置默认保留已有文件，不要无故加 `--force`。当安装使用文件副本而不是符号链接时，源码改变还需重新安装对应包。

## 稳定版前的条件

包名就绪和开发分支可安装不等于稳定版验收完成。生产网关重复头处理、目标容量、故障切换/备份恢复、独立安全审计与维护/漏洞渠道仍按[维护指南](maintenance.md)落实。正式版本标签应与实际发行阶段一致，并先确保核心包版本能满足适配包依赖。
