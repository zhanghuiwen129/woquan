<?php
/**
 * 前台路由解析（架构评审 P1-5）
 *
 * 从 index.php 原 213-364 行逐行搬移：正则、顺序、兜底全部保持不变。
 * 类内不写 exit()/header()：原「动态不存在」404 以 earlyExit 描述符返回，
 * 由入口按原样执行，保证状态码与响应体逐字节不变。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class FrontRouter
{
    /**
     * @param string $base    站点部署前缀（根目录为空串）
     * @param string $path    当前请求路径
     * @param string $themeDir 当前主题模板目录（含末尾斜杠）
     */
    public function __construct(
        private string $base,
        private string $path,
        private string $themeDir
    ) {
    }

    /**
     * 解析当前请求对应的模板与路由派生数据。
     *
     * 未命中的可选值以 null 初始化，模拟原入口中「变量未定义」的语义；
     * 下游保留 ?? '' / !empty() 兜底，行为与拆分前一致。
     *
     * @return array{
     *     templatePath: string,
     *     postDetail: ?array,
     *     categoryDetail: ?array,
     *     paywallHtml: ?string,
     *     searchQuery: ?string,
     *     isUnknownPath: ?bool,
     *     ssrVisWhere: string,
     *     ssrVisParams: array,
     *     earlyExit: ?array{status: int, body: string}
     * }
     */
    public function resolve(): array
    {
        // 动态详情页 /p/{id}
        $postDetail = null;
        $categoryDetail = null;
        $paywallHtml = null;
        $searchQuery = null;
        $isUnknownPath = null;
        $earlyExit = null;
        $templatePath = $this->themeDir . 'index.html';
        // SSR 固定游客视角：首屏/Meta/JSON-LD 全部按游客口径渲染，不引入登录态。
        // 片段在主查询与下面的首屏辅助查询中共用。
        [$ssrVisWhere, $ssrVisParams] = VisibilityPolicy::where(0);
        if (preg_match('#^' . preg_quote($this->base, '#') . '/p/(\d+)/?$#', $this->path, $matches)) {
            $templatePath = $this->themeDir . 'post.html';
            try {
                $stmt = Database::getInstance()->prepare(
                    "SELECT p.id, p.title, p.content, p.images, p.created_at, p.updated_at, p.user_id, p.post_type,"
                    . " p.visibility, p.circle_id,"
                    . " u.nickname, u.username, u.avatar"
                    . " FROM posts p LEFT JOIN users u ON u.id = p.user_id"
                    . " WHERE p.id = ? AND p.status = 1 AND {$ssrVisWhere} LIMIT 1"
                );
                $stmt->execute(array_merge([(int)$matches[1]], $ssrVisParams));
                $postDetail = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                $postDetail = null;
            }
            // 二次内存判定：SQL 片段与权威策略同口径，防止未来 SELECT/WHERE 漂移造成绕过。
            if ($postDetail && !VisibilityPolicy::canView($postDetail, 0)) {
                $postDetail = null;
            }
            if (!$postDetail) {
                $earlyExit = ['status' => 404, 'body' => '动态不存在'];
                return [
                    'templatePath' => $templatePath,
                    'postDetail' => $postDetail,
                    'categoryDetail' => $categoryDetail,
                    'paywallHtml' => $paywallHtml,
                    'searchQuery' => $searchQuery,
                    'isUnknownPath' => $isUnknownPath,
                    'ssrVisWhere' => $ssrVisWhere,
                    'ssrVisParams' => $ssrVisParams,
                    'earlyExit' => $earlyExit,
                ];
            }
            // V97：内容付费墙 —— 首屏是游客视角，付费内容只输出试读。
            // 在源头截断 $postDetail['content']，下游 seo_paragraphs / og / JSON-LD / POST_CONTENT_TEXT
            // 全部派生自它，一处裁剪全覆盖，杜绝付费全文从 SEO 元信息泄露。
            $paywallInfo = ['locked' => false];
            $paywallHtml = '';
            try {
                $paywallInfo = ContentPayService::paywallForSeo($postDetail);
                if (!empty($paywallInfo['locked'])) {
                    $postDetail['content'] = ContentPayService::preview(
                        (string)$postDetail['content'],
                        (int)($paywallInfo['preview_ratio'] ?? 30)
                    );
                    $postDetail['images'] = ''; // 首屏只输出试读，不暴露媒体原 URL
                    $pLabel = ($paywallInfo['product_type'] ?? 'post') === 'column' ? '专栏' : '单篇';
                    $pPrice = number_format((float)($paywallInfo['price'] ?? 0), 2);
                    $postPathBase = $this->base === '' ? '' : $this->base;
                    $pUrl = $postPathBase . '/p/' . (int)$postDetail['id'];
                    $paywallHtml = '<div class="paywall-card" data-paywall="1"'
                        . ' data-product-type="' . htmlspecialchars((string)($paywallInfo['product_type'] ?? 'post'), ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-target-id="' . (int)($paywallInfo['target_id'] ?? 0) . '"'
                        . ' data-price="' . htmlspecialchars($pPrice, ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-post-id="' . (int)$postDetail['id'] . '"'
                        . ' data-login-url="' . htmlspecialchars($postPathBase . '/login', ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-post-url="' . htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') . '">'
                        . '<p class="paywall-title">剩余内容需付费解锁</p>'
                        . '<p class="paywall-price">¥' . htmlspecialchars($pPrice, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<button type="button" class="paywall-unlock" data-action="content-unlock">解锁' . $pLabel . '全文</button>'
                        . '</div>';
                }
            } catch (\Throwable $e) {
                // 付费判定失败一律按「未锁」降级，绝不因付费链路让详情页 500
                $paywallInfo = ['locked' => false];
                $paywallHtml = '';
            }
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/u/([a-zA-Z0-9_-]+)/followers/?$#', $this->path, $matches)) {
            // 用户粉丝列表页 /u/{username}/followers
            $templatePath = $this->themeDir . 'followers.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/u/([a-zA-Z0-9_-]+)/following/?$#', $this->path, $matches)) {
            // 用户关注列表页 /u/{username}/following
            $templatePath = $this->themeDir . 'following.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/u/([a-zA-Z0-9_-]+)/?$#', $this->path, $matches)) {
            // 用户主页 /u/{username}
            $templatePath = $this->themeDir . 'user.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/followers/?$#', $this->path)) {
            // 我的粉丝列表页 /followers
            $templatePath = $this->themeDir . 'followers.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/following/?$#', $this->path)) {
            // 我的关注列表页 /following
            $templatePath = $this->themeDir . 'following.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/pinned/?$#', $this->path)) {
            // 置顶动态列表页 /pinned
            $templatePath = $this->themeDir . 'pinned.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/favorites/?$#', $this->path)) {
            // 我的收藏列表页 /favorites
            $templatePath = $this->themeDir . 'favorites.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/go/?$#', $this->path)) {
            // 外站跳转提示页 /go
            $templatePath = $this->themeDir . 'go.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/c/(\d+)/?$#', $this->path, $matches)) {
            // 分类聚合页 /c/{id}
            $templatePath = $this->themeDir . 'category.html';
            try {
                $stmt = Database::getInstance()->prepare(
                    "SELECT id, name FROM categories WHERE id = ? LIMIT 1"
                );
                $stmt->execute([(int)$matches[1]]);
                $categoryDetail = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                $categoryDetail = null;
            }
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/topic/(.+?)/?$#', $this->path, $matches)) {
            // 话题聚合页 /topic/{name}
            $templatePath = $this->themeDir . 'topic.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/circles/?$#', $this->path)) {
            // V49：圈子列表页 /circles
            $templatePath = $this->themeDir . 'circles.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/circle/(\d+)/?$#', $this->path, $matches)) {
            // V49：圈子详情页 /circle/{id}
            $templatePath = $this->themeDir . 'circle.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/search/?$#', $this->path)) {
            // 搜索页 /search
            $templatePath = $this->themeDir . 'search.html';
            $searchQuery = $_GET['q'] ?? '';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/chat/?$#', $this->path)) {
            // 聊天页 /chat
            $templatePath = $this->themeDir . 'chat.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/messages/?$#', $this->path)) {
            // 消息中心页 /messages
            $templatePath = $this->themeDir . 'messages.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/reset-password/?$#', $this->path)) {
            // V36 新增：密码重置页 /reset-password
            $templatePath = $this->themeDir . 'reset-password.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/wallet/?$#', $this->path)) {
            // V67：会员中心 / 钱包（余额 / 会员档位 / 积分抵扣 / 订单）
            $templatePath = $this->themeDir . 'wallet.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/points/?$#', $this->path)) {
            // V68：积分中心（月签到 / 积分任务 / 积分流水）
            $templatePath = $this->themeDir . 'points.html';
        } elseif (preg_match('#^' . preg_quote($this->base, '#') . '/([a-z0-9_-]+)/?$#', $this->path, $matches)
            && !in_array($matches[1], ['index', 'install', 'health', 'api', 'admin', 'cron'], true)
            && file_exists($this->themeDir . $matches[1] . '.html')) {
            // 主题扩展页：由主题目录自动发现。主题作者只需在 templates/{主题}/ 下放一个同名 .html，
            // 即可获得 /{name} 路由，无需改动本文件（框架侧零改动）。
            // URL 段限 [a-z0-9_-] + 排除保留字，且拼接后必须落在当前主题目录内，天然防路径穿越；
            // 主题未提供该页时自动回退首页，行为不变。
            $templatePath = $this->themeDir . $matches[1] . '.html';
        } else {
            $templatePath = $this->themeDir . 'index.html';
            // 未命中任何路由：可能是首页，也可能是未知路径（软 404）。
            // 未知路径统一输出 noindex，避免无限 URL 空间被索引；
            // 状态码与「回退首页」行为保持不变，不影响主题扩展页自动发现。
            $normalizePath = static function ($p) {
                $p = rtrim((string)$p, '/');
                return $p === '' ? '/' : $p;
            };
            $homeCandidates = [
                $this->base === '' ? '/' : $this->base . '/',
                $this->base === '' ? '' : $this->base,
                $this->base === '' ? '/index.php' : $this->base . '/index.php',
                $this->base === '' ? '/index.php/' : $this->base . '/index.php/',
            ];
            $isUnknownPath = !in_array($normalizePath($this->path), array_map($normalizePath, $homeCandidates), true);
        }

        return [
            'templatePath' => $templatePath,
            'postDetail' => $postDetail,
            'categoryDetail' => $categoryDetail,
            'paywallHtml' => $paywallHtml,
            'searchQuery' => $searchQuery,
            'isUnknownPath' => $isUnknownPath,
            'ssrVisWhere' => $ssrVisWhere,
            'ssrVisParams' => $ssrVisParams,
            'earlyExit' => $earlyExit,
        ];
    }
}
