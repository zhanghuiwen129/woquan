# 贡献指南

## 提交流程

1. Fork 仓库，从当前主分支创建特性分支：`git checkout -b feature/xxx`。
2. 一个提交只做一件事，提交信息用 `feat:` / `fix:` / `docs:` / `refactor:` / `test:` / `chore:` 开头。
3. 推送到你的 Fork，在 Gitee 发起 Pull Request，说明改动目的、验证方式与影响范围。
4. 涉及使用者行为的改动必须同步修改 `docs/` 下对应说明；涉及接口的改动见下方「接口与文档门禁」。

## 开发环境

| 组件 | 要求 |
| --- | --- |
| PHP | >= 8.0，推荐 8.1；扩展 `PDO`、`PDO_MySQL`、`GD`、`JSON`、`cURL`，推荐 `Fileinfo`、`Redis` |
| MySQL | >= 5.7，字符集 `utf8mb4` |
| Composer | 用仓库自带 `composer.phar` 或系统 Composer 安装依赖 |
| Node.js | 仅在改主题契约 / 静态资源版本时使用 |
| PHPStan | `php scripts/install-phpstan.php` 安装，CI 门禁缺工具即失败 |

本地启动可用 `php -S 127.0.0.1:8899 -t .`，后台页面直接用浏览器打开 `admin/index.html`（本地 PHP 内置服务器对后台路由重写支持有限）。配置只从 `config.php` 的 `define()` 读取，`.env` / `EnvLoader.php` 未接入。

## 代码约定

- 后端命名空间为 `Hublog\`；`api/core/AutoLoader.php` 只注册 `Core` / `Controllers` / `Middleware` 三个 PSR-4 前缀。插件不走 PSR-4，由 `Plugin::load()` 直接 `require_once`，命名空间为 `Hublog\Plugin\{Name}\Plugin`，目录根为 `plugins/`。
- 插件元数据以 `plugin.json` 为准，`name` 必须与目录名一致且匹配 `^[A-Za-z][A-Za-z0-9]*$`；`docblock` 仅在没有清单时回退。插件建表走 `PluginSchemaMigrator` 声明式规格，不写裸 SQL。
- 主题位于 `templates/<id>/`，含 18 个骨架页、`theme.json`、`contract.json` 与 `assets/`；`Theme.php::resolve()` 是唯一解析入口。
- 用户内容必须经过 `renderMarkdown()` 或安全过滤，禁止直接 `v-html`。正文媒体用 `[[media:<type>:<index>]]` 占位符，不靠放宽过滤规则放行 `<video>`。
- 后台图标统一用 `<i data-icon>`，新增后台页要同步导航与页面标题映射。
- 行尾以本机 `core.autocrlf` 配置为准，仓库没有提交 `.gitattributes`；需要按字节比对时用 `git -c core.autocrlf=false archive HEAD`。

## 必跑门禁

| 改动类型 | 命令 |
| --- | --- |
| 全量 CI | `composer ci`（等价于 `php scripts/ci.php`） |
| 单元测试 | `composer test` |
| 行为 / 集成测试 | `composer test:behavior` / `composer test:integration` |
| 路由与 OpenAPI 对账 | `composer docs:routes` 与 `composer docs:check` |
| 主题契约 | `node scripts/audit-theme.js --gen`；生成后确认 `contract.json` 变更一并提交 |
| 迁移契约 | `php scripts/build_migration_contract.php --check`；不一致时用 `--write` 重生成并提交 |
| 计数对账 | `php bin/reconcile-counters.php` |

CI 中 PHPStan（level 3）、后台脚本审计、鉴权守卫审计、日志用法检查、内联 SQL 上限检查、唯一索引 DDL 检查与路由文档对账都是硬门禁，失败即退出非零，不能以“本地环境特殊”为由跳过。

## 数据库迁移

- 新增迁移只在 `api/core/Migration.php` 增加 `migrateNN()`，并同步更新 `$currentVersion`。
- 新表要写入 `api/core/MigrationBatchC.php`；新增批次时同步修改 `scripts/MigrationContractScanner.php` 的 `use` 与 `SOURCES`。
- DDL 的 `try/catch` 会吞掉异常，必须在迁移末尾做终态契约自检；改完后重生成 `MigrationContract.php` 的登记项，否则下次 `--write` 会覆盖手工改动。
- `criticalTablesExist()` 已移除，不要新增依赖它的断言。

## 接口与文档

`api/routes.php` 是路由唯一真源。新增或修改路由必须同时更新 `docs/API_接口手册.md` 与 `docs/openapi.yaml`，并运行 `composer docs:check`。基线 `docs/openapi_route_baseline.json` 记录的历史缺失只允许减少、不允许增加，不要用放宽基线的方式让门禁通过。

面向使用者的文档放在 `docs/`；以源码实际情况为准，不写与实现不符的示例值，不在文档中解释内部命名或沿革。

## 不可提交的内容

- `config.php`、`.env`、真实数据库密码、FTP / API 密钥、授权 RSA 私钥、`data/` 或 `license_system/data/` 下的密钥文件。
- `app-client/`、`.workbuddy/`、`_analysis/`、主题预览截图目录、根目录分析类 Markdown 不进入公开仓库；公开仓库只发布后端范围。
- 示例配置只能写 `config.example.php` / `.env.example`，且不得填入真实凭据。

## 代码权属

`README.md` 与 `NOTICE` 中的代码权属分层声明（上游 Hublog 部分为 Apache-2.0；本项目新增及实质性改写部分保留权利）是授权边界的对外口径，模块清单详述见 [代码来源与授权边界](docs/代码来源与授权边界.md)。

- 新增独立模块 / 能力时，按归属在详述文档的「层 A / 层 B」清单中登记。
- 对上游文件做实质性修改时，在文件头补 `修改记录（Apache-2.0 要求标注变更）` 标注，这是分发时的合规义务。
- 本项目新增的 PHP 文件在文件头标注版权与「商业使用须书面授权」。
- 不要把整仓库笼统描述为「Apache-2.0 开源」或「禁止商用」，两者都与分层事实不符。

## 行为准则

尊重其他贡献者，围绕可复现事实讨论；对安全问题的处理见 [SECURITY.md](SECURITY.md)。
