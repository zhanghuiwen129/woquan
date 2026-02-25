<?php
namespace app\controller;

use think\facade\Db;
use think\facade\Request;

class QuickReplies extends BaseFrontendController
{
    protected $userId;

    protected function initialize()
    {
        parent::initialize();
        $this->userId = (int)(session('user_id') ?: cookie('user_id'));
    }

    /**
     * 获取快捷回复列表
     */
    public function list()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $category = input('category', '');
        $keyword = input('keyword', '');

        try {
            $query = Db::name('quick_replies')
                ->where('is_active', 1)
                ->where(function($query) {
                    $query->where('user_id', $this->userId)
                          ->whereOr('user_id', 0);
                });

            if ($category) {
                $query->where('category', $category);
            }

            if ($keyword) {
                $query->whereLike('content', "%{$keyword}%");
            }

            $list = $query->order('user_id DESC, sort_order ASC, id ASC')
                          ->select();

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $list
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 创建快捷回复
     */
    public function create()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $content = input('content', '');
        $category = input('category', 'custom');
        $sortOrder = (int)input('sort_order', 0);

        if (empty($content)) {
            return json(['code' => 400, 'msg' => '回复内容不能为空']);
        }

        try {
            $data = [
                'user_id' => $this->userId,
                'content' => $content,
                'category' => in_array($category, ['greeting', 'common', 'farewell', 'custom']) ? $category : 'custom',
                'sort_order' => $sortOrder,
                'is_active' => 1,
                'use_count' => 0,
                'create_time' => time(),
                'update_time' => time()
            ];

            $id = Db::name('quick_replies')->insertGetId($data);

            $reply = Db::name('quick_replies')->where('id', $id)->find();

            return json([
                'code' => 200,
                'msg' => '创建成功',
                'data' => $reply
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '创建失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 更新快捷回复
     */
    public function update()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $id = (int)input('id');
        $content = input('content', '');
        $category = input('category', 'custom');
        $sortOrder = (int)input('sort_order', 0);
        $isActive = (int)input('is_active', 1);

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '回复ID不能为空']);
        }

        try {
            $reply = Db::name('quick_replies')->where('id', $id)->find();

            if (!$reply) {
                return json(['code' => 404, 'msg' => '回复不存在']);
            }

            if ($reply['user_id'] != $this->userId) {
                return json(['code' => 403, 'msg' => '无权限操作']);
            }

            $data = [
                'update_time' => time()
            ];

            if ($content) {
                $data['content'] = $content;
            }

            if ($category) {
                $data['category'] = in_array($category, ['greeting', 'common', 'farewell', 'custom']) ? $category : 'custom';
            }

            $data['sort_order'] = $sortOrder;
            $data['is_active'] = $isActive;

            Db::name('quick_replies')->where('id', $id)->update($data);

            $reply = Db::name('quick_replies')->where('id', $id)->find();

            return json([
                'code' => 200,
                'msg' => '更新成功',
                'data' => $reply
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 删除快捷回复
     */
    public function delete()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $id = (int)input('id');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '回复ID不能为空']);
        }

        try {
            $reply = Db::name('quick_replies')->where('id', $id)->find();

            if (!$reply) {
                return json(['code' => 404, 'msg' => '回复不存在']);
            }

            if ($reply['user_id'] != $this->userId) {
                return json(['code' => 403, 'msg' => '无权限操作']);
            }

            Db::name('quick_replies')->where('id', $id)->delete();

            return json([
                'code' => 200,
                'msg' => '删除成功'
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 使用快捷回复
     */
    public function use()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $id = (int)input('id');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '回复ID不能为空']);
        }

        try {
            $reply = Db::name('quick_replies')->where('id', $id)->find();

            if (!$reply) {
                return json(['code' => 404, 'msg' => '回复不存在']);
            }

            Db::name('quick_replies')->where('id', $id)->inc('use_count')->update();

            return json([
                'code' => 200,
                'msg' => '使用成功',
                'data' => [
                    'id' => $reply['id'],
                    'content' => $reply['content']
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '使用失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取快捷回复详情
     */
    public function detail()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $id = (int)input('id');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '回复ID不能为空']);
        }

        try {
            $reply = Db::name('quick_replies')->where('id', $id)->find();

            if (!$reply) {
                return json(['code' => 404, 'msg' => '回复不存在']);
            }

            if ($reply['user_id'] != $this->userId && $reply['user_id'] != 0) {
                return json(['code' => 403, 'msg' => '无权限查看']);
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $reply
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除快捷回复
     */
    public function batchDelete()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $ids = input('ids', []);

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要删除的回复']);
        }

        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Db::name('quick_replies')
                ->where('user_id', $this->userId)
                ->whereIn('id', $ids)
                ->delete();

            return json([
                'code' => 200,
                'msg' => '删除成功',
                'data' => [
                    'count' => count($ids)
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取分类统计
     */
    public function stats()
    {
        if (empty($this->userId)) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        try {
            $stats = Db::name('quick_replies')
                ->where('is_active', 1)
                ->where(function($query) {
                    $query->where('user_id', $this->userId)
                          ->whereOr('user_id', 0);
                })
                ->field('category, COUNT(*) as count')
                ->group('category')
                ->select();

            $result = [
                'greeting' => 0,
                'common' => 0,
                'farewell' => 0,
                'custom' => 0,
                'total' => 0
            ];

            foreach ($stats as $stat) {
                $result[$stat['category']] = $stat['count'];
                $result['total'] += $stat['count'];
            }

            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '获取失败: ' . $e->getMessage()]);
        }
    }
}
