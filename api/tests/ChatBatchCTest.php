<?php
/**
 * 私信模块 · 批次 C 回归护栏（软删除语义统一 + ChatService 七 Trait 拆分）
 *
 * 本文件在 T01 建立「方法清单 + 源码视图顺序」基线，后续 T02/T03/T04 逐步追加断言：
 *   T02：V99 迁移 / 终态契约精确哨兵
 *   T03：软删除 fail-closed / 零物理删 / 跨表原子闸门
 *   T04：37 个原方法 + 新方法全部挂在 ChatService 上（反射校验）
 *
 * 运行：php api/tests/run_tests.php
 * 说明：全部为纯静态 / 反射断言，不依赖数据库。
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__) . '/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
require_once __DIR__ . '/SourceView.php';

class ChatBatchCTest extends \PHPUnit\Framework\TestCase
{
    /**
     * 基线：ChatService 拆分前的 29 个 public 方法（get_class_methods 口径）。
     * 架构文档写的「33」与实际源码不符，这里以反射实测为准，绝不漏一个。
     */
    private const BASELINE_PUBLIC_METHODS = [
        'checkBlockedBothWays',
        'getOrCreatePrivateConversation',
        'getConversations',
        'getMessages',
        'getNewMessages',
        'sendMessage',
        'markAsRead',
        'getTotalUnread',
        'invalidateUnreadCache',
        'validateMembership',
        'columnExists',
        'visibilitySql',
        'contentHash',
        'tableExists',
        'softDeleteOwnMessages',
        'restoreOwnMessages',
        'recycleBin',
        'adminGetConversations',
        'adminGetMessages',
        'adminGetDeletions',
        'adminRestoreMessage',
        'adminExportRows',
        'adminLog',
        'adminDeleteMessage',
        'adminBatchDeleteMessages',
        'adminCleanupExpired',
        'adminDeleteConversation',
        'adminStorageStats',
        'adminCleanupOrphanFiles',
    ];

    /** 基线：拆分前的 8 个 private 方法（ReflectionClass::getMethods 口径补充） */
    private const BASELINE_PRIVATE_METHODS = [
        'circleCovers',
        'formatMessageById',
        'formatMessage',
        'previewContent',
        'logDeletion',
        'cleanupUnusedFile',
        'dirSize',
        'findOrphanFiles',
    ];

    /** @return list<string> */
    private static function allMethods(): array
    {
        $ref = new \ReflectionClass(\Hublog\Core\ChatService::class);
        $names = [];
        foreach ($ref->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === \Hublog\Core\ChatService::class) {
                $names[] = $m->getName();
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    public function testBaselineMethodInventoryRecorded(): void
    {
        // T01 基线：公开方法用 get_class_methods 口径，私有方法用反射补齐。
        $public = get_class_methods(\Hublog\Core\ChatService::class) ?: [];
        foreach (self::BASELINE_PUBLIC_METHODS as $name) {
            $this->assertTrue(in_array($name, $public, true), '拆分丢失基线方法: ' . $name);
        }
        // T03 新增的公开方法
        foreach ([
            'messageSoftDeleteReady', 'conversationSoftDeleteReady', 'memberSoftDeleteReady',
            'requireMessageSoftDeleteReady', 'markMessagesDeleted', 'dissolveGroupAsOwner',
        ] as $name) {
            $this->assertTrue(in_array($name, $public, true), 'T03 新方法缺失: ' . $name);
        }

        $all = self::allMethods();
        foreach (array_merge(self::BASELINE_PUBLIC_METHODS, self::BASELINE_PRIVATE_METHODS) as $name) {
            $this->assertTrue(in_array($name, $all, true), '基线方法缺失: ' . $name);
        }
        $this->assertSame(count($all), count(array_unique($all)), '方法名不得重名');
        $this->assertSame(43, count($all), '37 个原方法 + T03 新增 6 个方法应全部挂在 ChatService 上');
    }

    public function testSevenTraitSplitKeepsMainClassThin(): void
    {
        $main = (string)file_get_contents(dirname(__DIR__) . '/core/ChatService.php');
        $this->assertTrue(count(explode("\n", $main)) < 80, 'ChatService 主类必须保持精简');
        foreach ([
            'ChatSchemaTrait', 'ChatFormatTrait', 'ChatCoreTrait', 'ChatSendTrait',
            'ChatDeletionTrait', 'ChatAdminTrait', 'ChatFileGcTrait',
        ] as $trait) {
            $this->assertStringContainsString('use ' . $trait . ';', $main);
            $path = dirname(__DIR__) . '/core/' . $trait . '.php';
            $this->assertFileExists($path);
            $src = (string)file_get_contents($path);
            $this->assertStringContainsString('trait ' . $trait, $src);
            $this->assertStringContainsString('use Hublog\\Core\\Database;', $src, $trait . ' 缺少完整导入块');
            // 与主类保持一致：trait 不声明 strict_types，避免改变调用时的参数强转行为
            $this->assertFalse(str_contains($src, 'declare(strict_types'));
        }
    }

    public function testChatServiceViewKeepsSendBeforeMarkAsRead(): void
    {
        $src = SourceView::chatService();
        $this->assertTrue(strlen($src) > 50000, 'ChatService 逻辑源码视图不应为空');
        $posSend = strpos($src, 'public static function sendMessage(');
        $posRead = strpos($src, 'public static function markAsRead(');
        $this->assertTrue($posSend !== false && $posRead !== false && $posSend < $posRead);
    }

    public function testMethodSourceExtractsSingleMethodBody(): void
    {
        $body = SourceView::methodSource('sendMessage');
        $this->assertTrue(str_contains($body, 'function sendMessage('));
        $this->assertTrue(str_contains($body, 'Database::beginTransaction();'));
        $this->assertTrue(str_contains($body, 'Database::commit();'));
        // 只截方法体，不能把下一个方法也吞进来
        $this->assertFalse(str_contains($body, 'public static function markAsRead('));
        $this->assertSame('', SourceView::methodSource('noSuchMethod'));
    }

    /** 取 migrate99() 方法体源码（它是 MigrationBatchC 的最后一个方法） */
    private static function migrate99Source(): string
    {
        $src = (string)file_get_contents(dirname(__DIR__) . '/core/MigrationBatchC.php');
        $start = strpos($src, 'private static function migrate99(');
        if ($start === false) {
            return '';
        }
        return substr($src, $start);
    }

    public function testV99MigrationVersionAndHardGuard(): void
    {
        $migration = (string)file_get_contents(dirname(__DIR__) . '/core/Migration.php');
        $this->assertStringContainsString('private static int $currentVersion = 103;', $migration);

        $m99 = self::migrate99Source();
        $this->assertTrue(strlen($m99) > 1000, 'migrate99() 必须存在');
        $this->assertStringContainsString('V99 自检失败', $m99);
        $this->assertStringContainsString("['conversation_members', 'deleted_at']", $m99);
        $this->assertStringContainsString('.soft_delete_incomplete', $m99);
        $this->assertStringContainsString('WHERE delete_scope = \'self\' AND deleted_by IS NULL', $m99);
    }

    public function testV99MigrationHasNoDestructiveSql(): void
    {
        $m99 = self::migrate99Source();
        foreach (['DELETE FROM', 'DROP TABLE', 'TRUNCATE', 'DROP COLUMN'] as $keyword) {
            $this->assertFalse(str_contains(strtoupper($m99), $keyword), 'migrate99 不得出现破坏性语句: ' . $keyword);
        }
    }

    public function testContractRegistersChatSoftDeleteSentinels(): void
    {
        $ref = new \ReflectionClass(\Hublog\Core\MigrationContract::class);
        $critical = $ref->getReflectionConstant('CRITICAL_COLUMNS')->getValue();
        foreach ([
            ['conversations', 'deleted_at'],
            ['conversation_members', 'deleted_at'],
            ['messages', 'deleted_at'],
            ['messages', 'deleted_by'],
            ['messages', 'delete_scope'],
        ] as $sentinel) {
            $this->assertTrue(in_array($sentinel, $critical, true), '契约缺少精确哨兵: ' . implode('.', $sentinel));
        }
    }

    public function testSoftDeleteCapabilitiesAndSingleWriteEntry(): void
    {
        $svc = SourceView::chatService();
        foreach ([
            'public const MSG_SCOPE_SELF',
            'public const MSG_SCOPE_ADMIN',
            'public const MSG_SCOPE_PURGE',
            'public const REQUIRED_MESSAGE_SOFT_COLUMNS',
            'public static function messageSoftDeleteReady(',
            'public static function conversationSoftDeleteReady(',
            'public static function memberSoftDeleteReady(',
            'public static function requireMessageSoftDeleteReady(',
            'public static function markMessagesDeleted(',
            'public static function dissolveGroupAsOwner(',
        ] as $needle) {
            $this->assertStringContainsString($needle, $svc, '缺少软删除能力: ' . $needle);
        }
        $this->assertStringContainsString('服务端未完成软删除升级，操作已拒绝', $svc);
        $this->assertStringContainsString(', 503)', $svc);

        $deleteBody = SourceView::methodSource('adminDeleteConversation');
        $this->assertTrue(str_contains($deleteBody, 'requireMessageSoftDeleteReady();'));
        $this->assertTrue(str_contains($deleteBody, 'memberSoftDeleteReady()'));
        $this->assertFalse(str_contains(strtoupper($deleteBody), 'DELETE FROM'));

        $markBody = SourceView::methodSource('markMessagesDeleted');
        $this->assertFalse(str_contains(strtoupper($markBody), 'DELETE FROM'));
        $this->assertTrue(str_contains($markBody, 'logDeletion('));
    }

    public function testNoPhysicalMessageDeleteOutsideCompliancePurge(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];
        foreach (['api/core', 'api/controllers'] as $dir) {
            foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $file) {
                $base = basename($file);
                if (str_starts_with($base, 'Migration')) {
                    continue;
                }
                $lines = file($file) ?: [];
                foreach ($lines as $i => $line) {
                    if (preg_match('/(DELETE\s+FROM\s+`?messages`?|Database::delete\(\s*[\'"]messages[\'"])/i', $line) !== 1) {
                        continue;
                    }
                    // 唯一放行：账号注销的物理擦除（被遗忘权），必须带显式注释标记
                    if ($base === 'AppRepository.php') {
                        $context = implode('', array_slice($lines, max(0, $i - 4), 9));
                        if (str_contains($context, '合规擦除例外')) {
                            continue;
                        }
                    }
                    $violations[] = $dir . '/' . $base . ':' . ($i + 1);
                }
            }
        }
        $this->assertSame([], $violations, '发现未放行的消息物理删: ' . implode(', ', $violations));

        $appRepo = (string)file_get_contents($root . '/api/core/AppRepository.php');
        $this->assertStringContainsString('合规擦除例外（被遗忘权），非 IM 删除路径', $appRepo);
    }

    public function testDissolveGroupAndHardDeleteNeverPhysicallyDelete(): void
    {
        $repo = (string)file_get_contents(dirname(__DIR__) . '/core/ChatRepository.php');
        $this->assertStringContainsString('public static function dissolveMembersAndConversation(', $repo);
        $this->assertFalse(str_contains($repo, 'function dissolveGroup('));
        $this->assertFalse(str_contains(strtoupper($repo), 'DELETE FROM MESSAGES'));
        $this->assertFalse(str_contains($repo, "Database::delete('messages'"));

        $ctrl = (string)file_get_contents(dirname(__DIR__) . '/controllers/ChatController.php');
        $this->assertStringContainsString('ChatService::dissolveGroupAsOwner(', $ctrl);
        $this->assertStringContainsString("暂不支持彻底删除会话', 501)", $ctrl);
        $this->assertFalse(str_contains($ctrl, 'ChatRepository::deleteMember('));
    }
}
