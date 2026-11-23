<?php
/**
 * Hublog 在线升级工具类
 * 负责升级包解压、数据库/文件备份、覆盖升级与回滚
 *
 * P1-10 修复：
 * 1. extractPackage 规范化每条路径，拒绝含 ../ 的 Zip Slip 攻击；
 * 2. validatePackage 默认强制校验 MANIFEST.sha256 + RSA 签名 + 后台公钥（S3 fail-closed）；
 *    仅 UPGRADE_ALLOW_UNSIGNED=true 时才退化为存在性校验并打印警告日志；
 * 3. backupFiles 排除 config.php（防止含 DB 密码的配置打进备份并泄露）；
 * 4. run() 失败路径调用 restoreFiles / restoreDatabase 自动回滚；
 * 5. copyDirectory 再次规范化相对路径，防止穿越到 basePath 以外。
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Upgrade
{
    private string $basePath;
    private string $backupDir;

    public function __construct()
    {
        $this->basePath = rtrim(PathResolver::basePath(), '/');
        $this->backupDir = $this->basePath . '/backups';
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            throw new HublogException('备份目录创建失败', 500);
        }
    }

    /**
     * 执行升级
     */
    public function run(string $packagePath): array
    {
        if (!extension_loaded('zip')) {
            throw new HublogException('服务器未启用 zip 扩展，无法在线升级', 500);
        }

        $timestamp = date('Ymd_His');
        $dbBackupFile = "hublog_db_{$timestamp}.sql";
        $codeBackupFile = "hublog_code_{$timestamp}.zip";
        $extractDir = $this->backupDir . "/extract_{$timestamp}";
        // P1-10：失败时自动回滚的指针（备份成功后置位 true，后续任何异常触发 restore）
        $backupCompleted = false;
        $backupDbFullPath = $this->backupDir . '/' . $dbBackupFile;
        $backupCodeFullPath = $this->backupDir . '/' . $codeBackupFile;

        try {
            // 1. 备份数据库
            $this->backupDatabase($backupDbFullPath);

            // 2. 备份代码文件（已排除 config.php 等敏感文件）
            $this->backupFiles($backupCodeFullPath);
            $backupCompleted = true;

            // 3. 解压升级包（带 Zip Slip 防护）
            $this->extractPackage($packagePath, $extractDir);

            // 4. 校验升级包：MANIFEST.sha256（含可选 RSA 签名）+ 结构完整性
            $sourceDir = $this->detectPackageRoot($extractDir);
            $this->validatePackage($sourceDir);

            // 5. 覆盖代码（路径规范化，防止穿越 basePath 之外）
            $this->applyPackage($sourceDir);

            // 5.1 同步本地 config.php 中的版本号（config.php 是受保护文件不会被升级包覆盖）
            $versionSynced = $this->syncLocalVersion($sourceDir);

            // 6. 记录备份索引（记录成功意味着升级确认完成）
            $this->recordBackup($timestamp, $dbBackupFile, $codeBackupFile);

            // 7. 执行数据库迁移
            $this->runMigration();

            // 7.1 升级成功后生成文件指纹（供下次 comparePackage 识别本地自定义修改）
            // P3 清理：try/catch 三件套已抽为私有方法 writeLocalFingerprintQuietly()，日志文案与吞异常语义不变
            $this->writeLocalFingerprintQuietly();

            // 8. 清理解压目录
            $this->removeDirectory($extractDir);

            // 9. 清除版本检测缓存，确保升级后立即显示最新状态
            Cache::delete('admin_version_check');

            return [
                'success' => true,
                'timestamp' => $timestamp,
                'db_backup' => $dbBackupFile,
                'code_backup' => $codeBackupFile,
                'version_synced' => $versionSynced,
                'message' => '升级成功，已自动完成数据库迁移' . ($versionSynced ? '' : '（config.php 版本号未同步，请检查文件权限）')
            ];
        } catch (\Throwable $e) {
            // 清理解压目录
            $this->cleanupExtractDir($extractDir);

            // P1-10：如果已完成备份（说明文件替换可能已开始），自动执行回滚恢复到升级前状态
            $rollbackMsg = '';
            if ($backupCompleted && is_file($backupDbFullPath) && is_file($backupCodeFullPath)) {
                $rollbackMsg = $this->attemptRollback($backupCodeFullPath, $backupDbFullPath, $dbBackupFile, $codeBackupFile);
            }

            // 抛异常附带回滚状态，管理端能直接提示用户
            throw new HublogException('升级失败：' . $e->getMessage() . $rollbackMsg, 500);
        }
    }

    /**
     * 回滚到最近一次备份
     */
    public function rollback(): array
    {
        if (!extension_loaded('zip')) {
            throw new HublogException('服务器未启用 zip 扩展，无法回滚', 500);
        }

        $indexFile = $this->backupDir . '/index.json';
        if (!file_exists($indexFile)) {
            throw new HublogException('没有找到可用的备份记录', 404);
        }

        $index = json_decode(file_get_contents($indexFile), true);
        if (empty($index['backups'])) {
            throw new HublogException('没有找到可用的备份记录', 404);
        }

        // 取最近一次升级产生的备份
        $backup = null;
        foreach (array_reverse($index['backups']) as $b) {
            if (!empty($b['upgrade'])) {
                $backup = $b;
                break;
            }
        }
        if (!$backup) {
            throw new HublogException('没有找到升级备份，无法回滚', 404);
        }

        $dbFile = $this->backupDir . '/' . $backup['db_backup'];
        $codeFile = $this->backupDir . '/' . $backup['code_backup'];

        if (!file_exists($dbFile) || !file_exists($codeFile)) {
            throw new HublogException('备份文件缺失，无法回滚', 500);
        }

        // 恢复数据库
        $this->restoreDatabase($dbFile);

        // 恢复代码
        $this->restoreFiles($codeFile);

        return [
            'success' => true,
            'timestamp' => $backup['timestamp'],
            'message' => '回滚成功，已恢复到 ' . $backup['timestamp']
        ];
    }

    /**
     * 导出数据库为 SQL 文件
     */
    private function backupDatabase(string $outputFile): void
    {
        $pdo = Database::getInstance();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

        $sql = "-- Hublog Database Backup\n";
        $sql .= "-- Generated at " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            // 表结构
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            // 表数据
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) continue;

            $columns = array_keys($rows[0]);
            $columnStr = '`' . implode('`, `', $columns) . '`';
            $sql .= "INSERT INTO `{$table}` ({$columnStr}) VALUES\n";

            $values = [];
            foreach ($rows as $row) {
                $escaped = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $escaped[] = 'NULL';
                    } else {
                        $escaped[] = "'" . addslashes($val) . "'";
                    }
                }
                $values[] = '(' . implode(', ', $escaped) . ')';
            }
            $sql .= implode(",\n", $values) . ";\n\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        if (file_put_contents($outputFile, $sql) === false) {
            throw new HublogException('数据库备份文件写入失败', 500);
        }
    }

    /**
     * 打包代码文件（排除运行时目录 + 敏感配置）
     * P1-10：排除 config.php / .env，避免数据库密码与 JWT_SECRET 随 backups/*.zip 泄露
     */
    private function backupFiles(string $outputFile): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($outputFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new HublogException('代码备份压缩包创建失败', 500);
        }

        // 目录排除
        $excludeDirs = ['uploads', 'cache', 'logs', 'backups', '.git', '.DS_Store',
                        'license_system/data'];
        // 敏感文件排除（含 DB_PASS、JWT_SECRET 等）
        $excludeFiles = ['config.php', '.env', '.htpasswd'];
        $this->addDirectoryToZip($zip, $this->basePath, '', $excludeDirs, $excludeFiles);

        $zip->close();
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $realPath, string $zipPath, array $excludeDirs, array $excludeFiles = []): void
    {
        $iterator = new \DirectoryIterator($realPath);
        foreach ($iterator as $file) {
            if ($file->isDot()) continue;
            $name = $file->getFilename();
            if ($file->isDir() && in_array($name, $excludeDirs, true)) continue;
            if (!$file->isDir() && in_array($name, $excludeFiles, true)) continue;

            $localPath = $zipPath ? $zipPath . '/' . $name : $name;
            $fullPath = $file->getPathname();

            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
                $this->addDirectoryToZip($zip, $fullPath, $localPath, $excludeDirs, $excludeFiles);
            } else {
                $zip->addFile($fullPath, $localPath);
            }
        }
    }

    /**
     * 解压升级包
     * P1-10：Zip Slip 防护 —— 遍历压缩包中每一条 entry，规范化路径后确认仍然位于 $extractDir 之内，
     *       拒绝包含 ../、绝对路径、软链接的恶意条目。
     */
    private function extractPackage(string $packagePath, string $extractDir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new HublogException('无法打开升级包', 400);
        }
        if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new HublogException('升级包解压目录创建失败', 500);
        }

        $realExtractDir = realpath($extractDir);
        if ($realExtractDir === false) {
            $zip->close();
            throw new HublogException('解压目录路径解析失败', 500);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            // 拒绝绝对路径、符号链接和 Zip Slip（含 ../）
            if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                $zip->close();
                throw new HublogException('升级包包含非法绝对路径：' . $name, 400);
            }
            if (str_contains($name, '..')) {
                // 进一步 realpath 规范化确认：避免 "foo..bar" 这种边缘情况误判，但 "foo/../bar" 确实含 .. 直接拒绝
                $zip->close();
                throw new HublogException('升级包包含路径穿越尝试：' . $name, 400);
            }

            // 构造真实目标路径，二次校验仍在 extractDir 之内
            $targetPath = $realExtractDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
            $normalized = self::normalizePath($targetPath);
            if (!str_starts_with($normalized, rtrim($realExtractDir, '/\\') . DIRECTORY_SEPARATOR)
                && rtrim($normalized, '/\\') !== rtrim($realExtractDir, '/\\')) {
                $zip->close();
                throw new HublogException('升级包目标路径超出解压目录：' . $name, 400);
            }

            // 单项提取（目录直接创建）
            if (str_ends_with($name, '/')) {
                if (!is_dir($normalized)) {
                    mkdir($normalized, 0755, true);
                }
            } else {
                $parent = dirname($normalized);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    $zip->close();
                    throw new HublogException('升级包文件读取失败：' . $name, 400);
                }
                file_put_contents($normalized, $content, LOCK_EX);
            }
        }
        $zip->close();
    }

    /**
     * P1-10：辅助：规范化文件路径，处理 ./ 和部分相对跳转
     */
    private static function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        // Windows 盘符保留
        $prefix = (isset($parts[0]) && preg_match('/^[A-Za-z]:$/', $parts[0])) ? '' : DIRECTORY_SEPARATOR;
        return $prefix . implode(DIRECTORY_SEPARATOR, $absolutes);
    }

    /**
     * 检测升级包根目录（支持 zip 内多一层目录的情况）
     */
    private function detectPackageRoot(string $extractDir): string
    {
        $entries = array_diff(scandir($extractDir), ['.', '..']);
        if (count($entries) === 1) {
            $first = reset($entries);
            $candidate = $extractDir . '/' . $first;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return $extractDir;
    }

    /**
     * 校验升级包基本结构 + 内容完整性
     * P1-10：
     *   1. 结构校验：4 个关键文件必须存在；
     *   2. SHA256 清单校验：如果升级包根目录包含 MANIFEST.sha256，则逐文件比对哈希；
     *      - 格式：每行 `{sha256} *{相对路径}`（与 sha256sum -c 输出兼容）
     *   3. RSA 签名校验（S3 起默认强制）：清单、签名文件、后台公钥三者缺一即拒绝升级；
     *      只有显式开启 UPGRADE_ALLOW_UNSIGNED 才退化为「仅结构校验 + 警告日志」。
     */
    private function validatePackage(string $sourceDir): void
    {
        // 1. 基础结构校验
        $required = ['index.php', 'api/index.php', 'config.example.php', 'sql/hublog.sql'];
        foreach ($required as $file) {
            if (!file_exists($sourceDir . '/' . $file)) {
                throw new HublogException('升级包校验失败：缺少 ' . $file, 400);
            }
        }

        $manifestFile = $sourceDir . '/MANIFEST.sha256';
        $sigFile = $sourceDir . '/MANIFEST.sha256.sig';

        // S3：下载链路已强制 TLS + SSRF 防护，但覆盖全站代码的包仍需内容可信。
        // 原实现「无清单/无签名/无公钥一律跳过」是 fail-open，中间人只要能替换
        // 归档即可植入任意 PHP。现在默认强制清单 + 签名 + 公钥；私有仓库 / 自托管
        // 确实拿不到签名包时，须由运维在 config.php 显式 define('UPGRADE_ALLOW_UNSIGNED', true)
        // 表示知情降级。
        $allowUnsigned = self::allowUnsigned();

        // 2. 无清单文件
        if (!is_file($manifestFile)) {
            if (!$allowUnsigned) {
                throw new HublogException('升级包缺少 MANIFEST.sha256 清单，无法验证完整性，已拒绝升级', 400);
            }
            error_log('[Hublog Upgrade] 警告：UPGRADE_ALLOW_UNSIGNED 已开启，放行无清单升级包（高风险）。');
            return;
        }

        // 3. RSA 签名校验
        if (!is_file($sigFile)) {
            if (!$allowUnsigned) {
                throw new HublogException('升级包缺少 MANIFEST.sha256.sig 签名，已拒绝升级', 400);
            }
            error_log('[Hublog Upgrade] 警告：UPGRADE_ALLOW_UNSIGNED 已开启，放行无签名升级包（高风险）。');
        } else {
            $this->verifyManifestSignature($manifestFile, $sigFile);
        }

        // 4. 逐文件 SHA256 比对
        $lines = file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new HublogException('升级包 MANIFEST.sha256 读取失败', 400);
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            // 兼容 "hash *path" 与 "hash  path" 两种写法
            if (!preg_match('/^([0-9a-fA-F]{64})\s+\*?(.+)$/', $line, $m)) {
                throw new HublogException('升级包 MANIFEST.sha256 格式非法：' . substr($line, 0, 80), 400);
            }
            [$_, $expectHash, $relPath] = $m;
            $fullPath = $sourceDir . '/' . $relPath;
            // 拒绝穿越（清单本身也要防污染）
            if (str_contains($relPath, '..') || str_starts_with($relPath, '/')) {
                throw new HublogException('升级包 MANIFEST.sha256 含非法路径：' . $relPath, 400);
            }
            if (!is_file($fullPath)) {
                throw new HublogException('升级包清单声明的文件缺失：' . $relPath, 400);
            }
            $actual = hash_file('sha256', $fullPath);
            if (!hash_equals(strtolower($expectHash), strtolower($actual))) {
                throw new HublogException('升级包文件校验失败（SHA256 不匹配）：' . $relPath, 400);
            }
        }
    }

    /** 是否显式允许无签名升级包（默认 false = fail-closed） */
    private static function allowUnsigned(): bool
    {
        return defined('UPGRADE_ALLOW_UNSIGNED') && UPGRADE_ALLOW_UNSIGNED === true;
    }

    /**
     * P1-10：验证 MANIFEST.sha256 的 RSA-SHA256 签名
     * - upgrade_public_key_pem 在后台「升级设置」中配置（来自发布方公钥）
     * - S3：原实现「缺 openssl / 缺公钥只警告不阻止」是 fail-open，现默认拒绝；
     *   仅 UPGRADE_ALLOW_UNSIGNED=true 时才降级放行。
     */
    private function verifyManifestSignature(string $manifestFile, string $sigFile): void
    {
        if (!extension_loaded('openssl')) {
            if (!self::allowUnsigned()) {
                throw new HublogException('未启用 openssl 扩展，无法验证升级包签名，已拒绝升级', 500);
            }
            error_log('[Hublog Upgrade] 警告：openssl 缺失且已显式允许无签名包，跳过 RSA 验签。');
            return;
        }

        // 从 settings 表中读取升级公钥（管理员手动配置）
        // P3 清理：原 try/catch 三件套已抽为 rememberOrDefault()，降级语义（异常时返回 null）与文案不变
        $pubKeyPem = self::rememberOrDefault('upgrade_public_key_pem', function () {
            return (string)(Database::fetchColumn(
                "SELECT `value` FROM settings WHERE `key` = ?",
                ['upgrade_public_key_pem']
            ) ?: '');
        }, 3600, null);

        if ($pubKeyPem === '' || $pubKeyPem === null) {
            if (!self::allowUnsigned()) {
                throw new HublogException(
                    '后台未配置升级公钥（upgrade_public_key_pem），无法验签，已拒绝升级。请在后台上传官方公钥，'
                    . '或在 config.php 显式开启 UPGRADE_ALLOW_UNSIGNED 表示知情降级。',
                    500
                );
            }
            error_log('[Hublog Upgrade] 警告：未配置升级公钥且已显式允许无签名包，跳过 RSA 验签。');
            return;
        }

        $sigRaw = base64_decode((string)file_get_contents($sigFile), true);
        if ($sigRaw === false) {
            throw new HublogException('升级包签名文件 MANIFEST.sha256.sig 非合法 base64', 400);
        }

        $manifestData = (string)file_get_contents($manifestFile);
        $pubKey = openssl_pkey_get_public($pubKeyPem);
        if ($pubKey === false) {
            throw new HublogException('升级公钥格式非法，无法验签。请重新粘贴正确的 PEM 公钥。', 500);
        }
        $ok = openssl_verify($manifestData, $sigRaw, $pubKey, 'sha256WithRSAEncryption');
        openssl_free_key($pubKey);
        if ($ok === 1) {
            return; // 验签通过
        }
        if ($ok === 0) {
            throw new HublogException('升级包 RSA 签名校验失败，可能被篡改。拒绝本次升级。', 400);
        }
        // openssl 返回 -1 = 内部错误
        throw new HublogException('升级包签名验证发生内部错误，请检查公钥与签名格式。', 500);
    }

    /**
     * 将升级包覆盖到当前代码目录
     */
    private function applyPackage(string $sourceDir): void
    {
        $this->copyDirectory($sourceDir, $this->basePath);
    }

    private function copyDirectory(string $source, string $dest): void
    {
        $source = rtrim($source, '/\\');
        $destReal = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dest), '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($source . '/', '', $file->getPathname());
            $relativePath = str_replace($source . '\\', '', $relativePath);
            // P1-10：路径穿越二次防护 —— 规范化后不得跳出 $destReal 之外
            $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
            if (str_starts_with($relativePath, '..') || str_contains($relativePath, DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR)) {
                throw new HublogException('升级复制时检测到非法相对路径：' . $relativePath, 500);
            }
            $targetPath = $destReal . DIRECTORY_SEPARATOR . $relativePath;

            if ($file->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    throw new HublogException('创建目录失败: ' . $targetPath, 500);
                }
            } else {
                // 不要覆盖运行时数据与配置文件
                if ($this->isProtectedFile(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath))) {
                    continue;
                }
                if (!copy($file->getPathname(), $targetPath)) {
                    throw new HublogException('覆盖文件失败: ' . $relativePath, 500);
                }
            }
        }
    }

    /**
     * 受保护文件：配置文件、运行时目录不覆盖
     */
    private function isProtectedFile(string $relativePath): bool
    {
        $protected = ['config.php', '.env', 'uploads/', 'cache/', 'logs/', 'backups/'];
        foreach ($protected as $p) {
            if (strpos($relativePath, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 升级成功后同步本地 config.php 中的版本号
     * 因为 config.php 是受保护文件不会被升级包覆盖，需要手动写入新版本
     */
    private function syncLocalVersion(string $sourceDir): bool
    {
        $exampleFile = $sourceDir . '/config.example.php';
        $localConfig = $this->basePath . '/config.php';
        if (!is_file($exampleFile) || !is_file($localConfig)) {
            return false;
        }

        $exampleContent = file_get_contents($exampleFile);
        // 支持单双引号、括号前后空格等多种写法
        $pattern = "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)\s*;/";
        if (!preg_match($pattern, $exampleContent, $matches)) {
            return false;
        }
        $newVersion = $matches[1];

        $localContent = file_get_contents($localConfig);
        if ($localContent === false) {
            return false;
        }

        // 支持单双引号、括号前后空格，且只替换第一处（避免命中注释行）
        $updated = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . addslashes($newVersion) . "');",
            $localContent,
            1
        );

        if ($updated !== null && $updated !== $localContent) {
            $written = @file_put_contents($localConfig, $updated, LOCK_EX);
            if ($written === false) {
                error_log('[Hublog Upgrade] 无法写入 config.php 版本号，请检查文件权限');
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * 执行数据库迁移
     */
    private function runMigration(): void
    {
        if (file_exists($this->basePath . '/api/core/AutoLoader.php')) {
            require_once $this->basePath . '/api/core/AutoLoader.php';
            \Hublog\Core\AutoLoader::register();
            \Hublog\Core\Migration::run();
        }
    }

    /**
     * 恢复数据库
     */
    private function restoreDatabase(string $sqlFile): void
    {
        $pdo = Database::getInstance();
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new HublogException('读取数据库备份失败', 500);
        }
        $pdo->exec($sql);
    }

    /**
     * 恢复代码文件
     */
    private function restoreFiles(string $zipFile): void
    {
        // P2-12 加固：复用带 Zip Slip 防护的 extractPackage（逐项校验路径，拒绝 ../ 与绝对路径），
        // 不再使用无防护的 $zip->extractTo()，避免恶意备份包路径穿越覆盖站点任意文件。
        $this->extractPackage($zipFile, $this->basePath);
    }

    /**
     * 记录备份索引
     */
    private function recordBackup(string $timestamp, string $dbBackup, string $codeBackup): void
    {
        $indexFile = $this->backupDir . '/index.json';
        $index = ['backups' => []];
        if (file_exists($indexFile)) {
            $index = json_decode(file_get_contents($indexFile), true) ?: ['backups' => []];
        }
        $index['backups'][] = [
            'timestamp' => $timestamp,
            'created_at' => date('Y-m-d H:i:s'),
            'db_backup' => $dbBackup,
            'code_backup' => $codeBackup,
            'upgrade' => true
        ];
        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 每次备份后自动清理，只保留最近 2 次
        $this->cleanupOldBackups(2);
    }

    /**
     * 清理旧备份，只保留最近 N 次
     * 可配置 cron 每天 0 点调用：curl -H "Authorization: Bearer TOKEN" /api/admin/backup/cleanup
     */
    public function cleanupOldBackups(int $keep = 2): int
    {
        $indexFile = $this->backupDir . '/index.json';
        if (!file_exists($indexFile)) {
            return 0;
        }

        $index = json_decode(file_get_contents($indexFile), true) ?: ['backups' => []];
        $backups = $index['backups'] ?? [];
        if (count($backups) <= $keep) {
            return 0;
        }

        // 按时间戳升序，越早的排在越前面
        usort($backups, function ($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });

        $toDelete = array_slice($backups, 0, count($backups) - $keep);
        $remaining = array_slice($backups, count($backups) - $keep);

        foreach ($toDelete as $item) {
            $dbFile = $this->backupDir . '/' . ($item['db_backup'] ?? '');
            $codeFile = $this->backupDir . '/' . ($item['code_backup'] ?? '');
            if (is_file($dbFile)) {
                @unlink($dbFile);
            }
            if (is_file($codeFile)) {
                @unlink($codeFile);
            }
        }

        $index['backups'] = array_values($remaining);
        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return count($toDelete);
    }

    /**
     * 独立创建一次完整备份（数据库 + 代码），供后台「自动备份」与 cron 调用。
     *
     * 与 run() 的区别：不做升级、不覆盖任何代码，只备份 + 登记索引。
     * AdminToolsController::autoBackup() 依赖本方法返回的文件名组织响应。
     *
     * @return array{timestamp:string, created_at:string, db_backup:string, code_backup:string}
     */
    public function createBackup(): array
    {
        // 与 run() 保持同一套命名约定，便于 cleanupOldBackups / rollback 复用索引
        $timestamp = date('Ymd_His');
        $dbBackupFile = "hublog_db_{$timestamp}.sql";
        $codeBackupFile = "hublog_code_{$timestamp}.zip";

        $this->backupDatabase($this->backupDir . '/' . $dbBackupFile);
        $this->backupFiles($this->backupDir . '/' . $codeBackupFile);
        // 内部写 backups/index.json 并按既有策略清理旧备份
        $this->recordBackup($timestamp, $dbBackupFile, $codeBackupFile);

        return [
            'timestamp'   => $timestamp,
            'created_at'  => date('Y-m-d H:i:s'),
            'db_backup'   => $dbBackupFile,
            'code_backup' => $codeBackupFile,
        ];
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ============================================================
    // 升级前差异预览（Dry-run Diff）
    // ============================================================

    /**
     * 对比升级包与本地代码的差异（预演，不执行覆盖）
     *
     * 返回结构：
     * {
     *   "summary": { "total_files": N, "added": N, "modified": N, "removed": N, "protected": N, "skipped_local_custom": N },
     *   "added":    ["path/to/file.php", ...],        // 远程有，本地没有 → 升级时会新增
     *   "modified": ["path/to/file.php", ...],        // 远程和本地都有但 hash 不同 → 升级时会覆盖
     *   "removed":  ["path/to/file.php", ...],        // 本地有，远程没有 → 升级时会被删除
     *   "protected":["config.php", ".env", ...],      // 远程声明了但本地有同名受保护文件 → 不会被覆盖
     *   "skipped_local_custom": [...],                // 本地有自定义修改但不在远程包里（不会被改动）
     *   "warnings":  ["本地文件 xxx 与上一次基线不一致"]   // 本地存在自定义修改但升级包没声明 → 可能冲突
     * }
     *
     * @param string $packagePath zip 包路径
     * @param bool $checkLocalFingerprint 是否检查本地自上次升级后是否被手动修改过（需要 fingerprint.json）
     */
    public function comparePackage(string $packagePath, bool $checkLocalFingerprint = true): array
    {
        if (!extension_loaded('zip')) {
            throw new HublogException('服务器未启用 zip 扩展，无法对比升级包', 500);
        }

        $timestamp = date('Ymd_His');
        $extractDir = $this->backupDir . "/diff_{$timestamp}";

        try {
            // 1. 解压（Zip Slip 防护复用 extractPackage）
            $this->extractPackage($packagePath, $extractDir);
            $sourceDir = $this->detectPackageRoot($extractDir);

            // 2. 扫描远程文件清单 + 计算 sha256
            $remoteFiles = $this->scanFileHashes($sourceDir);

            // 3. 扫描本地文件清单（排除运行时目录 + 敏感配置）
            $localFiles = $this->scanFileHashes($this->basePath);

            // 4. 分类对比
            $protectedSet = $this->getProtectedRelPaths();
            $remoteKeys = array_keys($remoteFiles);
            $localKeys   = array_keys($localFiles);
            $allKeys     = array_unique(array_merge($remoteKeys, $localKeys));

            $added    = [];
            $modified = [];
            $removed  = [];
            $protected = [];
            $skippedCustom = [];

            foreach ($allKeys as $relPath) {
                $inRemote = isset($remoteFiles[$relPath]);
                $inLocal  = isset($localFiles[$relPath]);

                if ($inRemote && !$inLocal) {
                    // 远程有、本地无 → 新增
                    $added[] = $relPath;
                } elseif ($inLocal && !$inRemote) {
                    // 本地有、远程无 → 升级后会删除
                    // 但运行时目录（uploads/cache/logs/backups）不建议删，跳过
                    if ($this->isRuntimeDir($relPath)) {
                        continue;
                    }
                    $removed[] = $relPath;
                } else {
                    // 两边都有
                    if ($remoteFiles[$relPath] === $localFiles[$relPath]) {
                        // hash 相同 → 不变，跳过
                    } elseif (isset($protectedSet[$relPath])) {
                        // hash 不同 + 是受保护文件 → 标记为 protected，不会被覆盖
                        $protected[] = $relPath;
                    } else {
                        // hash 不同 + 非受保护 → 会被升级包覆盖
                        $modified[] = $relPath;
                    }
                }
            }

            // 5. 排序（按目录层级 + 文件名）
            sort($added);
            sort($modified);
            sort($removed);
            sort($protected);

            // 6. 检查本地是否有自定义修改（可选：与上一次升级后的 fingerprint 对比）
            $warnings = [];
            if ($checkLocalFingerprint) {
                $fpFile = $this->basePath . '/cache/local_fingerprint.json';
                if (is_file($fpFile)) {
                    $prevFp = json_decode(file_get_contents($fpFile), true);
                    if (is_array($prevFp)) {
                        foreach ($prevFp as $relPath => $prevHash) {
                            if (isset($localFiles[$relPath]) && $localFiles[$relPath] !== $prevHash) {
                                // 本地文件自上次升级后被改过，但它不在本次升级包的 modified/added 里
                                // 说明是用户自行修改的，升级时不会被覆盖但要提醒
                                if (!in_array($relPath, $modified, true) && !in_array($relPath, $added, true)) {
                                    $skippedCustom[] = $relPath;
                                }
                            }
                        }
                    }
                }
            }

            sort($skippedCustom);

            // 7. 生成汇总
            $report = [
                'summary' => [
                    'total_files'          => count($allKeys),
                    'added'                => count($added),
                    'modified'             => count($modified),
                    'removed'              => count($removed),
                    'protected'            => count($protected),
                    'skipped_local_custom' => count($skippedCustom),
                ],
                'added'                => $added,
                'modified'             => $modified,
                'removed'              => $removed,
                'protected'            => $protected,
                'skipped_local_custom' => $skippedCustom,
                'warnings'             => $warnings,
            ];

            // 8. 释放解压目录（keep 最多 10 分钟，后台异步清理）
            $this->removeDirectory($extractDir);

            return $report;
        } catch (\Throwable $e) {
            // P3 清理：与 run() 相同的"清理解压目录"块已抽为 cleanupExtractDir()
            $this->cleanupExtractDir($extractDir);
            throw $e;
        }
    }

    /**
     * 生成当前本地代码的文件指纹（升级成功后调用，供下次 comparePackage 使用）
     * 仅追踪代码文件（排除 uploads/cache/logs 等运行时目录）
     */
    public function writeLocalFingerprint(): int
    {
        $fpFile = $this->basePath . '/cache/local_fingerprint.json';
        $cacheDir = dirname($fpFile);
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return 0;
        }

        $hashes = $this->scanFileHashes($this->basePath);
        file_put_contents($fpFile, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return count($hashes);
    }

    /**
     * 递归扫描目录，返回 [相对路径 => sha256] 映射
     * 自动排除运行时目录、隐藏文件、敏感配置
     */
    private function scanFileHashes(string $baseDir): array
    {
        $baseDir = rtrim($baseDir, '/\\');
        $result = [];

        if (!is_dir($baseDir)) {
            return $result;
        }

        $protectedDirs = ['uploads', 'cache', 'logs', 'backups', '.git', 'vendor', 'node_modules'];
        $protectedFiles = ['config.php', '.env', '.env.local', 'config.local.php',
                           '.htpasswd', 'cache/local_fingerprint.json', '.workbuddy'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;

            $realPath = $file->getPathname();
            $relative = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $realPath);
            $relative = str_replace(['/', '\\'], '/', $relative);

            // 排除运行时目录
            $skip = false;
            foreach ($protectedDirs as $dir) {
                if (str_starts_with($relative, $dir . '/') || $relative === $dir) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // 排除敏感文件
            foreach ($protectedFiles as $f) {
                if ($relative === $f) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // 跳过 .DS_Store / Thumbs.db
            $name = basename($relative);
            if (str_starts_with($name, '.') || in_array($name, ['Thumbs.db', 'ehthumbs.db'], true)) {
                continue;
            }

            // 跳过 zip 升级包 / 备份文件
            if (preg_match('/\.(zip|tar|gz|rar|7z)$/i', $name)) {
                continue;
            }

            $hash = hash_file('sha256', $realPath);
            if ($hash !== false) {
                $result[$relative] = $hash;
            }
        }

        return $result;
    }

    /**
     * 获取受保护文件的相对路径集合（用于对比时标记为不覆盖）
     */
    private function getProtectedRelPaths(): array
    {
        return [
            'config.php',
            '.env',
            '.env.local',
            'config.local.php',
        ];
    }

    /**
     * 判断相对路径是否属于运行时目录（升级时即使远程删除也不应该删本地）
     */
    private function isRuntimeDir(string $relPath): bool
    {
        $runtimePrefixes = ['uploads/', 'cache/', 'logs/', 'backups/'];
        foreach ($runtimePrefixes as $prefix) {
            if (str_starts_with($relPath, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // ============================================================
    // 在线升级（从 Gitee/GitLab 远程仓库自动下载源码）
    // ============================================================

    /**
     * 默认远程仓库配置（可通过 settings 表覆盖）
     *   upgrade_repo_provider  = 'gitee'
     *   upgrade_repo_owner     = 'aiwoquan'
     *   upgrade_repo_name      = 'woquan'
     *   upgrade_repo_branch    = 'main'
     *
     * 如果没有在后台配置，就用 APP_VERSION 的 git tag 作为 ref（回退策略）
     */
    private function getRepoConfig(): array
    {
        $defaults = [
            'provider' => 'gitee',
            'owner'    => 'aiwoquan',
            'name'     => 'woquan',
            'branch'   => 'main',
            'token'    => '', // 私有仓库可选
        ];

        try {
            // P3 清理：Cache::remember 的 try/catch 三件套已抽为 rememberOrDefault()，
            // 降级语义（DB 异常时返回默认值）与原实现一致
            $settings = self::rememberOrDefault('upgrade_repo_config', function () {
                $rows = Database::getInstance()->query("SELECT `key`, `value` FROM settings WHERE `key` IN
                    ('upgrade_repo_provider','upgrade_repo_owner','upgrade_repo_name','upgrade_repo_branch','upgrade_repo_token')")
                    ->fetchAll(\PDO::FETCH_KEY_PAIR);
                return is_array($rows) ? $rows : [];
            }, 300, []);

            foreach ($defaults as $k => $v) {
                if (!empty($settings["upgrade_repo_{$k}"])) {
                    $defaults[$k] = $settings["upgrade_repo_{$k}"];
                }
            }
        } catch (\Throwable $e) {
            // 数据库异常时使用默认配置，不阻塞在线升级
        }

        return $defaults;
    }

    /**
     * 组装源码归档下载 URL
     *
     * Gitee:  https://gitee.com/{owner}/{repo}/repository/archive/{ref}.zip
     * GitHub: https://github.com/{owner}/{repo}/archive/refs/heads/{branch}.zip
     * GitLab: https://gitlab.com/{owner}/{repo}/-/archive/{branch}/{repo}-{branch}.zip
     */
    public function buildArchiveUrl(?string $ref = null): string
    {
        $cfg = $this->getRepoConfig();
        $ref = $ref ?: $cfg['branch'];

        // S3：owner / name / ref 来自后台设置，直接拼进 URL 会造成 URL 注入与
        // SSRF（例如 owner 填成 "@127.0.0.1" 或 ref 带 ?# 改写请求目标）。
        // 这里做严格字符白名单：只允许字母数字与 . _ -，不含斜杠、@、?、#。
        foreach (['owner' => $cfg['owner'], 'name' => $cfg['name'], 'ref' => $ref] as $key => $value) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$value)) {
                throw new HublogException("升级源参数 {$key} 含非法字符，已拒绝下载", 400);
            }
        }

        switch ($cfg['provider']) {
            case 'github':
                return "https://github.com/{$cfg['owner']}/{$cfg['name']}/archive/refs/heads/{$ref}.zip";
            case 'gitlab':
                return "https://gitlab.com/{$cfg['owner']}/{$cfg['name']}/-/archive/{$ref}/{$cfg['name']}-{$ref}.zip";
            case 'gitee':
            default:
                return "https://gitee.com/{$cfg['owner']}/{$cfg['name']}/repository/archive/{$ref}.zip";
        }
    }

    /**
     * 从远程仓库下载源码 zip 到临时文件
     *
     * @return string 临时文件路径
     * @throws HublogException 下载失败时
     */
    public function downloadSourceZip(?string $ref = null): string
    {
        $url = $this->buildArchiveUrl($ref);
        $cfg = $this->getRepoConfig();

        // S3：下载的是会被解压覆盖全站代码的归档，出站前必须过项目统一的
        // SSRF 白名单（协议 / 私网 / 元数据地址 / 危险端口 / DNS 解析后逐条判定）。
        $check = Security::validateOutboundUrl($url);
        if (!$check['ok']) {
            throw new HublogException('升级源地址不合法：' . ($check['reason'] ?? '未知原因'), 400);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'hublog_upgrade_');
        if ($tmpFile === false) {
            throw new HublogException('无法创建临时文件', 500);
        }
        // tempnam 会生成空文件，追加 .zip 后缀（保持兼容 ZipArchive 识别）
        $zipPath = $tmpFile . '.zip';
        @rename($tmpFile, $zipPath);

        // 1. 优先用 curl（支持 302 跟随 + 自定义 UA + 超时）
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => 'Hublog-Upgrade/' . (defined('APP_VERSION') ? APP_VERSION : 'unknown'),
                // S3：原实现关闭 TLS 校验，管理员触发在线升级时中间人可直接替换
                // 源码包并覆盖站点代码（条件性 RCE）。现在强制校验证书与主机名。
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // 保留 FOLLOWLOCATION（Gitee/GitHub 归档会 302 到对象存储），
                // 但严格限制初始与跳转协议都只能是 HTTPS；私网/危险端口已由
                // 上面的 validateOutboundUrl() 对初始 URL 判定。
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_UNRESTRICTED_AUTH => false, // 私有仓库 token 不跨主机跟随
                CURLOPT_ENCODING       => '', // 自动解压 gzip
            ]);
            // 私有仓库 token（Bearer 或 private-token）
            if (!empty($cfg['token'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $cfg['token'],
                    'PRIVATE-TOKEN: ' . $cfg['token'],
                ]);
            }
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($content === false || $httpCode !== 200) {
                @unlink($zipPath);
                throw new HublogException("下载远程源码失败（HTTP {$httpCode}）：{$error}", 502);
            }

            file_put_contents($zipPath, $content);
        } else {
            // 2. 降级：file_get_contents + follow_location（PHP 5.6+ 支持）
            $opts = [
                'http' => [
                    'method'          => 'GET',
                    'header'          => "User-Agent: Hublog-Upgrade\r\n"
                        . (!empty($cfg['token']) ? "Authorization: Bearer {$cfg['token']}\r\n" : ''),
                    'follow_location' => true,
                    'max_redirects'   => 5,
                    'timeout'         => 120,
                ],
                'ssl' => [
                    // S3：降级分支同样必须校验证书，不能因为没装 curl 就放行 MITM。
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ];
            $content = @file_get_contents($url, false, stream_context_create($opts));
            if ($content === false) {
                @unlink($zipPath);
                throw new HublogException('下载远程源码失败：file_get_contents 请求被拒绝，请启用 curl 扩展', 502);
            }
            file_put_contents($zipPath, $content);
        }

        // 3. 基本校验：PK 头 + 最小体积
        if (filesize($zipPath) < 1024) {
            @unlink($zipPath);
            throw new HublogException('下载的源码包太小（<1KB），可能是远程仓库不存在或网络异常', 400);
        }
        $handle = fopen($zipPath, 'rb');
        $magic = fread($handle, 4);
        fclose($handle);
        if ($magic !== "PK\x03\x04") {
            @unlink($zipPath);
            throw new HublogException('下载的文件不是合法的 zip 源码包（PK 头缺失），可能仓库地址有误或需要配置升级 token', 400);
        }

        return $zipPath;
    }

    /**
     * 在线升级 = 下载 → 差异对比 → 返回报告（不执行覆盖）
     */
    public function onlineCompare(?string $ref = null): array
    {
        // P3 清理：与 onlineRun() 相同的"下载 → try/finally 删临时包"三件套已抽为 withDownloadedZip()
        return $this->withDownloadedZip($ref, function (string $zipPath) use ($ref): array {
            $report = $this->comparePackage($zipPath, true);
            return array_merge($report, [
                'source' => [
                    'type'  => 'remote',
                    'url'   => $this->buildArchiveUrl($ref),
                    'ref'   => $ref ?: $this->getRepoConfig()['branch'],
                ],
            ]);
        });
    }

    /**
     * 在线升级执行 = 下载 → 备份 → 覆盖 → 迁移 → 写指纹 → 返回结果
     */
    public function onlineRun(?string $ref = null): array
    {
        // P3 清理：与 onlineCompare() 相同的"下载 → try/finally 删临时包"三件套已抽为 withDownloadedZip()
        return $this->withDownloadedZip($ref, function (string $zipPath) use ($ref): array {
            $result = $this->run($zipPath);
            $result['source'] = [
                'type' => 'remote',
                'url'  => $this->buildArchiveUrl($ref),
                'ref'  => $ref ?: $this->getRepoConfig()['branch'],
            ];
            return $result;
        });
    }

    /**
     * 在线升级前的预检（轻量，仅下载 zip 不解压全量，获取远程版本号和文件数）
     * 同时也作为 versionCheck 的增强版：返回远程分支、commit sha、文件数等
     */
    public function onlinePreflight(?string $ref = null): array
    {
        $cfg = $this->getRepoConfig();
        $ref = $ref ?: $cfg['branch'];
        $owner = $cfg['owner'];
        $name  = $cfg['name'];
        $provider = $cfg['provider'];

        // 1. 远程 commit sha（通过 API 获取最新提交）
        $commitSha = null;
        $commitDate = null;
        $apiUrl = null;
        switch ($provider) {
            case 'gitee':
                $apiUrl = "https://gitee.com/api/v5/repos/{$owner}/{$name}/branches/{$ref}";
                break;
            case 'github':
                $apiUrl = "https://api.github.com/repos/{$owner}/{$name}/branches/{$ref}";
                break;
            case 'gitlab':
                $apiUrl = "https://gitlab.com/api/v4/projects/" . urlencode("{$owner}/{$name}") . "/repository/branches/{$ref}";
                break;
        }

        if ($apiUrl) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header'  => "User-Agent: Hublog-Upgrade\r\n"
                        . (!empty($cfg['token']) ? "Authorization: Bearer {$cfg['token']}\r\n" : ''),
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $resp = @file_get_contents($apiUrl, false, $ctx);
            if ($resp) {
                $data = json_decode($resp, true);
                if ($provider === 'gitee' && isset($data['commit']['sha'])) {
                    $commitSha  = substr($data['commit']['sha'], 0, 10);
                    $commitDate = $data['commit']['commit']['committer']['date'] ?? null;
                } elseif ($provider === 'github' && isset($data['commit']['sha'])) {
                    $commitSha  = substr($data['commit']['sha'], 0, 10);
                    $commitDate = $data['commit']['commit']['committer']['date'] ?? null;
                } elseif ($provider === 'gitlab' && isset($data['commit']['id'])) {
                    $commitSha  = substr($data['commit']['id'], 0, 10);
                    $commitDate = $data['commit']['committed_date'] ?? null;
                }
            }
        }

        // 2. 当前本地版本
        $currentVersion = defined('APP_VERSION') ? APP_VERSION : 'v2.9.0';

        return [
            'source' => [
                'provider' => $provider,
                'owner'    => $owner,
                'repo'     => $name,
                'branch'   => $ref,
                'archive_url' => $this->buildArchiveUrl($ref),
            ],
            'remote' => [
                'commit' => $commitSha,
                'date'   => $commitDate,
            ],
            'local' => [
                'version' => $currentVersion,
            ],
        ];
    }

    // ============================================================
    // 【P3 清理】重复的 try/catch 三件套统一收敛为下列私有方法
    //
    // 为什么这么改：Upgrade.php 原本在 run() / comparePackage() / verifyManifestSignature()
    // / getRepoConfig() / onlineCompare() / onlineRun() 六处各写了一遍几乎相同的
    // "try { 做事 } catch (\Throwable $e) { 降级/清理 }" 或 "try { 做事 } finally { 清理 }"，
    // 日志文案与降级策略散落多处，改一处漏一处的风险高。
    // 下面五个私有方法只是把「同一段代码 + 同一份错误处理语义」搬到一个地方，
    // 调用点、返回值、异常吞/抛、error_log 文案均逐字保持不变。
    // ============================================================

    /**
     * 写本地指纹（失败只记日志、不影响升级结果）
     *
     * 等价于原 run() 内的：
     *   try { $this->writeLocalFingerprint(); }
     *   catch (\Throwable $e) { error_log('[Hublog Upgrade] 写入本地指纹失败（不影响升级）: ' . $e->getMessage()); }
     */
    private function writeLocalFingerprintQuietly(): void
    {
        try {
            $this->writeLocalFingerprint();
        } catch (\Throwable $e) {
            error_log('[Hublog Upgrade] 写入本地指纹失败（不影响升级）: ' . $e->getMessage());
        }
    }

    /**
     * 清理解压目录（目录不存在时静默跳过）
     *
     * 等价于原两处的：
     *   if (isset($extractDir) && is_dir($extractDir)) { $this->removeDirectory($extractDir); }
     */
    private function cleanupExtractDir(?string $dir): void
    {
        if ($dir !== null && is_dir($dir)) {
            $this->removeDirectory($dir);
        }
    }

    /**
     * 失败后自动回滚代码与数据库，返回要附加到异常消息上的回滚状态后缀
     *
     * 等价于原 run() catch 内的 try/catch 三件套，异常吞/抛与 error_log 文案完全一致：
     *   成功 → '；已自动回滚代码与数据库'
     *   失败 → '；⚠️ 自动回滚失败：<msg>，请使用备份文件手动恢复；DB备份：xxx 代码备份：xxx'
     *          并 error_log('[Hublog Upgrade] 自动回滚失败: ' . msg)
     */
    private function attemptRollback(
        string $backupCodeFullPath,
        string $backupDbFullPath,
        string $dbBackupFile,
        string $codeBackupFile
    ): string {
        try {
            $this->restoreFiles($backupCodeFullPath);
            $this->restoreDatabase($backupDbFullPath);
            return '；已自动回滚代码与数据库';
        } catch (\Throwable $rbe) {
            $rollbackMsg = '；⚠️ 自动回滚失败：' . $rbe->getMessage()
                . '，请使用备份文件手动恢复；DB备份：' . $dbBackupFile . ' 代码备份：' . $codeBackupFile;
            error_log('[Hublog Upgrade] 自动回滚失败: ' . $rbe->getMessage());
            return $rollbackMsg;
        }
    }

    /**
     * 带降级的缓存读取：DB/缓存异常时返回默认值而不是向上抛
     *
     * 等价于原两处的：
     *   try { $x = Cache::remember($key, $cb, $ttl); }
     *   catch (\Throwable $e) { /* 降级 *\/ }
     * 调用方分别传 null（公钥未读到 → 走"未配置公钥"分支）与 []（仓库配置读不到 → 用默认值）。
     */
    private static function rememberOrDefault(string $key, callable $callback, int $ttl, mixed $default): mixed
    {
        try {
            return Cache::remember($key, $callback, $ttl);
        } catch (\Throwable $e) {
            // settings 表 / 缓存异常时降级为默认值，不阻塞升级流程
            return $default;
        }
    }

    /**
     * 下载远程源码 zip → 执行回调 → 无论成败都删掉临时 zip
     *
     * 等价于原 onlineCompare() / onlineRun() 各自写的一遍：
     *   $zipPath = $this->downloadSourceZip($ref);
     *   try { ... } finally { @unlink($zipPath); }
     */
    private function withDownloadedZip(?string $ref, callable $callback): mixed
    {
        $zipPath = $this->downloadSourceZip($ref);
        try {
            return $callback($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }
}
