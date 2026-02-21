<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Friends extends BaseFrontendController
{
    // 好友页面
    public function index()
    {
        // 获取当前登录用户信息
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

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId)
        ]);
        return View::fetch('index/friends');
    }
    // 获取好友分组列表
    public function getGroupList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $groups = Db::name('friend_groups')
                ->where('user_id', $userId)
                ->order('sort', 'asc')
                ->order('create_time', 'asc')
                ->select();

            // 获取每个分组的好友数量
            if (!empty($groups)) {
                foreach ($groups as &$group) {
                    $friendCount = Db::name('friend_group_members')
                        ->where('group_id', $group['id'])
                        ->where('user_id', $userId)
                        ->count();
                    $group['friend_count'] = $friendCount;
                }
            }

            return $this->success($groups, '获取分组列表成功');

        } catch (\Exception $e) {
            return $this->error('获取分组列表失败: ' . $e->getMessage());
        }
    }

    // 创建好友分组
    public function createGroup()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['name'])) {
                return $this->badRequest('无效的请求数据');
            }

            $name = $data['name'];
            $sort = $data['sort'] ?? 0;

            $existingGroup = Db::name('friend_groups')
                ->where('user_id', $userId)
                ->where('name', $name)
                ->find();

            if ($existingGroup) {
                return $this->badRequest('分组名称已存在');
            }

            $now = time();
            $groupData = [
                'user_id' => $userId,
                'name' => $name,
                'sort' => $sort,
                'create_time' => $now,
                'update_time' => $now
            ];

            $groupId = Db::name('friend_groups')->insertGetId($groupData);
            if (!$groupId) {
                return $this->error('创建分组失败');
            }

            $result = [
                'id' => $groupId,
                'user_id' => $userId,
                'name' => $name,
                'sort' => $sort,
                'create_time' => $now,
                'update_time' => $now,
                'friend_count' => 0
            ];

            return $this->success($result, '创建分组成功');

        } catch (\Exception $e) {
            return $this->error('创建分组失败: ' . $e->getMessage());
        }
    }

    // 修改好友分组
    public function updateGroup()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['group_id']) || !isset($data['name'])) {
                return $this->badRequest('无效的请求数据');
            }

            $groupId = $data['group_id'];
            $name = $data['name'];
            $sort = $data['sort'] ?? 0;

            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            $existingGroup = Db::name('friend_groups')
                ->where('user_id', $userId)
                ->where('name', $name)
                ->where('id', '<>', $groupId)
                ->find();

            if ($existingGroup) {
                return $this->badRequest('分组名称已存在');
            }

            $now = time();
            $updateData = [
                'name' => $name,
                'sort' => $sort,
                'update_time' => $now
            ];

            $result = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->update($updateData);

            if (!$result) {
                return $this->error('修改分组失败');
            }

            // 获取更新后的分组信息
            $updatedGroup = Db::name('friend_groups')
                ->where('id', $groupId)
                ->find();

            // 获取好友数量
            $friendCount = Db::name('friend_group_members')
                ->where('group_id', $groupId)
                ->where('user_id', $userId)
                ->count();
            $updatedGroup['friend_count'] = $friendCount;

            return $this->success($updatedGroup, '修改分组成功');

        } catch (\Exception $e) {
            return $this->error('修改分组失败: ' . $e->getMessage());
        }
    }

    // 删除好友分组
    public function deleteGroup()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['group_id'])) {
                return $this->badRequest('无效的请求数据');
            }
            }

            $groupId = $data['group_id'];

            // 检查分组是否存在并属于当前用户
            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            Db::startTrans();

            $deleteMembersResult = Db::name('friend_group_members')
                ->where('group_id', $groupId)
                ->where('user_id', $userId)
                ->delete();

            if ($deleteMembersResult === false) {
                Db::rollback();
                return $this->error('删除分组失败');
            }

            // 删除分组
            $deleteGroupResult = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->delete();

            if (!$deleteGroupResult) {
                Db::rollback();
                return $this->error('删除分组失败');
            }

            // 提交事务
            Db::commit();

            return $this->success(null, '删除分组成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除分组失败: ' . $e->getMessage());
        }
    }

    // 获取分组内的好友列表
    public function getGroupMembers()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $groupId = $_GET['group_id'] ?? null;
            if (!$groupId) {
                return $this->badRequest('缺少分组ID');
            }
            }

            // 检查分组是否存在并属于当前用户
            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 20;
            $offset = ($page - 1) * $limit;

            $query = Db::name('friend_group_members')
                ->alias('fgm')
                ->join('user u', 'fgm.friend_id = u.id', 'LEFT')
                ->where('fgm.group_id', $groupId)
                ->where('fgm.user_id', $userId)
                ->field('u.id, u.nickname, u.avatar, u.level, u.bio, fgm.add_time');

            $total = $query->count();

            $friends = $query->order('fgm.add_time', 'asc')->limit($offset, $limit)->select();

            return $this->success([
                'list' => $friends,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取分组好友列表成功');

        } catch (\Exception $e) {
            return $this->error('获取分组好友列表失败: ' . $e->getMessage());
        }
    }

    // 添加好友到分组
    public function addFriendToGroup()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['group_id']) || !isset($data['friend_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $groupId = $data['group_id'];
            $friendId = $data['friend_id'];

            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            $isFriend = Db::name('follows')
                ->where([
                    ['user_id', '=', $userId],
                    ['friend_id', '=', $friendId],
                    ['status', '=', 1]
                ])->find();

            if (!$isFriend) {
                return $this->badRequest('不是好友关系，无法添加到分组');
            }

            $existingMember = Db::name('friend_group_members')
                ->where([
                    ['group_id', '=', $groupId],
                    ['user_id', '=', $userId],
                    ['friend_id', '=', $friendId]
                ])->find();

            if ($existingMember) {
                return $this->badRequest('好友已经在该分组中');
            }

            $memberData = [
                'group_id' => $groupId,
                'user_id' => $userId,
                'friend_id' => $friendId,
                'add_time' => time()
            ];

            $result = Db::name('friend_group_members')->insert($memberData);

            if (!$result) {
                return $this->error('添加好友到分组失败');
            }

            return $this->success(null, '添加好友到分组成功');

        } catch (\Exception $e) {
            return $this->error('添加好友到分组失败: ' . $e->getMessage());
        }
    }

    // 从分组移除好友
    public function removeFriendFromGroup()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['group_id']) || !isset($data['friend_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $groupId = $data['group_id'];
            $friendId = $data['friend_id'];

            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            $result = Db::name('friend_group_members')
                ->where([
                    ['group_id', '=', $groupId],
                    ['user_id', '=', $userId],
                    ['friend_id', '=', $friendId]
                ])->delete();

            if (!$result) {
                return $this->error('从分组移除好友失败');
            }

            return $this->success(null, '从分组移除好友成功');

        } catch (\Exception $e) {
            return $this->error('从分组移除好友失败: ' . $e->getMessage());
        }
    }

    // 批量管理好友分组
    public function batchManageGroupMembers()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $groupEnabled = Db::name('system_config')->where('config_key', 'group_enabled')->value('config_value') ?? '1';
            if ($groupEnabled != '1') {
                return $this->forbidden('好友分组功能已关闭');
            }

            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['group_id']) || !isset($data['friend_ids']) || !is_array($data['friend_ids'])) {
                return $this->badRequest('无效的请求数据');
            }

            $groupId = $data['group_id'];
            $friendIds = $data['friend_ids'];
            $action = $data['action'] ?? 'add';

            $group = Db::name('friend_groups')
                ->where('id', $groupId)
                ->where('user_id', $userId)
                ->find();

            if (!$group) {
                return $this->notFound('分组不存在或没有权限');
            }

            Db::startTrans();

            $successCount = 0;

            if ($action === 'add') {
                // 批量添加好友到分组
                $now = time();
                $insertData = [];
                foreach ($friendIds as $friendId) {
                    // 检查是否是好友关系
                    $isFriend = Db::name('follows')
                        ->where([
                            ['user_id', '=', $userId],
                            ['friend_id', '=', $friendId],
                            ['status', '=', 1]
                        ])->find();

                    if (!$isFriend) {
                        continue;
                    }

                    // 检查好友是否已经在分组中
                    $existingMember = Db::name('friend_group_members')
                        ->where([
                            ['group_id', '=', $groupId],
                            ['user_id', '=', $userId],
                            ['friend_id', '=', $friendId]
                        ])->find();

                    if ($existingMember) {
                        continue;
                    }

                    $insertData[] = [
                        'group_id' => $groupId,
                        'user_id' => $userId,
                        'friend_id' => $friendId,
                        'add_time' => $now
                    ];
                }

                if (!empty($insertData)) {
                    $result = Db::name('friend_group_members')->insertAll($insertData);
                    $successCount = $result;
                }
            } else if ($action === 'remove') {
                // 批量从分组移除好友
                $result = Db::name('friend_group_members')
                    ->where([
                        ['group_id', '=', $groupId],
                        ['user_id', '=', $userId],
                        ['friend_id', 'in', $friendIds]
                    ])->delete();
                $successCount = $result;
            }

            Db::commit();

            return $this->success(['count' => $successCount], $action === 'add' ? '批量添加好友到分组成功' : '批量从分组移除好友成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('批量管理失败: ' . $e->getMessage());
        }
    }
}
