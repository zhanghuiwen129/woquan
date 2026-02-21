<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Db;

/**
 * 内容设置控制器
 * 负责管理内容发布配置、动态发布规则等
 */
class ContentSetting extends AdminController
{
    /**
     * 内容设置首页
     */
    public function index()
    {
        // 获取内容配置
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config
        ]);

        return View::fetch('admin/setting/content');
    }

    /**
     * 保存内容设置
     */
    public function save()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                // 保存内容配置
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
                                'create_time' => time(),
                                'update_time' => time()
                            ]);
                    }
                }

                Db::commit();
                return json(['code' => 200, 'msg' => '保存成功']);
            } catch (\Exception $e) {
                Db::rollback();
                return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        }
    }
}
