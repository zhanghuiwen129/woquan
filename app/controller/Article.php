<?php
// +----------------------------------------------------------------------
// | 文章控制器
// +----------------------------------------------------------------------

namespace app\controller;

use think\facade\Db;
use think\facade\View;
use think\facade\Request;

class Article
{
    /**
     * 文章列表页
     */
    public function index()
    {
        $userId = session('user_id') ?: cookie('user_id');
        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign([
            'userId' => $userId,
            'currentUser' => $currentUser,
            'isLogin' => true,
            'name' => '我圈社交平台',
            'subtitle' => '连接你我，分享精彩',
            'current_url' => '/articles'
        ]);
        return View::fetch('index/article/index');
    }

    /**
     * 发布/编辑文章页面
     */
    public function publishPage()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return redirect('/login');
            }

            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];

            View::assign([
                'userId' => $userId,
                'currentUser' => $currentUser,
                'isLogin' => true,
                'name' => '我圈社交平台',
                'subtitle' => '连接你我，分享精彩',
                'current_url' => '/articles/publish',
                'page_title' => '发布文章'
            ]);

            return View::fetch('index/article/publish');
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '发布页面错误: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * 文章详情页
     */
    public function detailPage()
    {
        $id = Request::param('id', 0);

        $userId = session('user_id') ?: cookie('user_id');
        $currentUser = $userId ? [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ] : [];

        View::assign([
            'article_id' => $id,
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId),
            'name' => '我圈社交平台',
            'subtitle' => '连接你我，分享精彩',
            'current_url' => '/articles/' . $id
        ]);
        return View::fetch('index/article/detail');
    }
    /**
     * 获取文章列表
     */
    public function apiList()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 10);
            $userId = Request::param('user_id', 0);
            $keyword = Request::param('keyword', '');
            $categoryId = Request::param('category_id', 0);
            $status = Request::param('status', '');
            $sort = Request::param('sort', 'create_time');
            $sortOrder = Request::param('sort_order', 'desc');

            // 记录请求参数
            \think\facade\Log::info('Article apiList called', [
                'page' => $page,
                'limit' => $limit,
                'user_id' => $userId,
                'keyword' => $keyword,
                'category_id' => $categoryId,
                'status' => $status,
                'sort' => $sort,
                'sort_order' => $sortOrder
            ]);

            $where = [];

            if ($userId > 0) {
                // 查询指定用户的文章：如果是自己访问显示所有状态，否则只显示已发布(status=1)
                $currentUserId = session('user_id') ?: cookie('user_id');
                if ($currentUserId == $userId) {
                    // 自己访问，显示所有状态的文章（除非指定了status参数）
                    if ($status !== '') {
                        $where[] = ['a.status', '=', $status];
                    }
                } else {
                    // 他人访问，只显示已发布的文章
                    $where[] = ['a.status', '=', 1];
                }
                $where[] = ['a.user_id', '=', $userId];
            } else {
                // 查询所有已发布的文章
                $where[] = ['a.status', '=', 1];
            }

            if ($status !== '') {
                $where[] = ['a.status', '=', $status];
            }

            if ($keyword) {
                $where[] = ['a.title|a.content|a.summary', 'like', '%' . $keyword . '%'];
            }

            if ($categoryId > 0) {
                $where[] = ['a.category_id', '=', $categoryId];
            }

            $orderField = 'a.create_time';
            $orderDirection = 'desc';

            $allowedSortFields = ['create_time', 'update_time', 'publish_time', 'view_count', 'like_count', 'comment_count', 'collect_count'];
            if (in_array($sort, $allowedSortFields)) {
                $orderField = 'a.' . $sort;
            }

            if (in_array(strtolower($sortOrder), ['asc', 'desc'])) {
                $orderDirection = strtolower($sortOrder);
            }

            $articles = Db::name('articles')
                ->alias('a')
                ->join('user u', 'a.user_id = u.id', 'LEFT')
                ->join('article_categories c', 'a.category_id = c.id', 'LEFT')
                ->field('a.*, u.username, u.nickname, u.avatar, c.name as category_name')
                ->where($where)
                ->order($orderField, $orderDirection)
                ->order('a.is_top', 'desc')
                ->order('a.id', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            $list = $articles->items();
            $total = $articles->total();

            \think\facade\Log::info('Article apiList query result', [
                'total' => $total,
                'count' => count($list)
            ]);
            foreach ($list as &$article) {
                $article['cover'] = $article['cover_image'] ?? '';
                $article['views'] = $article['view_count'] ?? 0;
                $article['likes'] = $article['like_count'] ?? 0;
                $article['comments'] = $article['comment_count'] ?? 0;
                $article['collections'] = $article['collect_count'] ?? 0;

                if (!empty($article['images'])) {
                    $article['images'] = json_decode($article['images'], true);
                } else {
                    $article['images'] = [];
                }

                $article['comments'] = Db::name('article_comments')
                    ->where('article_id', $article['id'])
                    ->where('status', 1)
                    ->count();

                $article['likes'] = Db::name('article_likes')
                    ->where('article_id', $article['id'])
                    ->count();
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $list,
                    'total' => $total
                ]
            ]);
        } catch (\Exception $e) {
            \think\facade\Log::error('Article apiList error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return json([
                'code' => 500,
                'msg' => '获取文章列表失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 获取文章详情
     */
    public function detail()
    {
        try {
            $id = Request::param('id', 0);

            $article = Db::name('articles')
                ->alias('a')
                ->leftJoin('user u', 'a.user_id = u.id')
                ->leftJoin('article_categories c', 'a.category_id = c.id')
                ->field('a.*, u.username, u.nickname, u.avatar, u.bio, c.name as category_name')
                ->where('a.id', $id)
                ->find();

            if (!$article) {
                return json([
                    'code' => 404,
                    'msg' => '文章不存在',
                    'data' => null
                ]);
            }

            $article['cover'] = $article['cover_image'] ?? '';
            $article['views'] = $article['view_count'] ?? 0;
            $article['likes'] = $article['like_count'] ?? 0;
            $article['comments'] = $article['comment_count'] ?? 0;
            $article['collections'] = $article['collect_count'] ?? 0;

            if (!empty($article['images'])) {
                $article['images'] = json_decode($article['images'], true);
            } else {
                $article['images'] = [];
            }

            Db::name('article_views')->insert([
                'article_id' => $id,
                'user_id' => session('user_id') ?: cookie('user_id') ?: 0,
                'ip' => Request::ip(),
                'user_agent' => Request::header('user-agent', ''),
                'create_time' => time()
            ]);

            $viewCount = Db::name('article_views')
                ->where('article_id', $id)
                ->count();
            Db::name('articles')->where('id', $id)->update(['view_count' => $viewCount]);
            $article['views'] = $viewCount;

            $commentCount = Db::name('article_comments')
                ->where('article_id', $id)
                ->where('status', 1)
                ->count();
            $article['comments'] = $commentCount;

            $likeCount = Db::name('article_likes')
                ->where('article_id', $id)
                ->count();
            $article['likes'] = $likeCount;

            $article['is_liked'] = false;
            if (session('user_id')) {
                $article['is_liked'] = Db::name('article_likes')
                    ->where('article_id', $id)
                    ->where('user_id', session('user_id'))
                    ->count() > 0;
            }

            $article['is_collected'] = false;
            if (session('user_id')) {
                $article['is_collected'] = Db::name('article_collections')
                    ->where('article_id', $id)
                    ->where('user_id', session('user_id'))
                    ->count() > 0;
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $article
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取文章详情失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 发布文章
     */
    public function publish()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $data = Request::post();
            $data['user_id'] = $userId;
            $data['create_time'] = time();
            $data['update_time'] = time();

            $allowedFields = [
                'title', 'content', 'summary', 'cover', 'images',
                'category_id', 'tags', 'status', 'publish_time'
            ];

            $insertData = [
                'user_id' => $userId,
                'create_time' => time(),
                'update_time' => time()
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'cover') {
                        $insertData['cover_image'] = $data[$field];
                    } else {
                        $insertData[$field] = $data[$field];
                    }
                }
            }

            if (isset($insertData['images'])) {
                if (is_string($insertData['images'])) {
                    $insertData['images'] = json_decode($insertData['images'], true);
                }
                if (!is_array($insertData['images'])) {
                    $insertData['images'] = [];
                }
                $insertData['images'] = json_encode($insertData['images']);
            } else {
                $insertData['images'] = json_encode([]);
            }

            if (!isset($insertData['status'])) {
                $insertData['status'] = 1;
            }

            if ($insertData['status'] == 1) {
                $insertData['publish_time'] = time();
            }

            $id = Db::name('articles')->insertGetId($insertData);

            Db::name('article_logs')->insert([
                'article_id' => $id,
                'user_id' => $userId,
                'action' => 'create',
                'remark' => '发布文章',
                'create_time' => time()
            ]);

            return json([
                'code' => 200,
                'msg' => '发布成功',
                'data' => ['id' => $id]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '发布失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 更新文章
     */
    public function update()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $id = Request::param('id', 0);
            $article = Db::name('articles')->where('id', $id)->find();

            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            if ($article['user_id'] != $userId) {
                return json(['code' => 403, 'msg' => '无权限操作']);
            }

            $data = Request::post();
            unset($data['id']);

            $allowedFields = [
                'title', 'content', 'summary', 'cover', 'images',
                'category_id', 'tags', 'status', 'publish_time'
            ];

            $updateData = ['update_time' => time()];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'cover') {
                        $updateData['cover_image'] = $data[$field];
                    } else {
                        $updateData[$field] = $data[$field];
                    }
                }
            }

            if (isset($updateData['images'])) {
                if (is_string($updateData['images'])) {
                    $updateData['images'] = json_decode($updateData['images'], true);
                }
                if (!is_array($updateData['images'])) {
                    $updateData['images'] = [];
                }
                $updateData['images'] = json_encode($updateData['images']);
            }

            if (isset($updateData['category_id']) && $updateData['category_id'] === '') {
                $updateData['category_id'] = null;
            }
            if (isset($updateData['tags']) && $updateData['tags'] === '') {
                $updateData['tags'] = '';
            }

            if ($article['status'] == 0 && isset($updateData['status']) && $updateData['status'] == 1) {
                $updateData['publish_time'] = time();
            }

            Db::name('articles')->where('id', $id)->update($updateData);

            return json([
                'code' => 200,
                'msg' => '更新成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '更新失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 删除文章
     */
    public function delete()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $id = Request::param('id', 0);
            $article = Db::name('articles')->where('id', $id)->find();

            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            if ($article['user_id'] != $userId) {
                return json(['code' => 403, 'msg' => '无权限操作']);
            }

            Db::name('articles')->where('id', $id)->delete();
            Db::name('article_comments')->where('article_id', $id)->delete();
            Db::name('article_likes')->where('article_id', $id)->delete();
            Db::name('article_collections')->where('article_id', $id)->delete();
            Db::name('article_views')->where('article_id', $id)->delete();
            Db::name('article_logs')->where('article_id', $id)->delete();

            return json([
                'code' => 200,
                'msg' => '删除成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '删除失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 点赞文章
     */
    public function like()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $articleId = Request::param('id', 0);
            $article = Db::name('articles')->where('id', $articleId)->find();

            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            $existed = Db::name('article_likes')
                ->where('article_id', $articleId)
                ->where('user_id', $userId)
                ->find();

            if ($existed) {
                Db::name('article_likes')
                    ->where('article_id', $articleId)
                    ->where('user_id', $userId)
                    ->delete();

                $likes = Db::name('article_likes')
                    ->where('article_id', $articleId)
                    ->count();

                Db::name('articles')->where('id', $articleId)->update(['like_count' => $likes]);

                return json([
                    'code' => 200,
                    'msg' => '取消点赞',
                    'data' => ['likes' => $likes, 'is_liked' => false]
                ]);
            } else {
                Db::name('article_likes')->insert([
                    'article_id' => $articleId,
                    'user_id' => $userId,
                    'create_time' => time()
                ]);

                $likes = Db::name('article_likes')
                    ->where('article_id', $articleId)
                    ->count();

                Db::name('articles')->where('id', $articleId)->update(['like_count' => $likes]);

                return json([
                    'code' => 200,
                    'msg' => '点赞成功',
                    'data' => ['likes' => $likes, 'is_liked' => true]
                ]);
            }
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 收藏文章
     */
    public function collect()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $articleId = Request::param('id', 0);
            $article = Db::name('articles')->where('id', $articleId)->find();

            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            $existed = Db::name('article_collections')
                ->where('article_id', $articleId)
                ->where('user_id', $userId)
                ->find();

            if ($existed) {
                Db::name('article_collections')
                    ->where('article_id', $articleId)
                    ->where('user_id', $userId)
                    ->delete();

                $collections = Db::name('article_collections')
                    ->where('article_id', $articleId)
                    ->count();

                Db::name('articles')->where('id', $articleId)->update(['collect_count' => $collections]);

                return json([
                    'code' => 200,
                    'msg' => '取消收藏',
                    'data' => ['collections' => $collections, 'is_collected' => false]
                ]);
            } else {
                Db::name('article_collections')->insert([
                    'article_id' => $articleId,
                    'user_id' => $userId,
                    'create_time' => time()
                ]);

                $collections = Db::name('article_collections')
                    ->where('article_id', $articleId)
                    ->count();

                Db::name('articles')->where('id', $articleId)->update(['collect_count' => $collections]);

                return json([
                    'code' => 200,
                    'msg' => '收藏成功',
                    'data' => ['collections' => $collections, 'is_collected' => true]
                ]);
            }
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取评论列表
     */
    public function comments()
    {
        try {
            $articleId = Request::param('article_id', 0);
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);

            $comments = Db::name('article_comments')
                ->alias('ac')
                ->join('user u', 'ac.user_id = u.id', 'LEFT')
                ->field('ac.*, u.username, u.nickname, u.avatar')
                ->where('ac.article_id', $articleId)
                ->order('ac.create_time', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            $list = $comments->items();
            foreach ($list as &$comment) {
                $comment['likes'] = Db::name('article_comment_likes')
                    ->where('comment_id', $comment['id'])
                    ->count();

                $comment['is_liked'] = false;
                if (session('user_id')) {
                    $comment['is_liked'] = Db::name('article_comment_likes')
                        ->where('comment_id', $comment['id'])
                        ->where('user_id', session('user_id'))
                        ->count() > 0;
                }
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $list,
                    'total' => $comments->total()
                ]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取评论失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 添加评论
     */
    public function addComment()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $articleId = Request::param('article_id', 0);
            $content = Request::param('content', '');
            $parentId = Request::param('parent_id', 0);

            if (!$articleId || !$content) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }

            $article = Db::name('articles')->where('id', $articleId)->find();
            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            $id = Db::name('article_comments')->insertGetId([
                'article_id' => $articleId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'content' => $content,
                'create_time' => time()
            ]);

            $commentCount = Db::name('article_comments')
                ->where('article_id', $articleId)
                ->count();
            Db::name('articles')->where('id', $articleId)->update(['comment_count' => $commentCount]);

            return json([
                'code' => 200,
                'msg' => '评论成功',
                'data' => ['id' => $id]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '评论失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 点赞评论
     */
    public function likeComment()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }

            $commentId = Request::param('id', 0);
            $comment = Db::name('article_comments')->where('id', $commentId)->find();

            if (!$comment) {
                return json(['code' => 404, 'msg' => '评论不存在']);
            }

            $existed = Db::name('article_comment_likes')
                ->where('comment_id', $commentId)
                ->where('user_id', $userId)
                ->find();

            if ($existed) {
                Db::name('article_comment_likes')
                    ->where('comment_id', $commentId)
                    ->where('user_id', $userId)
                    ->delete();

                return json([
                    'code' => 200,
                    'msg' => '取消点赞',
                    'data' => ['is_liked' => false]
                ]);
            } else {
                Db::name('article_comment_likes')->insert([
                    'comment_id' => $commentId,
                    'user_id' => $userId,
                    'create_time' => time()
                ]);

                return json([
                    'code' => 200,
                    'msg' => '点赞成功',
                    'data' => ['is_liked' => true]
                ]);
            }
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取分类列表
     */
    public function getCategories()
    {
        try {
            $categories = Db::name('categories')
                ->where('status', 1)
                ->order('sort_order', 'asc')
                ->order('id', 'desc')
                ->select()
                ->toArray();

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取分类失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 修复表结构
     */
    public function fixTable()
    {
        try {
            $columns = Db::name('articles')->getTableInfo();
            $columnNames = array_column($columns, 'Field');

            if (!in_array('images', $columnNames)) {
                Db::execute("ALTER TABLE `articles` ADD COLUMN `images` text COMMENT '文章配图(JSON数组)' AFTER `cover_image`");
            }

            return json([
                'code' => 200,
                'msg' => '修复成功'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '修复失败: ' . $e->getMessage()
            ]);
        }
    }
}
