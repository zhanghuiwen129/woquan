<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 主题管理控制器
 */
class Theme extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 主题管理首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/theme_index');
    }

    // 主题模板管理
    public function themeTemplates()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $type = Request::param('type', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['name', 'like', "%{$escapedKeyword}%"];
        }
        if ($type !== '' && $type !== null) {
            $where[] = ['type', '=', $type];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $themes = Db::name('theme_templates')
            ->where($where)
            ->order('id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'themes' => $themes,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/theme_templates');
    }

    // 主题模板表单
    public function themeTemplateForm()
    {
        $id = Request::param('id', 0);
        $theme = [];
        if ($id > 0) {
            $theme = Db::name('theme_templates')->find($id);
            if ($theme) {
                $theme['style'] = json_decode($theme['style'], true);
                $theme['config'] = json_decode($theme['config'], true);
            }
        }

        View::assign('theme', $theme);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/theme_template_form');
    }

    // 保存主题模板
    public function saveThemeTemplate()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $name = Request::param('name', '');
            $description = Request::param('description', '');
            $type = Request::param('type', 0);
            $style = Request::param('style', '{}');
            $config = Request::param('config', '{}');
            $status = Request::param('status', 1);
            $is_default = Request::param('is_default', 0);
            
            try {
                // 验证必填字段
                if (empty($name)) {
                    return json(['code' => 400, 'msg' => '主题名称不能为空']);
                }
                
                // 验证JSON格式
                $styleArray = json_decode($style, true);
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '样式或配置数据格式错误']);
                }
                
                // 如果设置为默认主题，取消其他主题的默认状态
                if ($is_default) {
                    Db::name('theme_templates')->update(['is_default' => 0]);
                }
                
                $data = [
                    'name' => $name,
                    'description' => $description,
                    'type' => $type,
                    'style' => $style,
                    'config' => $config,
                    'status' => $status,
                    'is_default' => $is_default,
                    'update_time' => time()
                ];
                
                if ($id > 0) {
                    // 更新主题
                    Db::name('theme_templates')->where('id', $id)->update($data);
                    $msg = '主题模板更新成功';
                } else {
                    // 创建主题
                    $data['create_time'] = time();
                    Db::name('theme_templates')->insert($data);
                    $msg = '主题模板创建成功';
                }
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $id > 0 ? '更新主题模板' : '创建主题模板',
                    'content' => '主题名称: ' . $name,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => $msg]);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除主题模板
    public function deleteThemeTemplate()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 检查是否为默认主题
                $theme = Db::name('theme_templates')->find($id);
                if ($theme && $theme['is_default']) {
                    return json(['code' => 400, 'msg' => '默认主题不能删除']);
                }
                
                // 删除主题模板
                Db::name('theme_templates')->where('id', $id)->delete();
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '删除主题模板',
                    'content' => '主题名称: ' . $theme['name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '主题模板删除成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 启用/禁用主题模板
    public function toggleThemeTemplate()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $status = Request::param('status', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 检查是否为默认主题
                $theme = Db::name('theme_templates')->find($id);
                if ($theme && $theme['is_default'] && $status == 0) {
                    return json(['code' => 400, 'msg' => '默认主题不能禁用']);
                }
                
                // 更新状态
                Db::name('theme_templates')->where('id', $id)->update(['status' => $status]);
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $status == 1 ? '启用主题模板' : '禁用主题模板',
                    'content' => '主题名称: ' . $theme['name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => $status == 1 ? '主题模板已启用' : '主题模板已禁用']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 设置默认主题
    public function setDefaultTheme()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 检查主题是否存在
                $theme = Db::name('theme_templates')->find($id);
                if (!$theme) {
                    return json(['code' => 400, 'msg' => '主题不存在']);
                }
                
                // 检查主题是否启用
                if ($theme['status'] == 0) {
                    return json(['code' => 400, 'msg' => '禁用的主题不能设置为默认主题']);
                }
                
                // 更新默认主题
                Db::name('theme_templates')->update(['is_default' => 0]);
                Db::name('theme_templates')->where('id', $id)->update(['is_default' => 1]);
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '设置默认主题',
                    'content' => '主题名称: ' . $theme['name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '默认主题设置成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 主题规则管理
    public function themeRules()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $rule_type = Request::param('rule_type', '');
        $status = Request::param('status', '');

        $where = [];
        if ($keyword) {
            $where[] = ['rule_name', 'like', "%{$keyword}%"];
        }
        if ($rule_type !== '' && $rule_type !== null) {
            $where[] = ['rule_type', '=', $rule_type];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', $status];
        }

        $rules = Db::name('theme_rules')
            ->where($where)
            ->order('id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'rules' => $rules,
            'keyword' => $keyword,
            'rule_type' => $rule_type,
            'status' => $status,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/theme_rules');
    }

    // 主题规则表单
    public function themeRuleForm()
    {
        $id = Request::param('id', 0);
        $rule = [];
        if ($id > 0) {
            $rule = Db::name('theme_rules')->find($id);
            if ($rule) {
                $rule['rule_content'] = json_decode($rule['rule_content'], true);
            }
        }

        View::assign('rule', $rule);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/theme_rule_form');
    }

    // 保存主题规则
    public function saveThemeRule()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $rule_name = Request::param('rule_name', '');
            $rule_type = Request::param('rule_type', 0);
            $rule_content = Request::param('rule_content', '{}');
            $status = Request::param('status', 1);
            
            try {
                // 验证必填字段
                if (empty($rule_name)) {
                    return json(['code' => 400, 'msg' => '规则名称不能为空']);
                }
                
                // 验证JSON格式
                $ruleContentArray = json_decode($rule_content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '规则内容数据格式错误']);
                }
                
                $data = [
                    'rule_name' => $rule_name,
                    'rule_type' => $rule_type,
                    'rule_content' => $rule_content,
                    'status' => $status,
                    'update_time' => time()
                ];
                
                if ($id > 0) {
                    // 更新规则
                    Db::name('theme_rules')->where('id', $id)->update($data);
                    $msg = '主题规则更新成功';
                } else {
                    // 创建规则
                    $data['create_time'] = time();
                    Db::name('theme_rules')->insert($data);
                    $msg = '主题规则创建成功';
                }
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $id > 0 ? '更新主题规则' : '创建主题规则',
                    'content' => '规则名称: ' . $rule_name,
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => $msg]);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除主题规则
    public function deleteThemeRule()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 删除主题规则
                $rule = Db::name('theme_rules')->find($id);
                Db::name('theme_rules')->where('id', $id)->delete();
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '删除主题规则',
                    'content' => '规则名称: ' . $rule['rule_name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '主题规则删除成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 启用/禁用主题规则
    public function toggleThemeRule()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $status = Request::param('status', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 更新状态
                $rule = Db::name('theme_rules')->find($id);
                Db::name('theme_rules')->where('id', $id)->update(['status' => $status]);
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $status == 1 ? '启用主题规则' : '禁用主题规则',
                    'content' => '规则名称: ' . $rule['rule_name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => $status == 1 ? '主题规则已启用' : '主题规则已禁用']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 用户主题数据管理
    public function userThemes()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $user_id = Request::param('user_id', '');
        $theme_id = Request::param('theme_id', '');

        $where = [];
        if ($user_id) {
            $where[] = ['ut.user_id', '=', $user_id];
        }
        if ($theme_id) {
            $where[] = ['ut.theme_id', '=', $theme_id];
        }

        $userThemes = Db::name('user_themes')
            ->alias('ut')
            ->leftJoin('user u', 'ut.user_id = u.id')
            ->leftJoin('theme_templates tt', 'ut.theme_id = tt.id')
            ->field('ut.*, u.username, u.nickname, tt.name as theme_name')
            ->where($where)
            ->order('ut.id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);

        View::assign([
            'user_themes' => $userThemes,
            'user_id' => $user_id,
            'theme_id' => $theme_id,
            'admin_name' => Session::get('admin_name')
        ]);

        return View::fetch('admin/theme_user_themes');
    }

    // 编辑用户主题
    public function editUserTheme()
    {
        $id = Request::param('id', 0);
        $userTheme = [];
        $themes = Db::name('theme_templates')->where('status', 1)->select();
        
        if ($id > 0) {
            $userTheme = Db::name('user_themes')->find($id);
            if ($userTheme) {
                $userTheme['custom_config'] = json_decode($userTheme['custom_config'], true);
                $user = Db::name('user')->find($userTheme['user_id']);
                $userTheme['username'] = $user['username'];
                $userTheme['nickname'] = $user['nickname'];
            }
        }

        View::assign('user_theme', $userTheme);
        View::assign('themes', $themes);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/theme_user_theme_edit');
    }

    // 保存用户主题
    public function saveUserTheme()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $user_id = Request::param('user_id', 0);
            $theme_id = Request::param('theme_id', 0);
            $custom_config = Request::param('custom_config', '{}');
            
            try {
                // 验证必填字段
                if (empty($user_id) || empty($theme_id)) {
                    return json(['code' => 400, 'msg' => '参数错误']);
                }
                
                // 检查用户是否存在
                $user = Db::name('user')->find($user_id);
                if (!$user) {
                    return json(['code' => 400, 'msg' => '用户不存在']);
                }
                
                // 检查主题是否存在并启用
                $theme = Db::name('theme_templates')->find($theme_id);
                if (!$theme || $theme['status'] == 0) {
                    return json(['code' => 400, 'msg' => '主题不存在或已禁用']);
                }
                
                // 验证JSON格式
                $customConfigArray = json_decode($custom_config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '自定义配置数据格式错误']);
                }
                
                $data = [
                    'user_id' => $user_id,
                    'theme_id' => $theme_id,
                    'custom_config' => $custom_config,
                    'update_time' => time()
                ];
                
                if ($id > 0) {
                    // 更新用户主题
                    Db::name('user_themes')->where('id', $id)->update($data);
                    $msg = '用户主题更新成功';
                } else {
                    // 创建用户主题
                    $existing = Db::name('user_themes')->where('user_id', $user_id)->find();
                    if ($existing) {
                        return json(['code' => 400, 'msg' => '用户已设置主题']);
                    }
                    $data['create_time'] = time();
                    Db::name('user_themes')->insert($data);
                    $msg = '用户主题设置成功';
                }
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => $id > 0 ? '更新用户主题' : '设置用户主题',
                    'content' => '用户ID: ' . $user_id . ', 用户名: ' . $user['username'] . ', 主题: ' . $theme['name'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => $msg]);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 删除用户主题
    public function deleteUserTheme()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            
            if (empty($id)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                // 删除用户主题
                $userTheme = Db::name('user_themes')->find($id);
                $user = Db::name('user')->find($userTheme['user_id']);
                Db::name('user_themes')->where('id', $id)->delete();
                
                // 记录操作日志
                Db::name('admin_log')->insert([
                    'admin_id' => Session::get('admin_id'),
                    'username' => Session::get('admin_name'),
                    'action' => '删除用户主题',
                    'content' => '用户ID: ' . $userTheme['user_id'] . ', 用户名: ' . $user['username'],
                    'ip' => Request::ip(),
                    'create_time' => time()
                ]);
                
                return json(['code' => 200, 'msg' => '用户主题删除成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }
}
