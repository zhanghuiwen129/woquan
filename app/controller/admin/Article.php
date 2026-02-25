<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Request;
use think\facade\Db;
use think\facade\View;
use think\facade\Session;

class Article extends AdminController
{
    /**
     * 初始化方法
     */
    protected function initialize()
    {
        parent::initialize();
        // 可以在这里添加控制器的初始化逻辑
    }

    /**
     * 文章列表页面
     */
    public function index()
    {
        View::assign([
            'active' => 'articles',
            'page_title' => '文章管理',
            'title' => '文章管理 - 后台管理系统',
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        return View::fetch('admin/article/index');
    }

    /**
     * 文章列表数据
     */
    public function list()
    {
        try {
            $page = Request::param('page', 1);
            $limit = Request::param('limit', 20);
            $keyword = Request::param('keyword', '');
            $status = Request::param('status', '');
            $categoryId = Request::param('category_id', '');

            $where = [];

            if (!empty($keyword)) {
                $escapedKeyword = Db::escape($keyword);
                $where[] = ['a.title', 'like', '%' . $escapedKeyword . '%'];
            }

            if ($status !== '') {
                $where[] = ['a.status', '=', $status];
            }

            if (!empty($categoryId)) {
                $where[] = ['a.category_id', '=', $categoryId];
            }

            $articles = Db::name('articles')
                ->alias('a')
                ->field('a.*')
                ->where($where)
                ->order('a.is_top', 'desc')
                ->order('a.id', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            // 获取用户信息
            $items = $articles->items();
            $userIds = array_column($items, 'user_id');
            $users = [];
            if (!empty($userIds)) {
                try {
                    $userList = Db::name('user')->whereIn('id', $userIds)->select()->toArray();
                    foreach ($userList as $u) {
                        $users[$u['id']] = $u;
                    }
                } catch (\Exception $e) {
                    // 用户表可能不存在或查询失败，忽略
                }
            }

            // 获取分类信息
            $categoryIds = array_column($items, 'category_id');
            $categories = [];
            if (!empty($categoryIds)) {
                try {
                    $categoryList = Db::name('article_categories')->whereIn('id', $categoryIds)->select()->toArray();
                    foreach ($categoryList as $c) {
                        $categories[$c['id']] = $c['name'];
                    }
                } catch (\Exception $e) {
                    // 分类表可能不存在或查询失败，忽略
                }
            }

            // 组装数据
            foreach ($items as &$item) {
                $userId = $item['user_id'] ?? 0;
                $item['username'] = $users[$userId]['username'] ?? '-';
                $item['nickname'] = $users[$userId]['nickname'] ?? '-';
                $item['avatar'] = $users[$userId]['avatar'] ?? '';
                $categoryId = $item['category_id'] ?? 0;
                $item['category_name'] = $categories[$categoryId] ?? '-';
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $items,
                    'total' => $articles->total()
                ]
            ]);
        } catch (\Exception $e) {
            // 如果表不存在，返回空数据而不是报错
            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => [],
                    'total' => 0
                ]
            ]);
        }
    }

    /**
     * 文章详情
     */
    public function detail()
    {
        $id = Request::param('id', 0);

        $article = Db::name('articles')
            ->alias('a')
            ->leftJoin('user u', 'a.user_id = u.id')
            ->leftJoin('article_categories c', 'a.category_id = c.id')
            ->field('a.*, u.username, u.nickname, u.avatar, c.name as category_name')
            ->where('a.id', $id)
            ->find();

        if (!$article) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $article
        ]);
    }

    /**
     * 新增/编辑文章
     */
    public function save()
    {
        try {
            $params = Request::post();

            $id = $params['id'] ?? 0;
            $title = $params['title'] ?? '';
            $content = $params['content'] ?? '';
            $summary = $params['summary'] ?? '';
            $categoryId = $params['category_id'] ?? 0;
            $coverImage = $params['cover_image'] ?? '';
            $tags = $params['tags'] ?? '';
            $status = $params['status'] ?? 0;
            $isTop = $params['is_top'] ?? 0;
            $isRecommend = $params['is_recommend'] ?? 0;

            if (empty($title)) {
                return json(['code' => 400, 'msg' => '文章标题不能为空']);
            }

            if (empty($content)) {
                return json(['code' => 400, 'msg' => '文章内容不能为空']);
            }

            $data = [
                'title' => $title,
                'content' => $content,
                'summary' => $summary,
                'category_id' => $categoryId,
                'cover_image' => $coverImage,
                'tags' => $tags,
                'status' => $status,
                'is_top' => $isTop,
                'is_recommend' => $isRecommend,
                'update_time' => time()
            ];

            if ($id > 0) {
                // 编辑
                $article = Db::name('articles')->find($id);
                if (!$article) {
                    return json(['code' => 404, 'msg' => '文章不存在']);
                }

                if ($status == 1 && $article['status'] != 1) {
                    $data['publish_time'] = time();
                }

                Db::name('articles')->where('id', $id)->update($data);
                return json(['code' => 200, 'msg' => '文章更新成功']);
            } else {
                // 新增
                $data['user_id'] = session('admin_id') ?? 1;
                $data['create_time'] = time();

                if ($status == 1) {
                    $data['publish_time'] = time();
                }

                $articleId = Db::name('articles')->insertGetId($data);
                return json(['code' => 200, 'msg' => '文章创建成功', 'data' => ['id' => $articleId]]);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 删除文章
     */
    public function delete()
    {
        try {
            $id = Request::param('id', 0);

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '文章ID不能为空']);
            }

            $article = Db::name('articles')->find($id);
            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            Db::name('articles')->where('id', $id)->delete();
            Db::name('article_views')->where('article_id', $id)->delete();
            Db::name('article_collections')->where('article_id', $id)->delete();
            Db::name('article_likes')->where('article_id', $id)->delete();

            return json(['code' => 200, 'msg' => '文章删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除文章
     */
    public function batchDelete()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $ids = $params['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的文章']);
            }

            Db::name('articles')->whereIn('id', $ids)->delete();
            Db::name('article_views')->whereIn('article_id', $ids)->delete();
            Db::name('article_collections')->whereIn('article_id', $ids)->delete();
            Db::name('article_likes')->whereIn('article_id', $ids)->delete();

            return json(['code' => 200, 'msg' => '批量删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 批量发布文章
     */
    public function batchPublish()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $ids = $params['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                return json(['code' => 400, 'msg' => '请选择要发布的文章']);
            }

            Db::name('articles')->whereIn('id', $ids)->update([
                'status' => 1,
                'publish_time' => time(),
                'update_time' => time()
            ]);

            return json(['code' => 200, 'msg' => '批量发布成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 发布/下架文章
     */
    public function changeStatus()
    {
        try {
            $id = Request::param('id', 0);
            $status = Request::param('status', 1);

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '文章ID不能为空']);
            }

            $article = Db::name('articles')->find($id);
            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            $updateData = ['status' => $status, 'update_time' => time()];
            if ($status == 1 && empty($article['publish_time'])) {
                $updateData['publish_time'] = time();
            }

            Db::name('articles')->where('id', $id)->update($updateData);

            // 记录操作日志
            $action = $status == 1 ? '发布' : '下架';
            Db::name('article_logs')->insert([
                'article_id' => $id,
                'admin_id' => session('admin_id') ?? 1,
                'action' => $action,
                'remark' => "管理员{$action}了文章: {$article['title']}",
                'create_time' => time()
            ]);

            return json(['code' => 200, 'msg' => "文章{$action}成功"]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 置顶/取消置顶
     */
    public function changeTop()
    {
        try {
            $id = Request::param('id', 0);
            $isTop = Request::param('is_top', 0);

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '文章ID不能为空']);
            }

            $article = Db::name('articles')->find($id);
            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            Db::name('articles')->where('id', $id)->update([
                'is_top' => $isTop,
                'update_time' => time()
            ]);

            $action = $isTop == 1 ? '置顶' : '取消置顶';
            return json(['code' => 200, 'msg' => "文章{$action}成功"]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 推荐/取消推荐
     */
    public function changeRecommend()
    {
        try {
            $id = Request::param('id', 0);
            $isRecommend = Request::param('is_recommend', 0);

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '文章ID不能为空']);
            }

            $article = Db::name('articles')->find($id);
            if (!$article) {
                return json(['code' => 404, 'msg' => '文章不存在']);
            }

            Db::name('articles')->where('id', $id)->update([
                'is_recommend' => $isRecommend,
                'update_time' => time()
            ]);

            $action = $isRecommend == 1 ? '推荐' : '取消推荐';
            return json(['code' => 200, 'msg' => "文章{$action}成功"]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 分类管理页面
     */
    public function categories()
    {
        View::assign([
            'active' => 'article_categories',
            'page_title' => '文章分类管理',
            'title' => '文章分类管理 - 后台管理系统',
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        return View::fetch('admin/article/categories');
    }

    /**
     * 分类列表数据
     */
    public function categoryList()
    {
        try {
            $categories = Db::name('article_categories')
                ->order('sort', 'asc')
                ->order('id', 'desc')
                ->select()
                ->toArray();

            // 构建树形结构
            $tree = $this->buildCategoryTree($categories);

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $tree
            ]);
        } catch (\Exception $e) {
            // 如果表不存在，返回空数据
            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => []
            ]);
        }
    }

    /**
     * 保存分类
     */
    public function saveCategory()
    {
        try {
            $params = Request::post();

            $id = $params['id'] ?? 0;
            $name = $params['name'] ?? '';
            $parentId = $params['parent_id'] ?? 0;
            $icon = $params['icon'] ?? '';
            $sort = $params['sort'] ?? 0;
            $status = $params['status'] ?? 1;

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '分类名称不能为空']);
            }

            $data = [
                'name' => $name,
                'parent_id' => $parentId,
                'icon' => $icon,
                'sort' => $sort,
                'status' => $status,
                'update_time' => time()
            ];

            if ($id > 0) {
                Db::name('article_categories')->where('id', $id)->update($data);
                return json(['code' => 200, 'msg' => '分类更新成功']);
            } else {
                $data['create_time'] = time();
                $categoryId = Db::name('article_categories')->insertGetId($data);
                return json(['code' => 200, 'msg' => '分类创建成功', 'data' => ['id' => $categoryId]]);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 删除分类
     */
    public function deleteCategory()
    {
        try {
            $id = Request::param('id', 0);

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '分类ID不能为空']);
            }

            // 检查是否有子分类
            $childrenCount = Db::name('article_categories')->where('parent_id', $id)->count();
            if ($childrenCount > 0) {
                return json(['code' => 400, 'msg' => '该分类下有子分类，无法删除']);
            }

            // 检查是否有文章
            $articleCount = Db::name('articles')->where('category_id', $id)->count();
            if ($articleCount > 0) {
                return json(['code' => 400, 'msg' => '该分类下有文章，无法删除']);
            }

            Db::name('article_categories')->where('id', $id)->delete();

            return json(['code' => 200, 'msg' => '分类删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 构建分类树
     */
    private function buildCategoryTree($categories, $parentId = 0)
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = $this->buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $tree[] = $category;
            }
        }
        return $tree;
    }
}
