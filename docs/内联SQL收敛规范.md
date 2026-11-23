# 内联 SQL 收敛规范

本规范用于把控制器里的内联 SQL 逐步迁到 `api/core/*Repository.php`，
让数据访问可复用、可测试、可被门禁约束。内联 SQL 包括两类：`Database::` 直连调用，
以及传给 `BaseController::paginate()` 的第 2 / 第 4 个 SQL 字面量参数（listSql / countSql）。
示例实现见 `CircleRepository.php` 与 `CircleController.php`，门禁见 `scripts/check-inline-sql.php`。

## 1. 适用范围与目标

- 适用：`api/controllers/` 下所有控制器与 Trait 中的 `Database::fetchOne` /
  `fetchAll` / `fetchColumn` / `query` / `update` / `insert` / `delete` /
  `beginTransaction` / `commit` / `rollBack` / `getInstance()->prepare(...)`，
  以及 `$this->paginate($request, <SQL字面量>, ..., <SQL字面量>, ...)`。
  注：`beginTransaction` / `commit` / `rollBack` / `inTransaction` 等事务控制调用不计入门禁（无 SQL 字面量、无注入面）。
- 目标：控制器只做业务编排、权限校验、参数校验、缓存与响应包装；SQL、参数绑定、
  事务边界与行结构组装留在 Repository。
- 验收口径：门禁脚本统计控制器内两类内联 SQL 的总数（token 解析，不计注释/字符串）：
  `Database::` 静态调用，以及 `paginate()` 第 2 / 第 4 实参为字符串或 heredoc 的字面量。
  示范批次 `CircleController.php`（原 101）、批次一四文件
  （User/Auth/Payment/PostCrudTrait，原 78/70/61/60）、批次二四文件
  （PostType/PostComment/AdminCircle/App，原 55/53/54/54）、批次三两文件
  （Point/Shop，原 47/46）、批次四九文件
  （PostFeed/PostUpdate/PostUpload/Follow/Chat/RealName/Admin/AdminTools/AdminSettings，
  原 30/24/27/31/34/22/24/25/22）、批次五 28 文件（Badge/Favorite/Notification/Call/Report/
  Activity/AdminActivities/Category/AdminCategory/Search/AdminFinance/AdminPoints/AdminStats/
  AdminBatch/AdminModeration/PostModerationTrait/Metrics/Health/Job/AdminUpgrade/AdminCrash/
  AdminAppVersion/AdminAttachment/AdminFriendLink/PostSettingsTrait/AdminAi/AdminLicense/Location）
  均已降到 0，并以 0 为严格上限。原计划批次文件均已收敛到 0 并纳入严格上限；扩展闭环等新增文件（如 ContentPayController、AdminPluginController）仍在警戒线模式，未纳入严格上限。

## 2. 何时该抽 Repository

满足任一条就应下沉，不要等到"整页重写"：

1. 同一条 SQL 在同文件出现 2 次及以上，或跨文件重复出现；
2. 控制器方法里 SQL 超过 8 行，或 SQL 与业务分支交织、无法一眼看出返回结构；
3. 查询涉及多表 JOIN、软删/可见性条件、分页与排序组合；
4. 一段写操作由 2 条以上语句组成，且有事务语义；
5. 该路径需要写行为回归测试，而下沉后能脱离 Request/Response 单测。

不必下沉：纯配置读取、单次且无复用价值的极简查询（如按主键取一行用于日志）。
判断标准是"是否值得被命名、被复用、被单独测试"，而不是"有没有出现 Database::"。

## 3. 命名与放置约定

| 项 | 约定 |
| --- | --- |
| 文件 | `api/core/{领域}Repository.php`，如 `CircleRepository.php`、`PostRepository.php`、`UserRepository.php` |
| 命名空间 | `Hublog\Core`（PSR-4 自动映射到 `api/core/`，无需手动 require） |
| 类名 | `{领域}Repository`，全部静态方法，与现有 `PostRepository` 风格一致 |
| 方法名 | 读：`find{ByX}` / `fetch{Plural}` / `count{Plural}` / `exists`；写：`insertX` / `updateX` / `deleteX` / `{动词}And{动词}`（事务） |
| 返回类型 | 读单行 `?array`，多行 `array`，计数 `int`，存在性 `bool`，写操作 `void` 或新 ID `int` |
| 注释 | 每个方法写清"对应哪个控制器动作"及软删/可见性口径，便于逐条 review |

方法名携带来 "`?`" 语义：比如 `findVisibleById` 表示带 status/removed_at 过滤，
`findAnyById` 表示不过滤状态。禁止用同一个方法在不同调用点表达不同过滤口径。

## 4. SQL 编写铁律

### 4.1 参数绑定：禁拼接

