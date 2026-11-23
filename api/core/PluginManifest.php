<?php
/**
 * 插件清单解析器（E1-1 / E1-5，清单优先、docblock 回退）
 *
 * 读 plugins/{Name}/plugin.json，按架构 §3.2 的字段表与 JSON Schema 手写等价校验
 * （is_* / preg_match / in_array，不引入 json-schema 依赖）；
 * 没有 plugin.json 时完全退化为 Plugin::readMeta() 的 docblock 输出，保证 5 个内置插件零破坏。
 *
 * 校验结果不抛异常：非法清单记 Logger::error 后由调用方（Plugin::loadAll/scanAll、
 * PluginLifecycle）按 valid() 跳过，绝不因一个插件的清单写错而中断整站请求。
 */

declare(strict_types=1);

namespace Hublog\Core;

class PluginManifest
{
    /** schema 允许的顶层字段（additionalProperties:false） */
    private const TOP_FIELDS = [
        'name', 'title', 'description', 'version', 'author', 'link', 'core_version',
        'permissions', 'hooks', 'required_tables', 'required_columns',
        'drop_tables_on_uninstall', 'admin_menu', 'admin_settings',
        'install_hook', 'uninstall_hook', 'upgrade_hook',
    ];

    /** §3.3 白名单类型 */
    private const COLUMN_TYPES = ['int', 'bigint', 'tinyint', 'varchar', 'text', 'longtext', 'datetime', 'decimal'];

    /** 禁止出现在任何字符串值里的关键字（与 additionalProperties:false 双保险） */
    private const FORBIDDEN_VALUE = '/\b(?:DROP|TRUNCATE|RENAME)\b/i';

    private array $data = [];
    private string $dir = '';
    private bool $valid = false;
    private array $errors = [];
    private string $source = 'docblock';
    /** @var list<array{op:string,version:string}> */
    private array $coreConstraints = [];

    private function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * 从插件目录构造清单：plugin.json 优先，缺失时回退 docblock。
     */
    public static function fromDir(string $dir): self
    {
        $manifest = new self($dir);
        $base = rtrim($dir, '/\\');
        $name = basename($base);
        $pluginFile = $base . '/Plugin.php';
        $jsonFile = $base . '/plugin.json';

        $raw = null;
        if (is_file($jsonFile)) {
            $content = @file_get_contents($jsonFile);
            if ($content === false) {
                $manifest->errors[] = 'plugin.json 读取失败';
            } else {
                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    $manifest->errors[] = 'plugin.json 不是合法 JSON: ' . json_last_error_msg();
                } else {
                    $raw = $decoded;
                }
            }
        }

        $meta = is_file($pluginFile) ? Plugin::readMeta($pluginFile) : [];
        $fallback = [
            'name' => $name,
            'title' => $meta['@name'] ?? $name,
            'description' => $meta['@description'] ?? '',
            'version' => $meta['@version'] ?? '1.0.0',
            'author' => $meta['@author'] ?? '',
            'link' => $meta['@link'] ?? '',
            'core_version' => '*',
            'permissions' => [],
            'hooks' => [],
            'required_tables' => [],
            'required_columns' => [],
            'drop_tables_on_uninstall' => false,
            'admin_menu' => [],
            'admin_settings' => null,
            'install_hook' => false,
            'uninstall_hook' => false,
            'upgrade_hook' => false,
        ];

        if ($raw !== null) {
            $manifest->source = 'manifest';
            $manifest->data = array_merge($fallback, $raw);
            // 只对原始清单做 additionalProperties 校验：合并后的 data 含回退字段，不能拿它判非法。
            $manifest->errors = array_merge(
                $manifest->errors,
                $manifest->checkAllowedKeys($raw, self::TOP_FIELDS, 'plugin.json')
            );
            $manifest->checkForbiddenValues($raw, 'plugin.json');
        } else {
            $manifest->source = 'docblock';
            $manifest->data = $fallback;
        }

