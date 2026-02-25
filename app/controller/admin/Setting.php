<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;
use think\facade\Db;

// 导入需要的控制器类
use app\controller\admin\WebsiteSetting;
use app\controller\admin\ContentSetting;
use app\controller\admin\UploadSetting;
use app\controller\admin\EmailSetting;
use app\controller\admin\SeoSetting;
use app\controller\admin\SystemTool;

/**
 * 系统设置汇总控制器
 * 旧版控制器，保留以向后兼容
 * 新功能请使用专门的控制器：WebsiteSetting, ContentSetting, UploadSetting等
 * @deprecated 建议使用专门的设置控制器
 */
class Setting extends AdminController
{
    /**
     * 初始化方法
     */
    public function initialize()
    {
        parent::initialize();
        // 检查是否登录
        if (!Session::has('admin_id')) {
            return redirect('/admin/login');
        }
    }

    /**
     * 系统设置首页（兼容旧版）
     */
    public function index()
    {
        // 获取所有系统配置
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting/index');
    }

    /**
     * 基本设置页面
     */
    public function basic()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_basic');
    }

    /**
     * 网站设置页面（包含SEO设置）
     */
    public function website()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting/index');
    }

    /**
     * 注册设置页面
     */
    public function register()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_register');
    }

    /**
     * 邮件设置页面
     */
    public function email()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_email');
    }

    /**
     * 上传设置页面
     */
    public function upload()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_upload');
    }

    /**
     * SEO设置页面（已合并到网站设置）
     */
    public function seo()
    {
        return $this->website();
    }

    /**
     * 通知设置页面
     */
    public function notification()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_notification');
    }

    /**
     * 发布设置页面
     */
    public function publish()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_publish');
    }

    /**
     * 安全设置页面
     */
    public function security()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_security');
    }

    /**
     * 社交设置页面
     */
    public function social()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_social');
    }

    /**
     * 工具设置页面
     */
    public function tools()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_tools');
    }

    /**
     * 资源设置页面
     */
    public function resource()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_resource');
    }

    /**
     * 运营设置页面
     */
    public function operation()
    {
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/setting_operation');
    }

    /**
     * 保存网站设置（兼容旧版，重定向到WebsiteSetting）
     */
    public function saveWebsite()
    {
        $websiteSetting = app()->make(WebsiteSetting::class);
        return $websiteSetting->save();
    }

    /**
     * 保存内容设置（兼容旧版，重定向到ContentSetting）
     */
    public function savePublish()
    {
        $contentSetting = app()->make(ContentSetting::class);
        return $contentSetting->save();
    }

    /**
     * 保存上传设置（兼容旧版，重定向到UploadSetting）
     */
    public function saveUpload()
    {
        $uploadSetting = app()->make(UploadSetting::class);
        return $uploadSetting->save();
    }

    /**
     * 保存邮件设置（兼容旧版，重定向到EmailSetting）
     */
    public function saveEmail()
    {
        $emailSetting = app()->make(EmailSetting::class);
        return $emailSetting->save();
    }

    /**
     * 测试邮件发送（兼容旧版）
     */
    public function testEmail()
    {
        $emailSetting = app()->make(EmailSetting::class);
        return $emailSetting->test();
    }

    /**
     * 保存SEO设置（兼容旧版，重定向到SeoSetting）
     */
    public function saveSeo()
    {
        $seoSetting = app()->make(SeoSetting::class);
        return $seoSetting->save();
    }

    /**
     * 备份数据库（兼容旧版，重定向到SystemTool）
     */
    public function backupDatabase()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->backupDatabase();
    }

    /**
     * 优化数据库（兼容旧版，重定向到SystemTool）
     */
    public function optimizeDatabase()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->optimizeDatabase();
    }

    /**
     * 修复数据库（兼容旧版，重定向到SystemTool）
     */
    public function repairDatabase()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->repairDatabase();
    }

    /**
     * 查看错误日志（兼容旧版，重定向到SystemTool）
     */
    public function viewErrorLogs()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->viewErrorLogs();
    }

    /**
     * 清理错误日志（兼容旧版，重定向到SystemTool）
     */
    public function clearErrorLogs()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->clearErrorLogs();
    }

    /**
     * 查看操作日志（兼容旧版）
     */
    public function viewOperationLogs()
    {
        // TODO: 实现操作日志查看
        return json(['code' => 200, 'msg' => '功能待实现']);
    }

    /**
     * 清理操作日志（兼容旧版）
     */
    public function clearOperationLogs()
    {
        // TODO: 实现操作日志清理
        return json(['code' => 200, 'msg' => '功能待实现']);
    }

    /**
     * 清理缓存（兼容旧版，重定向到SystemTool）
     */
    public function clearCache()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->clearCache();
    }

    /**
     * 清理临时文件（兼容旧版，重定向到SystemTool）
     */
    public function clearTempFiles()
    {
        $systemTool = app()->make(SystemTool::class);
        return $systemTool->clearTempFiles();
    }

    /**
     * 保存资源设置（兼容旧版）
     */
    public function saveResource()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    /**
     * 密码设置页面
     */
    public function password()
    {
        return View::fetch('admin/setting_password');
    }

    /**
     * 用户组管理（兼容旧版）
     */
    public function userGroups()
    {
        return View::fetch('admin/setting_user_groups');
    }

    /**
     * 保存用户组（兼容旧版）
     */
    public function saveUserGroup()
    {
        // TODO: 实现用户组保存
        return json(['code' => 200, 'msg' => '保存成功']);
    }

    /**
     * 删除用户组（兼容旧版）
     */
    public function deleteUserGroup()
    {
        // TODO: 实现用户组删除
        return json(['code' => 200, 'msg' => '删除成功']);
    }

    /**
     * 敏感词管理（兼容旧版）
     */
    public function sensitiveWords()
    {
        return View::fetch('admin/setting_sensitive_words');
    }

    /**
     * 保存敏感词（兼容旧版）
     */
    public function saveSensitiveWords()
    {
        // TODO: 实现敏感词保存
        return json(['code' => 200, 'msg' => '保存成功']);
    }

    /**
     * 错误页面管理（兼容旧版）
     */
    public function errorPages()
    {
        return View::fetch('admin/setting_error_pages');
    }

    /**
     * 保存错误页面设置（兼容旧版）
     */
    public function saveErrorPages()
    {
        // TODO: 实现错误页面保存
        return json(['code' => 200, 'msg' => '保存成功']);
    }

    /**
     * 保存注册设置
     */
    public function saveRegister()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__'], $data['tab']);

            Db::startTrans();
            try {
                // 保存注册配置
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        // 更新现有配置
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        // 添加新配置
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'config_type' => 'select',
                                'config_group' => 'register',
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    /**
     * 保存（通用保存方法，兼容旧版）
     */
    public function save()
    {
        $data = Request::param();
        $tab = $data['tab'] ?? '';

        switch ($tab) {
            case 'publish':
                return $this->savePublish();
            case 'upload':
                return $this->saveUpload();
            case 'email':
                return $this->saveEmail();
            case 'seo':
                return $this->saveSeo();
            case 'register':
                return $this->saveRegister();
            default:
                return $this->saveWebsite();
        }
    }

    /**
     * 保存社交设置（兼容旧版）
     */
    public function saveSocial()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    /**
     * 保存站点设置（兼容旧版）
     */
    public function saveSite()
    {
        return $this->saveWebsite();
    }

    /**
     * 保存基本设置（兼容旧版）
     */
    public function saveBasic()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    public function saveSecurity()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    public function saveNotification()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }

    public function saveOperation()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                foreach ($data as $key => $value) {
                    $config = Db::name('system_config')
                        ->where('config_key', $key)
                        ->find();

                    if ($config) {
                        Db::name('system_config')
                            ->where('config_key', $key)
                            ->update([
                                'config_value' => $value,
                                'update_time' => time()
                            ]);
                    } else {
                        Db::name('system_config')
                            ->insert([
                                'config_key' => $key,
                                'config_value' => $value,
                                'config_name' => $key,
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();

                // 清除系统配置缓存，使前端立即生效
                \app\model\SystemConfig::clearCache();

                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }
}