- 所有**值**必须用 `?` 占位符 + 参数数组传入，包括 `LIMIT ?, ?` 的 offset/limit、
  `IN (...)` 的每个元素、`LIKE` 的前后 `%`。
- 禁止把请求参数、用户 ID、关键词、枚举值、时间直接拼进 SQL 字符串。
- 原代码里形如 `(p.user_id = " . (int)$userId . " OR ...)` 的"已转型所以安全"写法也要改掉：
  转型正确不代表可维护，统一改成绑定参数。
- `IN (...)` 的占位符数量由白名单数组长度生成（标识符生成是安全的）：
  `$ph = implode(',', array_fill(0, count($ids), '?'));`，值仍走参数数组。
- 动态 `UPDATE` 的列名只能来自控制器硬编码白名单；Repository 内再加一道正则校验
  （如 `/^[a-z_]+ = \?$/`），字段名不进参数数组。

### 4.2 分页 / 排序

- `LIMIT` 绑定优先**保持原 SQL 语法形态**：原为 `LIMIT offset, limit` 就用 `LIMIT ?, ?`
  （参数顺序 `offset, limit`）；原为 `LIMIT n OFFSET m` 就用 `LIMIT ? OFFSET ?`
  （参数顺序 `limit, offset`）。两种写法参数顺序相反，切勿照抄某一种而颠倒分页
  （批次三商城四条分页曾踩此坑）。
- 排序字段、排序方向必须与原 SQL 逐字一致；`FIELD(...)`、`ORDER BY weight DESC`
  这类业务排序属于行为的一部分，不得"顺手优化"。
- 分页计数与列表查询必须用**同一套 WHERE**，否则翻页会错位；抽成私有
  `xxxWhere()` 助手同时产出 `$where` 与 `$params`，两处复用。

### 4.3 软删 / 状态 / 可见性

- `status = 1`、`removed_at IS NULL`、`visibility = 'public'`、`cm.status = 1`、
  `audit_state` 等条件一律留在 SQL 的 WHERE 中，不要取回 PHP 再过滤。
- `SELECT *` 用在详情/回显；列表必须写死字段清单且**字段顺序保持原样**，
  因为下游可能按位置或序列化结果比对。
- 表别名、JOIN 条件中的限定名保持原样（如 `c.status = 1` 不能写成 `status = 1`）。
- 涉及 `posts` 的行查询必须带 `p.post_type`；`COUNT(*)` 聚合保持 `COUNT(*)`，
  不要改成 `COUNT(p.post_type)`（后者在 post_type 为 NULL 时会漏计）。

### 4.4 事务与异常

- 一个完整写序列（插入主表 + 插入关联 + 更新计数）作为一个 Repository 方法，
  `beginTransaction` / `commit` / `rollBack` 全部留在 Repository。
- Repository 的 catch 里 `rollBack()` 后 `throw $e`，由控制器转换成原有错误响应，
  保证错误文案与状态码不变。
- 原本没有事务的连续写操作不要擅自加事务——保持行为一致优先。

## 5. 控制器侧保留什么

- 登录态与权限：`requireUser` / `Auth::user` / 角色判断 / `ensureViewable` 门禁。
- 请求解析与校验：`getString` / `getInt` / `pagination` / `normalizeXxx` / 长度与敏感词。
- 缓存边界：`Cache::remember` 的 key、TTL、以及"登录用户专属标记不进缓存"的边界。
- 后处理与响应：`MediaHelper::processXxx`、`Helper::paginatedResponse`、`Response::success/error`。
- 跨领域编排：调用 `PaymentController` / `BadgeService` / `PostRepository` 等。

简单说：控制器里出现 SQL 就是信号；出现 if/else 业务分支是正常的。

## 6. 回归测试怎么写

用 `api/tests/Behavior/BehaviorTestCase.php`（SQLite 内存库，零配置、互不污染）。

1. 测试类继承 `BehaviorTestCase`，在 `setUp()` 里 `parent::setUp()` 后为被测表建最小 schema。
   MySQL 专有函数需要给 SQLite 注册等价实现，例如成员排序的 `FIELD()`：
   `$this->pdo()->sqliteCreateFunction('FIELD', fn(...$a) => ..., -1);`
2. 用 `$this->seed('table', [...])` 造数据，`$this->rows('table')` 验证写副作用。
3. **对照法**：把迁移前的原始 SQL 原样贴在测试私有方法里执行，与 Repository 结果
   `assertSame` 比对。这样字段顺序、排序、分页、可见性、软删任意一个变化都会被抓到。
4. 若 Repository 返回新增的无害列（如为遵守 post_type 铁律而追加的字段），
   在对照时比较 `array_column($rows, 'id')`，并单独断言 `assertArrayHasKey('post_type', ...)`。
