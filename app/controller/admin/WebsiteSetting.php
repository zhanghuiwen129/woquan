<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\Request;
use think\facade\View;
use think\facade\Db;

/**
 * 网站设置控制器
 * 负责管理网站基本信息：logo、icon、名称、副标题、版权信息等
 */
class WebsiteSetting extends AdminController
{
    /**
     * 网站设置首页
     */
    public function index()
    {
        // 获取网站配置
        $settings = Db::name('system_config')->select();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['config_key']] = $setting['config_value'];
        }

        View::assign([
            'config' => $config
        ]);

        return View::fetch('admin/setting/website');
    }

    /**
     * 保存网站设置
     */
    public function save()
    {
        try {
            if (Request::isPost()) {
                // 处理Logo文件上传
                $logoPath = '';
                try {
                    // 检查是否有文件被上传
                    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                        $logoFile = Request::file('logo_file');
                        if ($logoFile && $logoFile->isValid()) {
                            $uploadPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo';
                            if (!is_dir($uploadPath)) {
                                mkdir($uploadPath, 0755, true);
                            }

                            $extension = $logoFile->extension();
                            $filename = 'logo_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
                            $logoFile->move($uploadPath, $filename);
                            $logoPath = '/uploads/logo/' . $filename;
                        }
                    }
                } catch (\Exception $e) {
                    return json(['code' => 0, 'msg' => 'Logo上传失败：' . $e->getMessage()]);
                }

                // 处理Icon文件上传
                $iconPath = '';
                try {
                    // 检查是否有文件被上传
                    if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                        $iconFile = Request::file('icon_file');
                        if ($iconFile && $iconFile->isValid()) {
                            $uploadPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'icon';
                            if (!is_dir($uploadPath)) {
                                mkdir($uploadPath, 0755, true);
                            }

                            $extension = $iconFile->extension();
                            $filename = 'icon_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
                            $iconFile->move($uploadPath, $filename);
                            $iconPath = '/uploads/icon/' . $filename;
                        }
                    }
                } catch (\Exception $e) {
                    return json(['code' => 0, 'msg' => 'Icon上传失败：' . $e->getMessage()]);
                }

                $data = Request::param();
                unset($data['_method'], $data['__token__'], $data['logo_file'], $data['icon_file']);

                // 如果上传了新logo或icon，覆盖原有的路径
                if ($logoPath) {
                    $data['site_logo'] = $logoPath;
                }
                if ($iconPath) {
                    $data['site_favicon'] = $iconPath;
                }

                Db::startTrans();
                try {
                    // 保存网站配置
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

                    // 清除系统配置缓存，使前端立即生效
                    \app\model\SystemConfig::clearCache();

                    $result = ['code' => 200, 'msg' => '保存成功'];
                    if ($logoPath || $iconPath) {
                        $result['data'] = [];
                        if ($logoPath) {
                            $result['data']['site_logo'] = $logoPath;
                        }
                        if ($iconPath) {
                            $result['data']['site_favicon'] = $iconPath;
                        }
                    }
                    return json($result);
                } catch (\Exception $e) {
                    Db::rollback();
                    return json(['code' => 0, 'msg' => '保存失败：' . $e->getMessage()]);
                }
            } else {
                return json(['code' => 0, 'msg' => '非法请求']);
            }
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => '系统错误：' . $e->getMessage()]);
        }
    }
}
