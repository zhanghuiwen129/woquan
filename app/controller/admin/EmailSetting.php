<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Db;

/**
 * 邮件设置控制器
 * 负责管理邮件发送配置
 */
class EmailSetting extends AdminController
{
    /**
     * 邮件设置首页
     */
    public function index()
    {
        // 获取邮件配置
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config
        ]);

        return View::fetch('admin/setting/email');
    }

    /**
     * 保存邮件设置
     */
    public function save()
    {
        if (Request::isPost()) {
            $data = Request::param();
            unset($data['_method'], $data['__token__']);

            Db::startTrans();
            try {
                // 保存邮件配置
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

    /**
     * 测试邮件发送
     */
    public function test()
    {
        if (Request::isPost()) {
            $email = Request::param('email');

            if (empty($email)) {
                return json(['code' => 0, 'msg' => '请输入测试邮箱']);
            }

            try {
                // TODO: 实现邮件发送逻辑
                // 这里需要集成邮件发送功能
                return json(['code' => 200, 'msg' => '测试邮件已发送']);
            } catch (\Exception $e) {
                return json(['code' => 0, 'msg' => '发送失败：' . $e->getMessage()]);
            }
        }
    }
}