5. 每个主要查询路径至少覆盖：空结果、单行、多页、被软删/无权限的负例。
6. 运行：`php vendor/bin/phpunit -c phpunit.xml.dist --testsuite behavior --no-coverage
   --filter {测试类名}`。

参考实现：`api/tests/Behavior/CircleRepositoryBehaviorTest.php`。
迁移前 SQL 快照写在测试私有方法里，不写进产品代码。

## 7. 门禁脚本用法

```bash
php scripts/check-inline-sql.php          # 清单：状态 / 文件 / 调用数 / 阈值
php scripts/check-inline-sql.php --json   # JSON，便于后续接入流水线
```

- 已收敛文件：`CircleController.php`（示范）、批次一 `UserController.php` /
  `AuthController.php` / `PaymentController.php` / `PostCrudTrait.php`、批次二
  `PostTypeTrait.php` / `PostCommentTrait.php` / `AdminCircleController.php` /
  `AppController.php`、批次三 `PointController.php` / `ShopController.php`、批次四
  `PostFeedTrait.php` / `PostUpdateTrait.php` / `PostUploadTrait.php` /
  `FollowController.php` / `ChatController.php` / `RealNameController.php` /
  `AdminController.php` / `AdminToolsController.php` / `AdminSettingsController.php`、
  批次五 28 文件（社交互动 Badge/Favorite/Notification/Call/Report、活动分类搜索
  Activity/AdminActivities/Category/AdminCategory/Search、后台财务积分统计
  AdminFinance/AdminPoints/AdminStats/AdminBatch、审核 AdminModeration/PostModerationTrait、
  系统设置杂项 Metrics/Health/Job/AdminUpgrade/AdminCrash/AdminAppVersion/
  AdminAttachment/AdminFriendLink/PostSettingsTrait/AdminAi/AdminLicense/Location），
  严格上限均为迁移后实际值 0，新增调用即 FAIL 并以退出码 1 阻断。
  原计划批次文件均已收敛到 0 并纳入严格上限；扩展闭环等新增文件（如 ContentPayController、AdminPluginController）仍在警戒线模式，未纳入严格上限。
- 门禁总调用数 = `Database::` 静态调用数 + `paginate()` 第 2/4 实参 SQL 字面量数。
  `AdminAttachmentController` 与 `UserController` 最后 10 处 `paginate()` 字面量
  已分别下沉到 `UploadRepository` / `UserRepository`，控制器改用仓储分页方法后
  自行调用 `Helper::paginatedResponse()` 组装响应；同样受 0 上限保护。
- 其他文件：警戒线 60，命中只标 WARN，不阻断、退出码仍为 0。
- 脚本已接入 `scripts/ci.php` 的 6.7 静态审计与巡检，作为硬门禁；
  已收敛文件新增任何 `Database::` 调用都会让 CI 以退出码 1 失败。
- 每次迁移完成，把对应文件的严格上限更新为迁移后的实际值，再提交。

## 8. 迁移操作步骤（每个方法组一组）

1. 对照原方法，列出它涉及的每条 SQL 与绑定参数。
2. 在 Repository 中按方法组补方法，SQL 逐字搬迁，只把拼接值改成 `?`。
3. 控制器调用替换为 `CircleRepository::xxx(...)`；需要 `int` 形参的调用点显式强转。
4. 增加/更新对照测试，运行 behavior 套件。
5. `php -l` 校验改动文件；运行门禁脚本确认严格上限未破。
6. 用门禁的 invocation count 对比迁移前后，确认只减不增。

## 9. 剩余高重复文件分批清单

按门禁脚本当前扫描结果（调用数降序）排列。已完成示范的 CircleController 单列。

