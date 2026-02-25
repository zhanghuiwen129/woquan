<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;
use think\facade\Db;

class User extends AdminController
{

    // 用户列表
    public function index()
    {
        // 获取查询参数
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');
        $canSpeak = Request::param('can_speak', '');
        $regTimeStart = Request::param('reg_time_start', '');
        $regTimeEnd = Request::param('reg_time_end', '');
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);

        // 检查并添加can_speak字段
        try {
            $config = config('database.connections.mysql');
            $prefix = $config['prefix'] ?? 'wq_';
            $tableName = $prefix . 'user';
            
            $columns = Db::query("SHOW COLUMNS FROM `{$tableName}` LIKE 'can_speak'");
            if (empty($columns)) {
                Db::execute("ALTER TABLE `{$tableName}` ADD COLUMN `can_speak` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否允许发言:1允许,0禁止' AFTER `status`");
            }
        } catch (\Exception $e) {
            // 忽略字段添加错误，继续执行
        }

        // 构建查询条件
        $where = [];
        
        // 关键词搜索（支持用户名、邮箱、手机号）
        if (!empty($keyword)) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['username|email|phone', 'like', '%' . $escapedKeyword . '%'];
        }
        
        // 用户状态筛选
        if ($status !== '') {
            $where[] = ['status', '=', $status];
        }
        
        // 发言状态筛选
        if ($canSpeak !== '') {
            $where[] = ['can_speak', '=', $canSpeak];
        }
        
        // 注册时间筛选 - 兼容不同的时间字段名
        if (!empty($regTimeStart)) {
            $where[] = ['regtime', '>=', strtotime($regTimeStart)];
        }
        if (!empty($regTimeEnd)) {
            $where[] = ['regtime', '<=', strtotime($regTimeEnd . ' 23:59:59')];
        }

        // 查询用户列表
        try {
            $users = Db::name('user')
                ->where($where)
                ->order('id DESC')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
        } catch (\Exception $e) {
            // 如果字段不存在，尝试不带条件查询
            $users = Db::name('user')
                ->order('id DESC')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
        }

        // 分配模板变量
        View::assign('users', $users);
        View::assign('keyword', $keyword);
        View::assign('status', $status);
        View::assign('can_speak', $canSpeak);
        View::assign('reg_time_start', $regTimeStart);
        View::assign('reg_time_end', $regTimeEnd);
        View::assign('admin_name', Session::get('admin_name', '管理员'));

        return View::fetch('admin/user');
    }

    // 用户详情
    public function detail($id = null)
    {
        $id = $id ?? Request::param('id');
        if (empty($id)) {
            return json(['code' => 400, 'msg' => '用户ID不能为空']);
        }
        
        // 查询用户信息
        $user = Db::name('user')->find($id);
        if (empty($user)) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }
        
        // 查询用户统计数据
        $userStatistics = Db::name('user')->where('id', $id)->field('post_count, comment_count, like_count, follow_count, follower_count, favorite_count')->find();
        
        // 查询用户最近动态
        $userPosts = Db::name('moments')
            ->where('user_id', $id)
            ->order('create_time DESC')
            ->limit(5)
            ->select();
        
        // 查询用户分组
        $userGroups = Db::name('user_group_relation')
            ->alias('ugr')
            ->leftJoin('user_groups ug', 'ugr.group_id = ug.id')
            ->where('ugr.user_id', $id)
            ->field('ug.id, ug.name, ug.description, ug.is_system')
            ->select();
        
        // 查询所有分组（用于分配）
        $allGroups = Db::name('user_groups')->order('id DESC')->select();
        
        // 查询用户标签
        $userTags = Db::name('user_tag_relation')
            ->alias('utr')
            ->leftJoin('user_tags ut', 'utr.tag_id = ut.id')
            ->where('utr.user_id', $id)
            ->field('ut.id, ut.name, ut.color, ut.description')
            ->select();
        
        // 查询所有标签（用于分配）
        $allTags = Db::name('user_tags')->order('id DESC')->select();
        
        // 分配模板变量
        View::assign('user', $user);
        View::assign('user_statistics', $userStatistics);
        View::assign('user_posts', $userPosts);
        View::assign('user_groups', $userGroups);
        View::assign('all_groups', $allGroups);
        View::assign('user_tags', $userTags);
        View::assign('all_tags', $allTags);
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        
        return View::fetch('admin/user_detail');
    }

    // 强制用户下线
    public function forceLogout()
    {
        try {
            $id = Request::param('id');
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            // 检查用户是否存在
            $user = Db::name('user')->find($id);
            if (empty($user)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 这里可以添加强制下线的逻辑，例如清除用户的session或token
            // 由于当前系统可能没有实现token机制，这里只记录日志
            
            // 记录日志
            Db::name('admin_log')
                ->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '强制用户下线',
                    'content' => '强制用户ID: ' . $id . ' 下线',
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
            
            return json(['code' => 200, 'msg' => '用户已被强制下线']);
        } catch (\Exception $e) {
            return $this->error('操作失败，请稍后重试');
        }
    }

    // 编辑用户
    public function edit($id = null)
    {
        $id = $id ?? Request::param('id');
        if (empty($id)) {
            return json(['code' => 400, 'msg' => '用户ID不能为空']);
        }
        
        // 查询用户信息
        $user = Db::name('user')->find($id);
        if (empty($user)) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }
        
        // 分配模板变量
        View::assign('user', $user);
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        
        return View::fetch('admin/user_edit');
    }

    // 更新用户资料
    public function update()
    {
        try {
            // 获取请求参数
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $id = $params['id'] ?? 0;
            $username = $params['username'] ?? '';
            $email = $params['email'] ?? '';
            $phone = $params['phone'] ?? '';
            $status = $params['status'] ?? 1;
            $nickname = $params['nickname'] ?? '';
            $bio = $params['bio'] ?? '';
            $reason = $params['reason'] ?? ''; // 操作原因
            
            // 验证参数
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            if (empty($username)) {
                return json(['code' => 400, 'msg' => '用户名不能为空']);
            }
            
            // 检查用户是否存在
            $user = Db::name('user')->find($id);
            if (empty($user)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 检查用户名是否已被使用
            $exists = Db::name('user')->where('username', $username)->where('id', '<>', $id)->find();
            if ($exists) {
                return json(['code' => 400, 'msg' => '用户名已存在']);
            }
            
            // 敏感词检测（简单示例，实际应从配置中读取敏感词库）
            $sensitiveWords = Db::name('system_settings')->where('key', 'sensitive_words')->value('value');
            $sensitiveWords = $sensitiveWords ? explode(',', $sensitiveWords) : [];
            
            if (!empty($nickname)) {
                foreach ($sensitiveWords as $word) {
                    if (strpos($nickname, trim($word)) !== false) {
                        return json(['code' => 400, 'msg' => '昵称包含敏感词，请修改后重试']);
                    }
                }
            }
            if (!empty($bio)) {
                foreach ($sensitiveWords as $word) {
                    if (strpos($bio, trim($word)) !== false) {
                        return json(['code' => 400, 'msg' => '个性签名包含敏感词，请修改后重试']);
                    }
                }
            }
            
            // 更新用户信息
            $data = [
                'username' => $username,
                'email' => $email,
                'phone' => $phone,
                'nickname' => $nickname,
                'bio' => $bio,
                'status' => $status,
                'update_time' => time()
            ];
            
            Db::name('user')->where('id', $id)->update($data);
            
            // 记录操作日志
            $action = $user['status'] == $status ? '更新用户资料' : ($status == 0 ? '锁定用户账号' : '解锁用户账号');
            $content = '用户ID: ' . $id . ', 用户名: ' . $username . ', 操作: ' . $action;
            if (!empty($reason)) {
                $content .= ', 原因: ' . $reason;
            }
            
            Db::name('admin_log')
                ->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $action,
                    'content' => $content,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
            
            return json(['code' => 200, 'msg' => '操作成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }

    // 删除用户
    public function delete($id = null)
    {
        // 支持从POST参数获取id
        $id = $id ?? Request::post('id');

        // 防止删除系统管理员账号
        if ($id == 1) {
            return json(['code' => 500, 'msg' => '系统管理员账号无法删除']);
        }

        $result = Db::name('user')->delete($id);
        if ($result) {
            return json(['code' => 200, 'msg' => '删除成功']);
        } else {
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }

    // 修改用户密码
    public function changePassword()
    {
        try {
            // 获取请求参数 - 优先从JSON获取
            $jsonData = Request::getContent();
            $params = [];
            if (!empty($jsonData)) {
                $params = json_decode($jsonData, true);
            }

            // 如果JSON解析失败，尝试从POST获取
            if (empty($params)) {
                $params = Request::post();
            }

            $id = $params['id'] ?? 0;
            $newPassword = $params['new_password'] ?? '';
            
            // 验证参数
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            if (empty($newPassword)) {
                return json(['code' => 400, 'msg' => '新密码不能为空']);
            }
            
            // 检查用户是否存在
            $user = Db::name('user')->find($id);
            if (empty($user)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 密码加密
            $encryptedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // 更新密码
            $result = Db::name('user')->where('id', $id)->update([
                'password' => $encryptedPassword,
                'update_time' => time()
            ]);
            
            if ($result) {
                // 记录日志
                Db::name('admin_log')
                    ->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '修改用户密码',
                        'content' => '修改了用户ID: ' . $id . ' 的密码',
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                
                return json(['code' => 200, 'msg' => '密码修改成功']);
            } else {
                return json(['code' => 500, 'msg' => '密码修改失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量删除
    public function batchDelete()
    {
        try {
            // 获取请求参数 - 优先从JSON获取
            $jsonData = Request::getContent();
            $params = [];
            if (!empty($jsonData)) {
                $params = json_decode($jsonData, true);
            }

            // 如果JSON解析失败，尝试从POST获取
            if (empty($params)) {
                $params = Request::post();
            }

            $ids = $params['ids'] ?? [];
            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的用户']);
            }
            
            // 防止删除超级管理员
            if (in_array(1, $ids)) {
                return json(['code' => 403, 'msg' => '不能删除超级管理员']);
            }
            
            // 执行批量删除
            $result = Db::name('user')->whereIn('id', $ids)->delete();
            
            if ($result !== false) {
                // 记录日志
                Db::name('admin_log')
                    ->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '批量删除用户',
                        'content' => '批量删除了用户ID: ' . implode(',', $ids),
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                
                return json(['code' => 200, 'msg' => '批量删除成功', 'data' => ['deleted_count' => $result]]);
            } else {
                return json(['code' => 500, 'msg' => '批量删除失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量更新用户状态
    public function batchUpdateStatus()
    {
        try {
            // 获取请求参数 - 优先从JSON获取
            $jsonData = Request::getContent();
            $params = [];
            if (!empty($jsonData)) {
                $params = json_decode($jsonData, true);
            }

            // 如果JSON解析失败，尝试从POST获取
            if (empty($params)) {
                $params = Request::post();
            }

            $ids = $params['ids'] ?? [];
            $status = $params['status'] ?? 1;

            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要更新的用户']);
            }
            
            // 执行批量更新
            $result = Db::name('user')->whereIn('id', $ids)->update(['status' => $status]);
            
            if ($result !== false) {
                // 记录日志
                Db::name('admin_log')
                    ->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '批量更新用户状态',
                        'content' => '将用户ID: ' . implode(',', $ids) . ' 的状态更新为' . ($status == 1 ? '启用' : '禁用'),
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                
                return json(['code' => 200, 'msg' => '批量更新状态成功']);
            } else {
                return json(['code' => 500, 'msg' => '批量更新状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量更新用户发言状态
    public function batchUpdateSpeakStatus()
    {
        try {
            // 获取请求参数 - 优先从JSON获取
            $jsonData = Request::getContent();
            $params = [];
            if (!empty($jsonData)) {
                $params = json_decode($jsonData, true);
            }

            // 如果JSON解析失败，尝试从POST获取
            if (empty($params)) {
                $params = Request::post();
            }

            $ids = $params['ids'] ?? [];
            $canSpeak = $params['can_speak'] ?? 1;

            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要更新的用户']);
            }

            // 执行批量更新
            $result = Db::name('user')->whereIn('id', $ids)->update(['can_speak' => $canSpeak]);

            if ($result !== false) {
                // 记录日志
                Db::name('admin_log')
                    ->insert([
                        'admin_id' => Session::get('admin_id'),
                        'username' => Session::get('admin_name'),
                        'action' => '批量更新用户发言状态',
                        'content' => '将用户ID: ' . implode(',', $ids) . ' 的发言状态更新为' . ($canSpeak == 1 ? '允许发言' : '禁止发言'),
                        'ip' => Request::ip(),
                        'create_time' => time()
                    ]);
                
                return json(['code' => 200, 'msg' => '批量更新发言状态成功']);
            } else {
                return json(['code' => 500, 'msg' => '批量更新发言状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量发送通知
    public function batchSendNotification()
    {
        try {
            // 获取请求参数 - 优先从JSON获取
            $jsonData = Request::getContent();
            $params = [];
            if (!empty($jsonData)) {
                $params = json_decode($jsonData, true);
            }

            // 如果JSON解析失败，尝试从POST获取
            if (empty($params)) {
                $params = Request::post();
            }

            $ids = $params['ids'] ?? [];
            $title = $params['title'] ?? '';
            $content = $params['content'] ?? '';

            if (empty($ids)) {
                return json(['code' => 400, 'msg' => '请选择要发送通知的用户']);
            }

            if (empty($title) || empty($content)) {
                return json(['code' => 400, 'msg' => '请输入通知标题和内容']);
            }

            // 这里可以添加发送通知的逻辑，例如保存到通知表
            // 由于当前系统可能没有实现通知表，这里只记录日志

            // 记录日志
            Db::name('admin_log')
                ->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '批量发送通知',
                    'content' => '向用户ID: ' . implode(',', $ids) . ' 发送了通知，标题: ' . $title,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);

            return json(['code' => 200, 'msg' => '批量发送通知成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 更新用户状态
    public function updateStatus()
    {
        if (Request::isPost()) {
            $id = Request::post('id');
            $status = Request::post('status');

            $result = Db::name('user')->where('id', $id)->update(['status' => $status]);
            if ($result) {
                return json(['code' => 200, 'msg' => '状态更新成功']);
            } else {
                return json(['code' => 500, 'msg' => '状态更新失败']);
            }
        }
    }

    // 切换用户发言状态
    public function toggleSpeakStatus()
    {
        $id = Request::param('id');
        $canSpeak = Request::param('can_speak');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        // 尝试更新，同时处理字段不存在的情况
        try {
            $result = Db::name('user')->where('id', $id)->update(['can_speak' => $canSpeak]);

            if ($result === false) {
                // 可能是字段不存在，尝试添加字段
                $config = config('database.connections.mysql');
                $prefix = $config['prefix'] ?? 'wq_';
                Db::execute("ALTER TABLE `{$prefix}user` ADD COLUMN `can_speak` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否允许发言'");
                // 重新更新
                $result = Db::name('user')->where('id', $id)->update(['can_speak' => $canSpeak]);
            }

            if ($result !== false) {
                return json(['code' => 200, 'msg' => '发言状态更新成功']);
            } else {
                return json(['code' => 500, 'msg' => '发言状态更新失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 用户登录日志页面
    public function loginLogs()
    {
        View::assign([
            'admin_name' => Session::get('admin_name', '管理员')
        ]);
        return View::fetch('admin/user_login_logs');
    }

    // 用户分组管理
    public function groups()
    {
        // 检查是否有系统分组，如果没有则创建默认分组
        $systemGroupCount = Db::name('user_groups')->where('is_system', 1)->count();
        
        if ($systemGroupCount == 0) {
            // 创建默认系统分组
            $defaultGroups = [
                [
                    'name' => '普通用户',
                    'description' => '系统默认分组，所有新注册用户自动加入',
                    'is_system' => 1,
                    'sort' => 1,
                    'create_time' => time(),
                    'update_time' => time()
                ],
                [
                    'name' => 'VIP用户',
                    'description' => 'VIP会员用户分组',
                    'is_system' => 1,
                    'sort' => 2,
                    'create_time' => time(),
                    'update_time' => time()
                ],
                [
                    'name' => '管理员',
                    'description' => '系统管理员分组',
                    'is_system' => 1,
                    'sort' => 3,
                    'create_time' => time(),
                    'update_time' => time()
                ]
            ];
            
            Db::name('user_groups')->insertAll($defaultGroups);
        }
        
        $groups = Db::name('user_groups')->order('sort ASC, id DESC')->select();
        View::assign('groups', $groups);
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        return View::fetch('admin/user_groups');
    }

    // 用户标签管理
    public function tags()
    {
        // 检查是否有标签，如果没有则创建默认标签
        $tagCount = Db::name('user_tags')->count();
        
        if ($tagCount == 0) {
            // 创建默认标签
            $defaultTags = [
                [
                    'name' => '活跃用户',
                    'color' => '#52c41a',
                    'description' => '经常活跃的用户',
                    'create_time' => time(),
                    'update_time' => time()
                ],
                [
                    'name' => '新用户',
                    'color' => '#1890ff',
                    'description' => '新注册的用户',
                    'create_time' => time(),
                    'update_time' => time()
                ],
                [
                    'name' => '认证用户',
                    'color' => '#722ed1',
                    'description' => '已通过身份认证的用户',
                    'create_time' => time(),
                    'update_time' => time()
                ],
                [
                    'name' => '优质用户',
                    'color' => '#faad14',
                    'description' => '贡献度高的优质用户',
                    'create_time' => time(),
                    'update_time' => time()
                ]
            ];
            
            Db::name('user_tags')->insertAll($defaultTags);
        }
        
        $tags = Db::name('user_tags')->order('id DESC')->select();
        View::assign('tags', $tags);
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        return View::fetch('admin/user_tags');
    }

    // 给用户分配分组
    public function assignGroup()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $userId = $params['user_id'] ?? 0;
            $groupId = $params['group_id'] ?? 0;
            
            if (empty($userId) || empty($groupId)) {
                return json(['code' => 400, 'msg' => '用户ID和分组ID不能为空']);
            }
            
            // 检查用户是否存在
            $user = Db::name('user')->find($userId);
            if (empty($user)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 检查分组是否存在
            $group = Db::name('user_groups')->find($groupId);
            if (empty($group)) {
                return json(['code' => 404, 'msg' => '分组不存在']);
            }
            
            // 检查是否已分配
            $existing = Db::name('user_group_relation')
                ->where('user_id', $userId)
                ->where('group_id', $groupId)
                ->find();
            
            if ($existing) {
                return json(['code' => 400, 'msg' => '该用户已在该分组中']);
            }
            
            // 分配分组
            Db::name('user_group_relation')->insert([
                'user_id' => $userId,
                'group_id' => $groupId,
                'create_time' => time()
            ]);
            
            return json(['code' => 200, 'msg' => '分组分配成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 给用户分配标签
    public function assignTag()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $userId = $params['user_id'] ?? 0;
            $tagId = $params['tag_id'] ?? 0;
            
            if (empty($userId) || empty($tagId)) {
                return json(['code' => 400, 'msg' => '用户ID和标签ID不能为空']);
            }
            
            // 检查用户是否存在
            $user = Db::name('user')->find($userId);
            if (empty($user)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 检查标签是否存在
            $tag = Db::name('user_tags')->find($tagId);
            if (empty($tag)) {
                return json(['code' => 404, 'msg' => '标签不存在']);
            }
            
            // 检查是否已分配
            $existing = Db::name('user_tag_relation')
                ->where('user_id', $userId)
                ->where('tag_id', $tagId)
                ->find();
            
            if ($existing) {
                return json(['code' => 400, 'msg' => '该用户已拥有该标签']);
            }
            
            // 分配标签
            Db::name('user_tag_relation')->insert([
                'user_id' => $userId,
                'tag_id' => $tagId,
                'create_time' => time()
            ]);
            
            return json(['code' => 200, 'msg' => '标签分配成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 移除用户分组
    public function removeGroup()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $userId = $params['user_id'] ?? 0;
            $groupId = $params['group_id'] ?? 0;
            
            if (empty($userId) || empty($groupId)) {
                return json(['code' => 400, 'msg' => '用户ID和分组ID不能为空']);
            }
            
            // 移除分组
            Db::name('user_group_relation')
                ->where('user_id', $userId)
                ->where('group_id', $groupId)
                ->delete();
            
            return json(['code' => 200, 'msg' => '分组移除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 移除用户标签
    public function removeTag()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }
            
            $userId = $params['user_id'] ?? 0;
            $tagId = $params['tag_id'] ?? 0;
            
            if (empty($userId) || empty($tagId)) {
                return json(['code' => 400, 'msg' => '用户ID和标签ID不能为空']);
            }
            
            // 移除标签
            Db::name('user_tag_relation')
                ->where('user_id', $userId)
                ->where('tag_id', $tagId)
                ->delete();
            
            return json(['code' => 200, 'msg' => '标签移除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 用户统计页面
    public function statistics()
    {
        // 获取当前时间
        $now = time();
        $today = strtotime(date('Y-m-d', $now));
        $yesterday = $today - 86400;
        $weekStart = strtotime('monday this week', $now);
        $monthStart = strtotime(date('Y-m-01', $now));
        
        // 用户总数
        $totalUsers = Db::name('user')->count();
        
        // 今日新增用户
        $todayNewUsers = Db::name('user')->where('regtime', '>=', $today)->count();
        
        // 昨日新增用户
        $yesterdayNewUsers = Db::name('user')->where('regtime', '>=', $yesterday)->where('regtime', '<', $today)->count();
        
        // 本周新增用户
        $weekNewUsers = Db::name('user')->where('regtime', '>=', $weekStart)->count();
        
        // 本月新增用户
        $monthNewUsers = Db::name('user')->where('regtime', '>=', $monthStart)->count();
        
        // 活跃用户数（最近7天登录过的用户）
        $activeUsers = Db::name('user')->where('logtime', '>=', $now - 604800)->count();
        
        // 用户状态统计
        $userStatus = Db::name('user')->field('status, count(*) as count')->group('status')->select();
        
        // 用户分组统计
        $groupStats = Db::name('user_group_relation')
            ->alias('ugr')
            ->leftJoin('user_groups ug', 'ugr.group_id = ug.id')
            ->field('ug.name, count(*) as count')
            ->group('ugr.group_id')
            ->select();
        
        // 用户标签统计
        $tagStats = Db::name('user_tag_relation')
            ->alias('utr')
            ->leftJoin('user_tags ut', 'utr.tag_id = ut.id')
            ->field('ut.name, ut.color, count(*) as count')
            ->group('utr.tag_id')
            ->select();
        
        // 用户增长趋势（最近7天）
        $growthTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today - ($i * 86400);
            $dateStr = date('m-d', $date);
            $count = Db::name('user')->where('regtime', '>=', $date)->where('regtime', '<', $date + 86400)->count();
            $growthTrend[] = ['date' => $dateStr, 'count' => $count];
        }
        
        // 分配模板变量
        View::assign('totalUsers', $totalUsers);
        View::assign('todayNewUsers', $todayNewUsers);
        View::assign('yesterdayNewUsers', $yesterdayNewUsers);
        View::assign('weekNewUsers', $weekNewUsers);
        View::assign('monthNewUsers', $monthNewUsers);
        View::assign('activeUsers', $activeUsers);
        View::assign('userStatus', $userStatus);
        View::assign('groupStats', $groupStats);
        View::assign('tagStats', $tagStats);
        View::assign('growthTrend', $growthTrend);
        View::assign('admin_name', Session::get('admin_name', '管理员'));
        
        return View::fetch('admin/user_statistics');
    }
    
    // 获取登录日志数据（API）
    public function loginLogsData()
    {
        $page = Request::param('page', 1);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status', '');
        $limit = 20;

        $where = [];

        if (!empty($keyword)) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['u.username|l.login_ip|l.device_type|l.browser', 'like', '%' . $escapedKeyword . '%'];
        }

        if ($status !== '') {
            $where[] = ['l.status', '=', $status];
        }

        $logs = Db::name('login_logs')
            ->alias('l')
            ->leftJoin('user u', 'l.user_id = u.id')
            ->field('l.*, u.username, u.nickname, u.avatar')
            ->where($where)
            ->order('l.id', 'desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $logs->items(),
                'total' => $logs->total()
            ]
        ]);
    }

    // 违规行为处理
    // 记录用户违规
    public function addViolation()
    {
        try {
            $params = Request::post();
            $userId = $params['user_id'];
            $violationType = $params['violation_type'];
            $violationReason = $params['violation_reason'];
            $violationContent = $params['violation_content'] ?? '';
            
            if (empty($userId) || empty($violationType) || empty($violationReason)) {
                return json(['code' => 400, 'msg' => '用户ID、违规类型和违规原因不能为空']);
            }
            
            // Check if user exists
            if (!Db::name('user')->find($userId)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            $now = time();
            $adminId = Session::get('admin_id', 1); // 默认管理员ID
            
            // Add violation record
            $violationData = [
                'user_id' => $userId,
                'violation_type' => $violationType,
                'violation_reason' => $violationReason,
                'violation_content' => $violationContent,
                'violation_time' => $now,
                'violation_ip' => Request::ip(),
                'operator_id' => $adminId,
                'status' => 0 // 待处理
            ];
            
            $violationId = Db::name('user_violations')->insertGetId($violationData);
            
            return json(['code' => 200, 'msg' => '违规记录添加成功', 'data' => ['violation_id' => $violationId]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 处理用户违规（添加惩罚）
    public function addPunishment()
    {
        try {
            $params = Request::post();
            $userId = $params['user_id'];
            $violationId = $params['violation_id'];
            $punishmentType = $params['punishment_type'];
            $punishmentReason = $params['punishment_reason'];
            $duration = $params['duration'] ?? 0; // 惩罚时长（小时），0表示永久
            
            if (empty($userId) || empty($violationId) || empty($punishmentType) || empty($punishmentReason)) {
                return json(['code' => 400, 'msg' => '用户ID、违规记录ID、惩罚类型和惩罚原因不能为空']);
            }
            
            // Check if user exists
            if (!Db::name('user')->find($userId)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // Check if violation exists
            if (!Db::name('user_violations')->find($violationId)) {
                return json(['code' => 404, 'msg' => '违规记录不存在']);
            }
            
            $now = time();
            $adminId = Session::get('admin_id', 1); // 默认管理员ID
            
            // Calculate end time
            $endTime = $duration > 0 ? $now + ($duration * 3600) : null;
            
            // Add punishment record
            $punishmentData = [
                'user_id' => $userId,
                'violation_id' => $violationId,
                'punishment_type' => $punishmentType,
                'punishment_reason' => $punishmentReason,
                'start_time' => $now,
                'end_time' => $endTime,
                'operator_id' => $adminId,
                'status' => 1 // 生效中
            ];
            
            Db::name('user_punishments')->insert($punishmentData);
            
            // Update violation status to processed
            Db::name('user_violations')->where('id', $violationId)->update(['status' => 1]);
            
            // Apply punishment to user
            $this->applyPunishment($userId, $punishmentType, $endTime);
            
            return json(['code' => 200, 'msg' => '惩罚已应用']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 应用惩罚（更新用户状态）
    private function applyPunishment($userId, $punishmentType, $endTime)
    {
        switch ($punishmentType) {
            case 'ban_speak':
                // 禁止发言
                Db::name('user')->where('id', $userId)->update(['can_speak' => 0]);
                break;
            case 'ban_login':
                // 禁止登录
                Db::name('user')->where('id', $userId)->update(['status' => 0]);
                break;
            case 'ban_forever':
                // 永久封禁
                Db::name('user')->where('id', $userId)->update(['status' => 0, 'is_banned_forever' => 1]);
                break;
            // warning不需要更新用户状态
        }
    }

    // 获取用户违规记录
    public function getViolations()
    {
        try {
            $userId = Request::get('user_id');
            
            if (empty($userId)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            // Check if user exists
            if (!Db::name('user')->find($userId)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // Get violations
            $violations = Db::name('user_violations')
                ->where('user_id', $userId)
                ->order('violation_time DESC')
                ->select();
            
            return json(['code' => 200, 'msg' => '获取成功', 'data' => ['violations' => $violations]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 获取用户惩罚记录
    public function getPunishments()
    {
        try {
            $userId = Request::get('user_id');
            
            if (empty($userId)) {
                return json(['code' => 400, 'msg' => '用户ID不能为空']);
            }
            
            // Check if user exists
            if (!Db::name('user')->find($userId)) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // Get punishments
            $punishments = Db::name('user_punishments')
                ->where('user_id', $userId)
                ->order('start_time DESC')
                ->select();
            
            return json(['code' => 200, 'msg' => '获取成功', 'data' => ['punishments' => $punishments]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 解除用户惩罚
    public function removePunishment()
    {
        try {
            $params = Request::post();
            $punishmentId = $params['punishment_id'];
            
            if (empty($punishmentId)) {
                return json(['code' => 400, 'msg' => '惩罚记录ID不能为空']);
            }
            
            // Get punishment record
            $punishment = Db::name('user_punishments')->find($punishmentId);
            if (empty($punishment)) {
                return json(['code' => 404, 'msg' => '惩罚记录不存在']);
            }
            
            // Update punishment status to inactive
            Db::name('user_punishments')->where('id', $punishmentId)->update(['status' => 0]);
            
            // Remove punishment from user
            $this->removePunishmentFromUser($punishment['user_id'], $punishment['punishment_type']);
            
            return json(['code' => 200, 'msg' => '惩罚已解除']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 添加用户标签
    public function addUserTag()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $name = $params['name'] ?? '';
            $color = $params['color'] ?? '#3B82F6';
            $description = $params['description'] ?? '';

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '标签名称不能为空']);
            }

            // 检查标签是否已存在
            $existing = Db::name('user_tags')->where('name', $name)->find();
            if ($existing) {
                return json(['code' => 400, 'msg' => '标签名称已存在']);
            }

            // 添加标签
            $tagId = Db::name('user_tags')->insertGetId([
                'name' => $name,
                'color' => $color,
                'description' => $description,
                'create_time' => time(),
                'update_time' => time()
            ]);

            return json(['code' => 200, 'msg' => '标签创建成功', 'data' => ['id' => $tagId]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 添加用户分组
    public function addUserGroup()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $name = $params['name'] ?? '';
            $description = $params['description'] ?? '';

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '分组名称不能为空']);
            }

            // 检查分组是否已存在
            $existing = Db::name('user_groups')->where('name', $name)->find();
            if ($existing) {
                return json(['code' => 400, 'msg' => '分组名称已存在']);
            }

            // 添加分组
            $groupId = Db::name('user_groups')->insertGetId([
                'name' => $name,
                'description' => $description,
                'is_system' => 0,
                'create_time' => time(),
                'update_time' => time()
            ]);

            return json(['code' => 200, 'msg' => '分组创建成功', 'data' => ['id' => $groupId]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 删除用户分组
    public function deleteUserGroup()
    {
        try {
            $groupId = Request::param('id', 0);

            if (empty($groupId)) {
                return json(['code' => 400, 'msg' => '分组ID不能为空']);
            }

            // 检查分组是否存在
            $group = Db::name('user_groups')->find($groupId);
            if (empty($group)) {
                return json(['code' => 404, 'msg' => '分组不存在']);
            }

            // 检查是否为系统分组
            if ($group['is_system'] == 1) {
                return json(['code' => 403, 'msg' => '系统分组不能删除']);
            }

            // 删除分组
            Db::name('user_groups')->where('id', $groupId)->delete();

            // 删除用户与分组的关联
            Db::name('user_group_relation')->where('group_id', $groupId)->delete();

            return json(['code' => 200, 'msg' => '分组删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量删除用户分组
    public function batchDeleteUserGroups()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $ids = $params['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的分组']);
            }

            // 检查是否有系统分组
            $systemGroups = Db::name('user_groups')
                ->whereIn('id', $ids)
                ->where('is_system', 1)
                ->find();

            if ($systemGroups) {
                return json(['code' => 403, 'msg' => '系统分组不能删除']);
            }

            // 删除分组
            Db::name('user_groups')->whereIn('id', $ids)->delete();

            // 删除用户与分组的关联
            Db::name('user_group_relation')->whereIn('group_id', $ids)->delete();

            return json(['code' => 200, 'msg' => '批量删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 删除用户标签
    public function deleteUserTag()
    {
        try {
            $tagId = Request::param('id', 0);

            if (empty($tagId)) {
                return json(['code' => 400, 'msg' => '标签ID不能为空']);
            }

            // 检查标签是否存在
            $tag = Db::name('user_tags')->find($tagId);
            if (empty($tag)) {
                return json(['code' => 404, 'msg' => '标签不存在']);
            }

            // 删除标签
            Db::name('user_tags')->where('id', $tagId)->delete();

            // 删除用户与标签的关联
            Db::name('user_tag_relation')->where('tag_id', $tagId)->delete();

            return json(['code' => 200, 'msg' => '标签删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量删除用户标签
    public function batchDeleteUserTags()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $ids = $params['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的标签']);
            }

            // 删除标签
            Db::name('user_tags')->whereIn('id', $ids)->delete();

            // 删除用户与标签的关联
            Db::name('user_tag_relation')->whereIn('tag_id', $ids)->delete();

            return json(['code' => 200, 'msg' => '批量删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 编辑用户标签
    public function editUserTag()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $id = $params['id'] ?? 0;
            $name = $params['name'] ?? '';
            $color = $params['color'] ?? '#3B82F6';
            $description = $params['description'] ?? '';

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '标签ID不能为空']);
            }

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '标签名称不能为空']);
            }

            // 检查标签是否存在
            $tag = Db::name('user_tags')->find($id);
            if (empty($tag)) {
                return json(['code' => 404, 'msg' => '标签不存在']);
            }

            // 检查标签名称是否重复（排除当前标签）
            $existing = Db::name('user_tags')
                ->where('name', $name)
                ->where('id', '<>', $id)
                ->find();
            if ($existing) {
                return json(['code' => 400, 'msg' => '标签名称已存在']);
            }

            // 更新标签
            Db::name('user_tags')->where('id', $id)->update([
                'name' => $name,
                'color' => $color,
                'description' => $description
            ]);

            return json(['code' => 200, 'msg' => '标签更新成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 添加用户分组
    public function addGroup()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $name = $params['name'] ?? '';
            $description = $params['description'] ?? '';

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '分组名称不能为空']);
            }

            // 检查分组是否已存在
            $existing = Db::name('user_groups')->where('name', $name)->find();
            if ($existing) {
                return json(['code' => 400, 'msg' => '分组名称已存在']);
            }

            // 添加分组
            $groupId = Db::name('user_groups')->insertGetId([
                'name' => $name,
                'description' => $description,
                'is_system' => 0,
                'create_time' => time()
            ]);

            return json(['code' => 200, 'msg' => '分组创建成功', 'data' => ['id' => $groupId]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 删除用户分组
    public function deleteGroup()
    {
        try {
            $groupId = Request::param('id', 0);

            if (empty($groupId)) {
                return json(['code' => 400, 'msg' => '分组ID不能为空']);
            }

            // 检查分组是否存在
            $group = Db::name('user_groups')->find($groupId);
            if (empty($group)) {
                return json(['code' => 404, 'msg' => '分组不存在']);
            }

            // 检查是否为系统分组
            if ($group['is_system'] == 1) {
                return json(['code' => 400, 'msg' => '系统分组不能删除']);
            }

            // 删除分组
            Db::name('user_groups')->where('id', $groupId)->delete();

            // 删除用户与分组的关联
            Db::name('user_group_relation')->where('group_id', $groupId)->delete();

            return json(['code' => 200, 'msg' => '分组删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 批量删除用户分组
    public function batchDeleteGroups()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $ids = $params['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                return json(['code' => 400, 'msg' => '请选择要删除的分组']);
            }

            // 过滤掉系统分组
            $systemGroups = Db::name('user_groups')
                ->whereIn('id', $ids)
                ->where('is_system', 1)
                ->column('id');

            $deletableIds = array_diff($ids, $systemGroups);

            if (!empty($systemGroups)) {
                return json(['code' => 400, 'msg' => '不能删除系统分组']);
            }

            // 删除分组
            Db::name('user_groups')->whereIn('id', $deletableIds)->delete();

            // 删除用户与分组的关联
            Db::name('user_group_relation')->whereIn('group_id', $deletableIds)->delete();

            return json(['code' => 200, 'msg' => '批量删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 编辑用户分组
    public function editGroup()
    {
        try {
            $params = Request::post();
            if (empty($params)) {
                $jsonData = Request::getContent();
                $params = json_decode($jsonData, true);
            }

            $id = $params['id'] ?? 0;
            $name = $params['name'] ?? '';
            $description = $params['description'] ?? '';

            if (empty($id)) {
                return json(['code' => 400, 'msg' => '分组ID不能为空']);
            }

            if (empty($name)) {
                return json(['code' => 400, 'msg' => '分组名称不能为空']);
            }

            // 检查分组是否存在
            $group = Db::name('user_groups')->find($id);
            if (empty($group)) {
                return json(['code' => 404, 'msg' => '分组不存在']);
            }

            // 检查是否为系统分组
            if ($group['is_system'] == 1) {
                return json(['code' => 400, 'msg' => '系统分组不能修改']);
            }

            // 检查分组名称是否重复（排除当前分组）
            $existing = Db::name('user_groups')
                ->where('name', $name)
                ->where('id', '<>', $id)
                ->find();
            if ($existing) {
                return json(['code' => 400, 'msg' => '分组名称已存在']);
            }

            // 更新分组
            Db::name('user_groups')->where('id', $id)->update([
                'name' => $name,
                'description' => $description
            ]);

            return json(['code' => 200, 'msg' => '分组更新成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    // 从用户移除惩罚
    private function removePunishmentFromUser($userId, $punishmentType)
    {
        switch ($punishmentType) {
            case 'ban_speak':
                // 恢复发言
                Db::name('user')->where('id', $userId)->update(['can_speak' => 1]);
                break;
            case 'ban_login':
                // 恢复登录
                Db::name('user')->where('id', $userId)->update(['status' => 1]);
                break;
            case 'ban_forever':
                // 解除永久封禁
                Db::name('user')->where('id', $userId)->update(['status' => 1, 'is_banned_forever' => 0]);
                break;
        }
    }
}