        $manifest->errors = array_merge($manifest->errors, $manifest->checkFields($name));
        $manifest->valid = $manifest->errors === [];
        if (!$manifest->valid) {
            Logger::error(
                '[Hublog PluginManifest] 插件清单非法，已跳过: ' . $name . ' | ' . implode('；', $manifest->errors)
            );
        }
        return $manifest;
    }

    /** 校验错误列表（空数组 = 合法） */
    public function validate(): array
    {
        return $this->errors;
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** 'manifest' = 有 plugin.json；'docblock' = 回退模式 */
    public function source(): string
    {
        return $this->source;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function name(): string
    {
        return (string)$this->field('name', basename($this->dir));
    }

    /** 统一取值口，供后台展示与领域层使用 */
    public function field(string $key, mixed $fallback = null): mixed
    {
        return $this->data[$key] ?? $fallback;
    }

    public function title(): string
    {
        return (string)$this->field('title', $this->name());
    }

    public function description(): string
    {
        return (string)$this->field('description', '');
    }

    public function version(): string
    {
        return (string)$this->field('version', '1.0.0');
    }

    public function coreVersion(): string
    {
        return (string)$this->field('core_version', '*');
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $value = $this->field('permissions', []);
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> */
    public function hooks(): array
    {
        $value = $this->field('hooks', []);
        return is_array($value) ? array_values($value) : [];
    }

    public function requiredTables(): array
    {
        $value = $this->field('required_tables', []);
        return is_array($value) ? array_values($value) : [];
    }

    public function requiredColumns(): array
    {
        $value = $this->field('required_columns', []);
        return is_array($value) ? array_values($value) : [];
    }

    public function adminMenu(): array
    {
        $value = $this->field('admin_menu', []);
        return is_array($value) ? array_values($value) : [];
    }

    public function adminSettings(): ?array
    {
        $value = $this->field('admin_settings', null);
        return is_array($value) ? $value : null;
    }

    public function dropTablesOnUninstall(): bool
    {
        return (bool)$this->field('drop_tables_on_uninstall', false);
    }

    public function declaresHook(string $hook): bool
    {
        return (bool)$this->field($hook . '_hook', false);
    }

    /**
     * core_version 约束是否满足当前核心版本。
     *
     * @param string $current 形如 APP_VERSION（允许前缀 v/V）
     */
    public function supportsCore(string $current): bool
    {
        return $this->coreVersionError($current) === null;
    }

    /**
     * 不满足约束时返回可读原因，满足时返回 null。
     *
     * 非法表达式在 validate() 阶段已经记入 errors，这里同样返回可读原因以拒绝激活。
     */
    public function coreVersionError(string $current): ?string
    {
        $expr = trim($this->coreVersion());
        $normalized = ltrim($current, 'vV');
        if ($normalized === '') {
            return '当前核心版本为空，无法校验 core_version';
        }
        if ($expr === '' || $expr === '*') {
            return null;
        }
        if ($this->coreConstraints === []) {
            return "插件 {$this->name()} 的 core_version 表达式非法: {$expr}";
        }
        foreach ($this->coreConstraints as $constraint) {
            if (!version_compare($normalized, $constraint['version'], $constraint['op'])) {
                $label = $constraint['op'] === '>=' ? $constraint['version'] : $constraint['op'] . $constraint['version'];
                return "插件 {$this->name()} 要求核心 {$expr}（当前 {$normalized} 不满足 {$label}）";
            }
        }
        return null;
    }

    /** @param list<string> $allowed */
    private function checkAllowedKeys(array $object, array $allowed, string $path): array
    {
        $errors = [];
        foreach (array_keys($object) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                $errors[] = "{$path}.{$key} 不是允许的字段";
            }
        }
        return $errors;
    }

    /** 禁止 ddl/sql/raw 值或 DROP/TRUNCATE/RENAME 关键字混进声明 */
    private function checkForbiddenValues(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->checkForbiddenValues($item, $path . '.' . $key);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        if (preg_match(self::FORBIDDEN_VALUE, $value) === 1) {
            $this->errors[] = "{$path} 含禁止的 DDL 关键字（DROP/TRUNCATE/RENAME）";
        }
    }

    /** 合并后的字段约束校验 */
    private function checkFields(string $dirName): array
    {
        $errors = [];
        $d = $this->data;

        $name = $d['name'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            $errors[] = 'name 必须是 ^[A-Za-z][A-Za-z0-9]*$ 的字符串';
        } elseif ($name !== $dirName) {
            $errors[] = "name({$name}) 必须与目录名({$dirName}) 一致";
        }

        $this->checkString($d['title'] ?? null, 'title', 128, null, $errors);
        $this->checkString($d['description'] ?? null, 'description', 1024, null, $errors);
        $this->checkString($d['version'] ?? null, 'version', 64, '/^[0-9]+\.[0-9]+\.[0-9]+/', $errors);
        $this->checkString($d['author'] ?? null, 'author', 128, null, $errors);
        $this->checkString($d['link'] ?? null, 'link', 255, null, $errors);

        $core = $d['core_version'] ?? '*';
        if (!is_string($core) || strlen($core) > 64) {
            $errors[] = 'core_version 必须是 ≤64 字符的字符串';
        } else {
            $constraints = self::parseCoreExpression($core);
            if ($core !== '*' && $constraints === null) {
                $errors[] = "core_version 表达式非法: {$core}";
            } else {
                $this->coreConstraints = $constraints ?? [];
            }
        }

        $this->checkStringList($d['permissions'] ?? [], 'permissions', $errors);
        $this->checkStringList($d['hooks'] ?? [], 'hooks', $errors);

        foreach (['drop_tables_on_uninstall', 'install_hook', 'uninstall_hook', 'upgrade_hook'] as $boolField) {
            if (!is_bool($d[$boolField] ?? null)) {
                $errors[] = "{$boolField} 必须是布尔值";
            }
        }

        if (!is_array($d['required_tables'] ?? null)) {
            $errors[] = 'required_tables 必须是数组';
        } else {
            foreach ($d['required_tables'] as $i => $spec) {
                $this->checkTableSpec($spec, "required_tables[{$i}]", $errors);
            }
        }
        if (!is_array($d['required_columns'] ?? null)) {
            $errors[] = 'required_columns 必须是数组';
        } else {
            foreach ($d['required_columns'] as $i => $spec) {
                $this->checkColumnAddSpec($spec, "required_columns[{$i}]", $errors);
            }
        }
        if (!is_array($d['admin_menu'] ?? null)) {
            $errors[] = 'admin_menu 必须是数组';
        } else {
            foreach ($d['admin_menu'] as $i => $spec) {
                $this->checkMenuSpec($spec, "admin_menu[{$i}]", $errors);
            }
        }
        if (($d['admin_settings'] ?? null) !== null) {
            $this->checkSettingsSpec($d['admin_settings'], 'admin_settings', $errors);
        }
        return $errors;
    }

    /**
     * core_version 子集解析： >=X / >X / <=X / <X / =X / X / * ，空格或逗号分隔 AND。
     *
     * @return list<array{op:string,version:string}>|null null = 非法表达式
     */
    public static function parseCoreExpression(string $expression): ?array
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '*') {
            return [];
        }
        $tokens = preg_split('/[\s,]+/', $expression, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return null;
        }
        $result = [];
        foreach ($tokens as $token) {
            if ($token === '*') {
                return count($tokens) === 1 ? [] : null;
            }
            if (!preg_match('/^(>=|<=|>|<|=)?([0-9]+\.[0-9]+\.[0-9]+)$/', $token, $m)) {
                return null;
            }
            $op = $m[1] === '' ? '>=' : $m[1];
            // version_compare 不吃单个 '='，统一映射成 '=='
            if ($op === '=') {
                $op = '==';
            }
            $result[] = ['op' => $op, 'version' => $m[2]];
        }
        return $result;
    }

    private function checkString(
        mixed $value,
        string $path,
        int $maxLength,
        ?string $pattern,
        array &$errors
    ): void {
        if (!is_string($value)) {
            $errors[] = "{$path} 必须是字符串";
            return;
        }
        if (strlen($value) > $maxLength) {
            $errors[] = "{$path} 长度超过 {$maxLength}";
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $errors[] = "{$path} 格式非法: {$value}";
        }
    }

    private function checkStringList(mixed $value, string $path, array &$errors): void
    {
        if (!is_array($value)) {
            $errors[] = "{$path} 必须是字符串数组";
            return;
        }
        foreach ($value as $i => $item) {
            if (!is_string($item) || strlen($item) > 64) {
                $errors[] = "{$path}[{$i}] 必须是 ≤64 字符的字符串";
            }
        }
    }

    private function checkColumnSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys(
            $spec,
            ['name', 'type', 'length', 'unsigned', 'nullable', 'default', 'comment', 'auto_increment'],
            $path
        ));
        $this->checkString($spec['name'] ?? null, "{$path}.name", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
        $type = $spec['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::COLUMN_TYPES, true)) {
            $errors[] = "{$path}.type 必须是白名单类型: " . implode('/', self::COLUMN_TYPES);
        }
        if (array_key_exists('length', $spec)
            && (!is_int($spec['length']) || $spec['length'] < 1 || $spec['length'] > 4000)
        ) {
            $errors[] = "{$path}.length 必须是 1~4000 的整数";
        }
        if ($type === 'varchar' && !isset($spec['length'])) {
            $errors[] = "{$path}.length varchar 必须声明 length";
        }
        foreach (['unsigned', 'nullable', 'auto_increment'] as $flag) {
            if (array_key_exists($flag, $spec) && !is_bool($spec[$flag])) {
                $errors[] = "{$path}.{$flag} 必须是布尔值";
            }
        }
        if (array_key_exists('default', $spec)
            && !is_string($spec['default'])
            && !is_int($spec['default'])
            && $spec['default'] !== null
        ) {
            $errors[] = "{$path}.default 只能是字符串/整数/null";
        }
        $this->checkString($spec['comment'] ?? '', "{$path}.comment", 255, null, $errors);
    }

    private function checkTableSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys(
            $spec,
            ['name', 'columns', 'primary_key', 'indexes'],
            $path
        ));
        $tableName = $spec['name'] ?? null;
        if (is_string($tableName) && !str_starts_with($tableName, 'plugin_')) {
            $errors[] = "{$path}.name 必须以 plugin_ 开头";
        }
        $this->checkString(
            $tableName,
            "{$path}.name",
            64,
            '/^plugin_[a-zA-Z][a-zA-Z0-9_]{0,55}$/',
            $errors
        );
        if (!is_array($spec['columns'] ?? null) || $spec['columns'] === []) {
            $errors[] = "{$path}.columns 至少一列";
        } else {
            foreach ($spec['columns'] as $i => $column) {
                $this->checkColumnSpec($column, "{$path}.columns[{$i}]", $errors);
            }
        }
        if (array_key_exists('primary_key', $spec)) {
            $this->checkString($spec['primary_key'], "{$path}.primary_key", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
        }
        if (array_key_exists('indexes', $spec)) {
            if (!is_array($spec['indexes'])) {
                $errors[] = "{$path}.indexes 必须是数组";
            } else {
                foreach ($spec['indexes'] as $i => $index) {
                    $this->checkIndexSpec($index, "{$path}.indexes[{$i}]", $errors);
                }
            }
        }
    }

    private function checkIndexSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys($spec, ['name', 'columns', 'unique'], $path));
        $this->checkString($spec['name'] ?? null, "{$path}.name", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
        if (!is_array($spec['columns'] ?? null) || $spec['columns'] === []) {
            $errors[] = "{$path}.columns 至少一列";
        } else {
            foreach ($spec['columns'] as $i => $column) {
                $this->checkString($column, "{$path}.columns[{$i}]", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
            }
        }
        if (array_key_exists('unique', $spec) && !is_bool($spec['unique'])) {
            $errors[] = "{$path}.unique 必须是布尔值";
        }
    }

    private function checkColumnAddSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys($spec, ['table', 'column', 'after'], $path));
        $this->checkString($spec['table'] ?? null, "{$path}.table", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
        $this->checkColumnSpec($spec['column'] ?? null, "{$path}.column", $errors);
        if (array_key_exists('after', $spec)) {
            $this->checkString($spec['after'], "{$path}.after", 64, '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $errors);
        }
    }

    private function checkMenuSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys($spec, ['group', 'title', 'icon', 'page', 'sort'], $path));
        $group = $spec['group'] ?? null;
        if (!is_string($group) || !in_array($group, ['overview', 'content', 'user', 'safety', 'commerce', 'system'], true)) {
            $errors[] = "{$path}.group 非法";
        }
        $this->checkString($spec['title'] ?? null, "{$path}.title", 32, null, $errors);
        $this->checkString($spec['icon'] ?? '', "{$path}.icon", 32, null, $errors);
        $this->checkString(
            $spec['page'] ?? null,
            "{$path}.page",
            52,
            '/^plugin-[a-zA-Z][a-zA-Z0-9-]{0,47}$/',
            $errors
        );
        if (array_key_exists('sort', $spec) && !is_int($spec['sort'])) {
            $errors[] = "{$path}.sort 必须是整数";
        }
    }

    private function checkSettingsSpec(mixed $spec, string $path, array &$errors): void
    {
        if (!is_array($spec)) {
            $errors[] = "{$path} 必须是对象";
            return;
        }
        $errors = array_merge($errors, $this->checkAllowedKeys($spec, ['page', 'config_scope', 'title'], $path));
        $this->checkString($spec['page'] ?? null, "{$path}.page", 16, '/^settings\.html$/', $errors);
        $this->checkString(
            $spec['config_scope'] ?? null,
            "{$path}.config_scope",
            48,
            '/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/',
            $errors
        );
        $this->checkString($spec['title'] ?? '', "{$path}.title", 32, null, $errors);
    }
}