| 批次 | 文件 | 当前调用数 | 建议动作 |
| --- | --- | --- | --- |
| 示范（已完成） | CircleController.php | 0（原 101） | 作为范例，严格上限 0 |
| 批次一（已完成） | UserController.php | 0（原 78） | 收敛到 UserRepository，严格上限 0 |
| 批次一（已完成） | AuthController.php | 0（原 70） | 收敛到 UserRepository，严格上限 0 |
| 批次一（已完成） | PaymentController.php | 0（原 61） | 收敛到 PaymentRepository，严格上限 0 |
| 批次一（已完成） | PostCrudTrait.php | 0（原 60） | 收敛到 PostRepository，严格上限 0 |
| 批次二（已完成） | PostTypeTrait.php | 0（原 55） | 收敛到 PostRepository，严格上限 0 |
| 批次二（已完成） | AdminCircleController.php | 0（原 54） | 收敛到 CircleRepository，严格上限 0 |
| 批次二（已完成） | AppController.php | 0（原 54） | 收敛到新 AppRepository，严格上限 0 |
| 批次二（已完成） | PostCommentTrait.php | 0（原 53） | 收敛到 PostRepository，严格上限 0 |
| 批次三（已完成） | PointController.php | 0（原 47） | 收敛到新 PointRepository，严格上限 0 |
| 批次三（已完成） | ShopController.php | 0（原 46） | 收敛到新 ShopRepository，严格上限 0 |
| 批次四（已完成） | ChatController.php | 0（原 34） | 收敛到新 ChatRepository，严格上限 0 |
| 批次四（已完成） | FollowController.php | 0（原 31） | 收敛到新 FollowRepository，严格上限 0 |
| 批次四（已完成） | PostFeedTrait.php | 0（原 30） | 收敛到 PostRepository，信息流 where 复用，严格上限 0 |
| 批次四（已完成） | PostUploadTrait.php | 0（原 27） | 收敛到新 UploadRepository，严格上限 0 |
| 批次四（已完成） | AdminToolsController.php | 0（原 25） | 收敛到新 AdminRepository，严格上限 0 |
| 批次四（已完成） | AdminController.php | 0（原 24） | 收敛到新 AdminRepository，严格上限 0 |
| 批次四（已完成） | PostUpdateTrait.php | 0（原 24） | 收敛到 PostRepository，严格上限 0 |
| 批次四（已完成） | AdminSettingsController.php | 0（原 22） | 收敛到 SettingsRepository，严格上限 0 |
| 批次四（已完成） | RealNameController.php | 0（原 22） | 收敛到新 RealNameRepository，严格上限 0 |
| 批次五（已完成） | BadgeController.php | 0（原 19） | 收敛到新 BadgeRepository |
| 批次五（已完成） | AdminFinanceController.php | 0（原 18） | 收敛到新 FinanceRepository |
| 批次五（已完成） | CallController.php | 0（原 14） | 收敛到新 CallRepository |
| 批次五（已完成） | FavoriteController.php | 0（原 14） | 收敛到新 FavoriteRepository |
| 批次五（已完成） | AdminActivitiesController.php | 0（原 13） | 收敛到 ActivityRepository |
| 批次五（已完成） | AdminModerationController.php | 0（原 13） | 收敛到新 ModerationRepository |
| 批次五（已完成） | NotificationController.php | 0（原 13） | 收敛到新 NotificationRepository |
| 批次五（已完成） | ActivityController.php | 0（原 10） | 收敛到新 ActivityRepository |
| 批次五（已完成） | AdminPointsController.php | 0（原 10） | 收敛到 PointRepository |
| 批次五（已完成） | CategoryController.php | 0（原 10） | 收敛到新 CategoryRepository |
| 批次五（已完成） | PostModerationTrait.php | 0（原 10） | 收敛到新 ModerationRepository |
| 批次五（已完成） | AdminBatchController.php | 0（原 9） | 收敛到 AdminRepository |
| 批次五（已完成） | AdminStatsController.php | 0（原 9） | 收敛到 AdminRepository |
| 批次五（已完成） | AdminCategoryController.php | 0（原 8） | 收敛到新 CategoryRepository |
| 批次五（已完成） | SearchController.php | 0（原 8） | 收敛到新 SearchRepository |
| 批次五（已完成） | AdminCrashController.php | 0（原 7） | 收敛到 AppRepository |
| 批次五（已完成） | AdminAttachmentController.php | 0（原 5） | 收敛到 UploadRepository |
| 批次五（已完成） | AdminFriendLinkController.php | 0（原 5） | 收敛到新 FriendLinkRepository |
| 批次五（已完成） | ReportController.php | 0（原 5） | 收敛到新 ReportRepository |
| 批次五（已完成） | AdminAppVersionController.php | 0（原 4） | 收敛到 AppRepository |
| 批次五（已完成） | MetricsController.php | 0（原 5） | 收敛到新 SystemRepository（元信息探针转发） |
| 批次五（已完成） | AdminUpgradeController.php | 0（原 2） | 收敛到 SystemRepository（动态表名白名单） |
| 批次五（已完成） | PostSettingsTrait.php | 0（原 2） | 复用 SettingsRepository::fetchKeyValueRows |
| 批次五（已完成） | AdminAiController.php | 0（原 1） | 复用 SettingsRepository::fetchValueByKey |
| 批次五（已完成） | AdminLicenseController.php | 0（原 1） | 新增 SettingsRepository::fetchLicenseSettings（无缓存） |
| 批次五（已完成） | HealthController.php | 0（原 1） | 收敛到 SystemRepository::ping |
| 批次五（已完成） | JobController.php | 0（原 1） | 收敛到 SystemRepository::recentJobs |
| 批次五（已完成） | LocationController.php | 0（原 1） | 复用 SettingsRepository::fetchKeyValueRows |

每完成一个文件，把该行标记为「已完成」并保留在表内作为历史记录；门禁中的严格上限已按收敛后的实际值 0 固定，清单不删除条目。
