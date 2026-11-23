<?php
/**
 * Hublog 统一响应类
 */

namespace Hublog\Core;

class Response
{
    private int $code;
    private string $message;
    private mixed $data;

    public function __construct(int $code = 200, string $message = 'success', mixed $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public static function success(mixed $data = null, string $message = 'success'): self
    {
        return new self(200, $message, $data);
    }

    /**
     * 构造错误响应。
     *
     * PHPStan 提级后揪出：12 处调用点传了第 3 个参数（错误详情，如 ['field' => 'username']），
     * 而旧签名只有 2 个 —— PHP 对用户态函数的多余实参是**静默丢弃**的，
     * 于是这些错误详情从来没有真正下发给前端（例如「用户名已被注册」丢了 field 标记，
     * 前端无法把红字定位到具体输入框）。
     *
     * 这里补上可选的第 3 参：既有 2 参调用行为完全不变，12 处调用点的意图得以生效。
     */
    public static function error(string $message = 'error', int $code = 400, mixed $data = null): self
    {
        return new self($code, $message, $data);
    }

    public function toJson(): string
    {
        return json_encode([
            'code' => $this->code,
            'message' => $this->message,
            'data' => self::normalizePayload($this->data, true)
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 【统一出口】把媒体路径转绝对 URL + avatar 为空时补默认头像。
     *
     * 为什么需要这层兜底：Post/Follow/Circle/Favorite/Chat/Notification/Search/User 等
     * 15 个控制器从来没有调用过 MediaHelper，它们吐出的媒体字段是裸值或站点根相对路径。
     * - H5 下浏览器会以站点根解析，能碰巧跑通；
     * - App 端没有同域概念，'/xxx' 会被当成应用沙箱内的本地文件路径 → 必然加载失败。
     *
     * 在这里挂一层，一处覆盖所有接口，无需每个控制器手写、也无需改前端 / 迁移数据库。
     * 幂等：已是 http(s):// 、// 、data: 的值原样返回，不会二次加工。
     *
     * ── 性能与正确性 ──
     * 旧实现是「两次全树遍历 + 每个字符串 2 次正则 + 每个字符串 json_decode 试探」。
     * 一条 20 条动态的时间线响应里有几百个字符串，其中绝大多数是正文、昵称、时间戳，
     * 对它们做 json_decode 纯属浪费（正文动辄上百字）。
     *
     * 更糟的是它会「误伤」：用户正文只要恰好是合法 JSON 文本（比如动态里贴了段 JSON），
     * 就会被解包成数组改写内容 —— 这是真实的内容损坏 bug。
     *
     * 现在改为按「字段名」判断，而不是对每个字符串盲试：
     *   - 只有 MEDIA_JSON_FIELDS 白名单里的字段才可能是「JSON 存的媒体数组」，
     *     只有它们才尝试 json_decode；
     *   - 媒体路径前缀判定用 str_starts_with，不用正则；
     *   - 其余字符串（正文、昵称、时间戳、标签…）几乎零开销，直接返回。
     *
     * 白名单按「字段名」而非「值内容」判断，所以正文里贴 JSON 不会被解包。
     *
     * @param bool $fillAvatar 是否补默认头像。
     *        JSON 字符串解码出来的子结构传 false —— 与旧实现语义一致：
     *        旧实现先跑 fillDefaultAvatar（只认数组，不解码 JSON 字符串），
     *        再跑 normalizeMediaPaths（会解码 JSON 字符串），所以 JSON 内部的 avatar 本来就不补。
     */
    private static function normalizePayload(mixed $value, bool $fillAvatar, string $key = ''): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if ($fillAvatar && $k === 'avatar' && ($v === null || (is_string($v) && trim($v) === ''))) {
                    // 走 MediaHelper::absoluteUrl('assets/images/default-avatar.svg') 而非硬编码，
                    // 这样会命中它在 MediaHelper 里的历史数据兜底分支 → 自动补上 templates/{模板}/ 前缀。
                    $value[$k] = MediaHelper::absoluteUrl('assets/images/default-avatar.svg');
                    continue;
                }
                $value[$k] = self::normalizePayload($v, $fillAvatar, (string)$k);
            }
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        // ── 热路径内联判定 ──
        // 这里刻意不拆成独立方法：PHP 用户态函数调用本身有开销，
        // 而每条列表响应有几百个字符串要走这段，逐个多一次函数调用就抵消掉优化收益。
        // 所以用一次 switch 按首字符分派，把绝大多数字符串一次判完。

        $first = $value[0] ?? '';

        // ① 已是完整 URL / data URI / 协议相对地址 → 原样返回
        if ($first === 'h' || $first === 'H') {
            if (strncasecmp($value, 'http://', 7) === 0 || strncasecmp($value, 'https://', 8) === 0) {
                return $value;
            }
        } elseif ($first === 'd' || $first === 'D') {
            if (strncasecmp($value, 'data:', 5) === 0) {
                return $value;
            }
        } elseif ($first === '/') {
            if (str_starts_with($value, '//')) {
                return $value; // 协议相对地址
            }
        }

        // ② 只有白名单字段才可能是「JSON 存的媒体数组」（images / videos / attachments）。
        //    非白名单字符串（正文、昵称、时间戳…）在这里直接返回：
        //    既省掉了 json_decode 试探，也修掉了「正文恰好是合法 JSON 会被解包改写」的 bug。
        if (isset(self::MEDIA_JSON_FIELDS[$key])) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::normalizePayload($decoded, false, $key);
            }
        }

        // ③ 站点根相对路径（/templates/...、/uploads/...）与裸相对路径（assets/...）
        //    用首字符再筛一次：只有 u / a / t 开头（或 / 开头）才可能是媒体路径。
        if ($first === '/' || $first === 'u' || $first === 'a' || $first === 't') {
            if (
                str_starts_with($value, '/uploads/')
                || str_starts_with($value, 'uploads/')
                || str_starts_with($value, '/assets/')
                || str_starts_with($value, 'assets/')
                || str_starts_with($value, '/templates/')
                || str_starts_with($value, 'templates/')
            ) {
                return MediaHelper::absoluteUrl($value);
            }
        }

        return $value;
    }

    /**
     * 存 JSON 媒体数组的字段名白名单。
     *
     * 对应 posts 表的 images / videos / attachments 三个 json 列，
     * 以及从它们派生的同名字段（列表页 / 详情页 / 收藏 / 圈子接口字段名一致）。
     */
    private const MEDIA_JSON_FIELDS = [
        'images'      => true,
        'videos'      => true,
        'attachments' => true,
    ];

    public function getCode(): int
    {
        return $this->code;
    }
}
