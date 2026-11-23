<?php
/**
 * Hublog 即时通讯服务层
 * 处理会话管理、消息收发、已读追踪、未读计数
 *
 * P1-13 修复：
 * - 创建/获取私聊会话前双向校验 blocked_users：发起方拉黑对方 / 对方拉黑发起方都不允许开启会话
 * - 新建会话按用户维度频控（默认 20 次/小时），防止批量建会话骚扰
 * - 发送消息前再次校验双向拉黑 + 按会话维度频控（默认 60 条/分，文本 2000 字符限制保持原有，避免 50MB 存储放大）
 *
 * V99 结构拆分：全部 43 个方法逐字搬迁到 7 个 Chat*Trait.php；
 * 本文件只保留共享常量与 trait 组合，逻辑源码视图见 api/tests/SourceView.php::chatService()。
 * 注意：不要给 trait 文件加 declare(strict_types=1)，否则会改变原有调用的参数强转行为。
 */

namespace Hublog\Core;

class ChatService
{
    // 类型化类常量（private const int X）为 PHP 8.3 语法，为兼容 8.2 及以下统一省略类型
    private const MAX_NEW_CONVERSATIONS_PER_HOUR = 20;   // 单用户每小时新建私聊会话上限（防批量骚扰）
    private const MAX_MESSAGES_PER_CONV_PER_MIN = 60;    // 单会话每分钟消息上限（防刷屏）
    private const UNREAD_CACHE_TTL = 10;                 // P1-2：未读数缓存 TTL 秒（轮询接口主要 DB 压力源，10s 延迟对 badge 无感）

    // V99：消息删除语义常量。任何删除路径都只能通过 markMessagesDeleted 落库，
    // 老库缺列时 fail-closed（503），不再回退物理删除。
    public const MSG_SCOPE_SELF = 'self';    // 仅删除者自己不可见，对方仍能看到
    public const MSG_SCOPE_ADMIN = 'admin';  // 后台下架：所有人不可见，后台可查
    public const MSG_SCOPE_PURGE = 'purge';  // 过期清理：所有人不可见，行与快照保留
    public const REQUIRED_MESSAGE_SOFT_COLUMNS = ['deleted_at', 'deleted_by', 'delete_scope'];
    public const REQUIRED_CONVERSATION_SOFT_COLUMNS = ['deleted_at'];
    public const REQUIRED_MEMBER_SOFT_COLUMNS = ['deleted_at'];

    // 每个 trait 自带完整的 Hublog\Core 导入块，编译期展平进本类，零行为差异
    use ChatSchemaTrait;
    use ChatFormatTrait;
    use ChatCoreTrait;
    use ChatSendTrait;
    use ChatDeletionTrait;
    use ChatAdminTrait;
    use ChatFileGcTrait;
}
