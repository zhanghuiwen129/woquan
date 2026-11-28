<?php

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\CronAuth;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\Security;
use Hublog\Core\Upload;
use Hublog\Core\VideoCompress;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;
use Hublog\Core\Hook;
use Hublog\Core\PostRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;
use Hublog\Core\ModerationRepository;

trait PostModerationTrait
{
    /**
     * V83：判断当前用户能否把本帖评论标记为「神评」
     * 权限：帖子作者 / 圈子圈主·管理员（仅当帖子属于该圈子）/ 平台管理员
     * 评论作者本人不可标这一层在 formatComment 的 can_mark 里再挡一次。
     *
     * @param array $post 动态行（需含 user_id / circle_id）
     * @param array|null $currentUser Auth::user() 结果
     */

    private function canMarkGodComment(array $post, ?array $currentUser): bool
    {
        if (empty($currentUser) || empty($currentUser['id'])) {
            return false;
        }
        $uid = (int)$currentUser['id'];
        // 平台管理员
        if (($currentUser['role'] ?? '') === 'admin') {
            return true;
        }
        // 帖子作者本人
        if ((int)($post['user_id'] ?? 0) === $uid) {
            return true;
        }
        // 圈子圈主 / 管理员
        $circleId = (int)($post['circle_id'] ?? 0);
        if ($circleId > 0) {
            $row = ModerationRepository::findCircleMemberRole($circleId, $uid);
            if ($row) {
                if (in_array((string)($row['role'] ?? ''), ['owner', 'admin'], true)) {
                    return true;
                }
                if ((int)($row['owner_id'] ?? 0) === $uid) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * V83：把一条评论标记为「神评」（写入 comment_marks，幂等）
     * 仅允许 canMarkGodComment 判定通过的人操作；评论作者本人不可标自己。
     */

    public function markGodComment(Request $request): Response
    {
        $user = Auth::check($request);
        $commentId = $request->getInt('comment_id');
        if (!$commentId) {
            return Response::error('参数错误', 400);
        }

        $comment = ModerationRepository::findCommentForMark($commentId);
        if (!$comment) {
            return Response::error('评论不存在', 404);
        }
        if ((int)($comment['status'] ?? 1) !== 1) {
            return Response::error('该评论已删除，无法标记', 403);
        }
        if ((int)$comment['is_ai'] === 1) {
            return Response::error('AI 评论不可标记为神评', 403);
        }

        $post = [
            'user_id'  => (int)$comment['post_user_id'],
            'circle_id' => (int)$comment['circle_id'],
        ];
        if (!$this->canMarkGodComment($post, $user)) {
            return Response::error('无标记权限', 403);
        }
        // 评论作者本人不可标自己
        if ((int)$comment['user_id'] === (int)$user['id']) {
            return Response::error('不能把自己的评论标记为神评', 403);
        }

        $now = Helper::now();
        $inserted = ModerationRepository::insertGodCommentMark($commentId, (int)$user['id'], $now);

        if ($inserted) {
            // 通知评论作者
            try {
                ModerationRepository::insertGodCommentNotification([
                    'user_id'    => (int)$comment['user_id'],
                    'type'       => 'god_comment',
                    // P0-A：notifications 表无 title 列，越界写入会抛 1054 被下方 catch 空吞，
                    // 导致神评通知永久发不出。标题语义并入 content，不再写越界列。
                    'content'    => '你的评论被标记为神评：持续产出优质评论可解锁神评系列徽章',
                    'post_id'    => (int)$comment['post_id'],
                    'comment_id' => $commentId,
                    'sender_id'  => (int)$user['id'],
                    'is_read'    => 0,
                    'created_at' => $now
                ]);
            } catch (\Throwable $_) {
            }
            // 神评系列徽章（被标神评次数）是 count 类，标记后立即重算让用户即时看到解锁
            BadgeService::evaluateUser((int)$comment['user_id']);
        }

        Cache::delete("post_{$comment['post_id']}");
        Cache::delete("post_comments_{$comment['post_id']}");
        Cache::delete("post_comments_raw_{$comment['post_id']}");

        return Response::success([
            'comment_id' => $commentId,
            'is_god'     => true,
            'marked'     => $inserted
        ], $inserted ? '已标记为神评' : '该评论已是神评');
    }

    /**
     * V83：取消「神评」标记（拥有标记权限的人都可取消）
     */

    public function unmarkGodComment(Request $request): Response
    {
        $user = Auth::check($request);
        $commentId = $request->getInt('comment_id');
        if (!$commentId) {
            return Response::error('参数错误', 400);
        }

        $comment = ModerationRepository::findCommentForMark($commentId);
        if (!$comment) {
            return Response::error('评论不存在', 404);
        }

        $post = [
            'user_id'  => (int)$comment['post_user_id'],
            'circle_id' => (int)$comment['circle_id'],
        ];
        if (!$this->canMarkGodComment($post, $user)) {
            return Response::error('无取消权限', 403);
        }

        $deleted = ModerationRepository::deleteGodCommentMark($commentId);

        if ($deleted) {
            // 取消标记后重算：被标次数回落到阈值以下时，神评徽章自动冻结
            BadgeService::evaluateUser((int)$comment['user_id']);
        }

        Cache::delete("post_{$comment['post_id']}");
        Cache::delete("post_comments_{$comment['post_id']}");
        Cache::delete("post_comments_raw_{$comment['post_id']}");

        return Response::success([
            'comment_id' => $commentId,
            'is_god'     => false,
            'marked'     => false
        ], $deleted ? '已取消神评标记' : '该评论本就不是神评');
    }

    /**
     * 发布定时动态（定时任务调用）
     */

    public static function publishScheduledPosts(): int
    {
        $now = Helper::now();
        // V169：audit_state=1 的动态是「因审核待审」，绝不能被定时任务自动放出去，
        // 否则等于绕过审核 —— 用户把定时改成待审，到点照样公开。
        // 该守卫已随 COUNT / UPDATE 两条 SQL 一并下沉仓储（audit_state = 0）。
        $count = ModerationRepository::countDueScheduledPosts($now);
        if ($count > 0) {
            ModerationRepository::publishDueScheduledPosts($now);
            // P0-11：定时发布改用版本号升版（timeline 命名空间同时覆盖
            // timeline_v4_* 列表与 timeline_total_* 计数缓存）
            Cache::bumpNsVersion('timeline');
            Cache::deleteByPrefix('posts_');
        }
        return (int)$count;
    }

    /**
     * 惰性触发：把到点的定时动态转成正式发布
     *
     * 【为什么必须有这个】publishScheduledPosts() 之前只有方法定义、全项目没有任何调用点，
     * 用户排好队的定时动态会永远停在 status=0（待发布），到点了也不会出现在时间线里，
     * 等于「定时发布」这个功能是假的。这里沿用 lazyCleanupExpiredUploads() 的惰性思路，
     * 由 api/index.php 每次请求调用；真实查库用 60 秒缓存锁节流，
     * 避免每个请求都多一次 COUNT 查询。
     */

    public static function lazyPublishScheduled(): void
    {
        try {
            if (Cache::get('scheduled_publish_lock')) {
                return;
            }
            Cache::set('scheduled_publish_lock', 1, 60);

            $count = self::publishScheduledPosts();
            if ($count > 0) {
                error_log('[Hublog lazyPublishScheduled] 已发布到期定时动态 ' . $count . ' 条'); // @allow-error-log
            }
        } catch (\Throwable $e) {
            // 惰性任务失败不能影响主流程
            error_log('[Hublog lazyPublishScheduled] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * V65：给外部定时器用的入口（POST /api/cron/scheduled {secret}）
     *
     * 原来的定时发布是「每次有人访问就顺手检查」，夜间低峰没有人访问 → 定时任务延迟到白天
     * 一次性刷出来。站主在后台系统设置里填一个 Token 后，挂 5 分钟一次的 cron 打这里就准点了。
     *
     * 鉴权：由 CronAuth::verify() 统一校验，比对方式用 hash_equals（防时序猜测）；
     * 没配过 token 一律 403 —— 宁可"关着"也不能让人白嫖了一个能改数据的公开接口。
     */

    public function cronPublishScheduled(Request $request): Response
    {
        // 支持两种传法：body {secret} 或 请求头 X-Cron-Token（curl -H 更顺手）
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }

        if (!CronAuth::verify($secret)) {
            return Response::error('cron token 无效或未配置', 403);
        }

        try {
            $count = self::publishScheduledPosts();
            error_log("[Hublog cronPublishScheduled] published={$count}"); // @allow-error-log
            return Response::success(['published' => $count, 'server_time' => time()]);
        } catch (\Throwable $e) {
            error_log('[Hublog cronPublishScheduled] ' . $e->getMessage()); // @allow-error-log
            return Response::error('调度执行失败', 500);
        }
    }

    /**
     * GET /api/posts/scheduled —— 我的待发布（定时）动态列表
     *
     * 只返回本人 status=0 且 scheduled_at 非空的动态，按发布时间正序（最近要发的排前面）。
     *
     * 未授权（License 的 scheduled 功能关闭）时返回空列表而不是 402：
     * App 端本来就靠 /api/app/config 的 allow_scheduled_post 隐藏入口，
     * 这里再兜一层，避免直接打接口时甩一个用户看不懂的错误码。
     */

    public function scheduledList(Request $request): Response
    {
        $user = Auth::check($request);
        if (!$user) {
            return Response::error('请先登录', 401);
        }

        if (!\Hublog\Core\License::canUseModule('scheduled')) {
            return Response::success([
                'list'       => [],
                'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0],
                'allowed'    => false,
                'server_time'=> time(),
            ]);
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);

        try {
            $total = ModerationRepository::countMyScheduledPosts((int)$user['id']);
            $rows = ModerationRepository::fetchMyScheduledPosts((int)$user['id'], $offset, $limit) ?: [];
        } catch (\Throwable $e) {
            error_log('[Hublog scheduledList] ' . $e->getMessage()); // @allow-error-log
            return Response::error('获取定时动态失败', 500);
        }

        $list = [];
        foreach ($rows as $row) {
            $images = $row['images'] ? json_decode($row['images'], true) : [];
            $videos = $row['videos'] ? json_decode($row['videos'], true) : [];
            $list[] = [
                'id'           => (int)$row['id'],
                'title'        => (string)($row['title'] ?? ''),
                'content'      => (string)$row['content'],
                'images'       => is_array($images) ? $images : [],
                'videos'       => is_array($videos) ? $videos : [],
                'location'     => (string)($row['location'] ?? ''),
                'circle_id'    => $row['circle_id'] ? (int)$row['circle_id'] : null,
                'is_anonymous' => (int)($row['is_anonymous'] ?? 0) === 1,
                'status'       => 0,
                'scheduled_at' => $row['scheduled_at'],
                'created_at'   => $row['created_at'],
            ];
        }

        $result = Helper::paginatedResponse($list, $page, $limit, $total);
        $result['allowed']     = true;
        $result['server_time'] = time();
        return Response::success($result);
    }
}
