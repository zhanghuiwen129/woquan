<?php
namespace Hublog\Controllers;

use Hublog\Core\AiService;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Response;
use Hublog\Core\Request;

/**
 * AI 辅助接口（发布时润色 / 扩写 / 续写 / 精简 / 换风格）
 *
 * 复用 AiService + settings 表 ai_comment_* 配置（与 AI 自动评论同源），
 * 前端不传密钥，仅需登录态。结果回填到发布页正文，由前端勾选「含AI生成内容」声明。
 */
class AiController
{
    /**
     * 动作 → 系统提示词
     */
    private const ACTIONS = [
        'polish'       => '你是一位文字润色助手。请优化下面用户提供的内容：修正错别字与语病、让表达更通顺自然，保持原意与原风格，不要额外发挥。只返回润色后的正文，不要任何解释或前缀。',
        'expand'       => '你是一位写作助手。请把下面用户提供的内容扩写得更丰满：补充细节、情感与画面感，使内容更完整生动。只返回扩写后的正文，不要任何解释或前缀。',
        'continue'     => '你是一位写作助手。请顺着下面用户提供的内容继续写下去，保持语气连贯，自然地延展一两段。只返回续写的部分，不要重复原文，不要任何解释或前缀。',
        'shorten'      => '你是一位文字精简助手。请把下面用户提供的内容压缩提炼为要点，保留核心信息与原有语气，去除冗余。只返回精简后的正文，不要任何解释或前缀。',
        'style_formal'   => '你是一位文风转换助手。请把下面用户提供的内容改写为更正式、得体的口吻，适合商务或公开场合。只返回改写后的正文，不要任何解释或前缀。',
        'style_literary' => '你是一位文风转换助手。请把下面用户提供的内容改写为更有文艺气息、富有画面感与诗意的口吻。只返回改写后的正文，不要任何解释或前缀。',
        'style_humorous' => '你是一位文风转换助手。请把下面用户提供的内容改写为更轻松幽默的口吻，适度俏皮但不浮夸。只返回改写后的正文，不要任何解释或前缀。',
    ];

    /**
     * POST /api/ai/assist
     * 入参：{ text: string, action: string }
     * 出参：{ result: string, action: string }
     */
    public function assist(Request $request): Response
    {
        $user = Auth::user($request);
        if (!$user) {
            return Response::error('请先登录后再使用 AI 辅助');
        }

        $uid = (int)($user['id'] ?? $user['uid'] ?? 0);
        // 中间件可能已经原子预扣一笔；控制器任何「未成功生成」的返回都必须释放，
        // 否则参数错误/限流/生成失败也会消耗用户额度。
        $reservationId = (int)$request->getAttribute('ai_quota_reservation_id', 0);

        $text = trim((string)$request->getString('text'));
        $action = trim((string)$request->getString('action'));

        if ($text === '') {
            self::releaseFailedAttempt($reservationId);
            return Response::error('请先输入要处理的内容');
        }
        if (mb_strlen($text) > 5000) {
            self::releaseFailedAttempt($reservationId);
            return Response::error('内容过长，请控制在 5000 字以内');
        }
        if (!isset(self::ACTIONS[$action])) {
            self::releaseFailedAttempt($reservationId);
            return Response::error('不支持的 AI 动作');
        }

        $cfg = AiService::getConfig();
        $apiUrl = $cfg['ai_comment_api_url'] ?? '';
        $apiKey = $cfg['ai_comment_api_key'] ?? '';
        $model  = $cfg['ai_comment_model'] ?? 'deepseek-chat';

        if ($apiUrl === '') {
            self::releaseFailedAttempt($reservationId);
            return Response::error('站点未配置 AI 服务，请联系管理员开启');
        }

        // 频率限制：同一用户 8 秒内最多调用 1 次，防滥用刷爆额度。
        // 移到参数校验之后：无效请求不该占用 8 秒窗口，也不该消耗预扣配额。
        $rlKey = 'ai_assist_rl_' . $uid;
        if (Cache::get($rlKey)) {
            self::releaseFailedAttempt($reservationId);
            return Response::error('操作太快了，请稍候再试');
        }
        // 先占位：并发/失败路径统一由 releaseFailedAttempt 撤销，避免「只在成功后 set」
        // 造成同一瞬间两个请求都通过限流检查。
        Cache::set($rlKey, time(), 8);

        // 扩写 / 续写允许更长输出
        $longActions = ['expand', 'continue'];
        $maxTokens = in_array($action, $longActions, true) ? 800 : 400;

        // P1-2：AI 润色是「用户当面等结果」的业务必需同步操作，不能改成异步，
        // 但也不能让大量并发润色把 FPM worker 池占满（每个请求最长占用 timeout 秒）。
        // 闸门用 Cache::withLock + 计数键实现，进程崩溃时靠 TTL 自动释放。
        $slot = self::acquireSlot();
        if ($slot === null) {
            self::releaseFailedAttempt($reservationId, $rlKey);
            return Response::error('AI 服务繁忙，请稍后重试', 503);
        }

        try {
            $reply = AiService::chatCompletion(
                self::ACTIONS[$action],
                $text,
                [
                    'api_url'     => $apiUrl,
                    'api_key'     => $apiKey,
                    'model'       => $model,
                    'timeout'     => 25,
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.7,
                ]
            );
        } catch (\Throwable $e) {
            self::releaseFailedAttempt($reservationId, $rlKey);
            throw $e;
        } finally {
            self::releaseSlot($slot);
        }

        if ($reply === null || $reply === '') {
            self::releaseFailedAttempt($reservationId, $rlKey);
            return Response::error('AI 生成失败，请稍后重试');
        }

        // V100：成功才把原子预扣行确认为正式配额记录（失败路径已 release）。
        \Hublog\Core\License::confirmQuotaReservation($reservationId, $action);
        return Response::success(['result' => $reply, 'action' => $action], 'ok');
    }

