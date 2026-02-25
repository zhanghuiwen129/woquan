<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 前端配置/个性化管理控制器
 */
class FrontendConfig extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 前端配置/个性化管理首页
    public function index()
    {
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/frontendconfig_index');
    }

    // 前端页面基础配置
    public function pageConfig()
    {
        // 前端页面配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'frontend_page_config')->value('config_value');
        $pageConfig = $config ? json_decode($config, true) : [];
        
        View::assign('page_config', $pageConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/frontendconfig_page');
    }

    // 保存前端页面基础配置
    public function savePageConfig()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'frontend_page_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'frontend_page_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'frontend_page_config',
                        'config_value' => $config,
                        'config_name' => '前端页面基础配置',
                        'config_type' => 'textarea',
                        'config_group' => 'frontend',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '前端页面配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 前端样式基础配置
    public function styleConfig()
    {
        // 前端样式配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'frontend_style_config')->value('config_value');
        $styleConfig = $config ? json_decode($config, true) : [];
        
        View::assign('style_config', $styleConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/frontendconfig_style');
    }

    // 保存前端样式基础配置
    public function saveStyleConfig()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'frontend_style_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'frontend_style_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'frontend_style_config',
                        'config_value' => $config,
                        'config_name' => '前端样式基础配置',
                        'config_type' => 'textarea',
                        'config_group' => 'frontend',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '前端样式配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 个性化推荐基础配置
    public function recommendationConfig()
    {
        // 个性化推荐配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'personalized_recommendation_config')->value('config_value');
        $recommendationConfig = $config ? json_decode($config, true) : [];
        
        View::assign('recommendation_config', $recommendationConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/frontendconfig_recommendation');
    }

    // 保存个性化推荐基础配置
    public function saveRecommendationConfig()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'personalized_recommendation_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'personalized_recommendation_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'personalized_recommendation_config',
                        'config_value' => $config,
                        'config_name' => '个性化推荐基础配置',
                        'config_type' => 'textarea',
                        'config_group' => 'frontend',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '个性化推荐配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 前端导航菜单配置
    public function navConfig()
    {
        // 前端导航菜单配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'frontend_nav_config')->value('config_value');
        $navConfig = $config ? json_decode($config, true) : [];
        
        View::assign('nav_config', $navConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/frontendconfig_nav');
    }

    // 保存前端导航菜单配置
    public function saveNavConfig()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'frontend_nav_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'frontend_nav_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'frontend_nav_config',
                        'config_value' => $config,
                        'config_name' => '前端导航菜单配置',
                        'config_type' => 'textarea',
                        'config_group' => 'frontend',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '前端导航菜单配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }

    // 前端广告位配置
    public function adConfig()
    {
        // 前端广告位配置存储在系统配置表中
        $config = Db::name('system_config')->where('config_key', 'frontend_ad_config')->value('config_value');
        $adConfig = $config ? json_decode($config, true) : [];
        
        View::assign('ad_config', $adConfig);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/frontendconfig_ad');
    }

    // 保存前端广告位配置
    public function saveAdConfig()
    {
        if (Request::isPost()) {
            $config = Request::param('config', '[]');
            
            try {
                // 验证JSON格式
                $configArray = json_decode($config, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return json(['code' => 400, 'msg' => '配置数据格式错误']);
                }
                
                // 保存到系统配置
                $existingConfig = Db::name('system_config')->where('config_key', 'frontend_ad_config')->find();
                if ($existingConfig) {
                    Db::name('system_config')->where('config_key', 'frontend_ad_config')->update([
                        'config_value' => $config,
                        'update_time' => time()
                    ]);
                } else {
                    Db::name('system_config')->insert([
                        'config_key' => 'frontend_ad_config',
                        'config_value' => $config,
                        'config_name' => '前端广告位配置',
                        'config_type' => 'textarea',
                        'config_group' => 'frontend',
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '前端广告位配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }
    }
}