    /**
     * 失败 / 未命中路径的统一回滚：释放预扣额度与 8 秒限流占位。
     * $rlKey 为空时只释放预扣（用于参数校验阶段，限流尚未占位）。
     */
    private static function releaseFailedAttempt(int $reservationId, string $rlKey = ''): void
    {
        if ($reservationId > 0) {
            \Hublog\Core\License::releaseQuotaReservation($reservationId);
        }
        if ($rlKey !== '') {
            Cache::delete($rlKey);
        }
    }

    /** 全站并发润色上限：超过即快速失败，保护 FPM worker 池 */
    private const MAX_CONCURRENT = 4;

    /** 并发计数的存活窗口（秒）：略大于最长单次耗时，保证进程崩溃后计数能自动回落 */
    private const SLOT_TTL = 40;

    /**
     * 申请一个并发槽位。
     *
     * @return string|null 槽位标识；null 表示已达上限
     */
    private static function acquireSlot(): ?string
    {
        $counterKey = 'ai_assist_concurrency';
        $slotId = bin2hex(random_bytes(8));

        $ok = Cache::withLock($counterKey, function () use ($counterKey, $slotId): bool {
            $current = Cache::get($counterKey);
            $slots = is_array($current) ? $current : [];
            // 清掉已过期的槽位（进程崩溃 / 超时未释放）
            $now = time();
            $alive = [];
            foreach ($slots as $id => $expire) {
                if ((int)$expire > $now) {
                    $alive[$id] = $expire;
                }
            }
            if (count($alive) >= self::MAX_CONCURRENT) {
                Cache::set($counterKey, $alive, self::SLOT_TTL);
                return false;
            }
            $alive[$slotId] = $now + self::SLOT_TTL;
            Cache::set($counterKey, $alive, self::SLOT_TTL);
            return true;
        });

        return $ok === true ? $slotId : null;
    }

    /** 释放并发槽位 */
    private static function releaseSlot(string $slotId): void
    {
        $counterKey = 'ai_assist_concurrency';
        Cache::withLock($counterKey, function () use ($counterKey, $slotId): void {
            $current = Cache::get($counterKey);
            $slots = is_array($current) ? $current : [];
            unset($slots[$slotId]);
            Cache::set($counterKey, $slots, self::SLOT_TTL);
        });
    }
}
