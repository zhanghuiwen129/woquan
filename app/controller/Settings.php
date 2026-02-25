<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;
use think\facade\Cookie;

class Settings extends BaseFrontendController
{
    // 设置页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        // 使用BaseFrontendController设置的全局变量
        return View::fetch('index/settings');
    }
    /**
     * 获取通用设置
     */
    public function getGeneralSettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取用户的通用设置
            $settings = Db::name('user_settings')
                ->where('user_id', $userId)
                ->find();

            // 如果没有设置，使用默认设置
            if (!$settings) {
                $settings = [
                    'user_id' => $userId,
                    'language' => 'zh-CN',
                    'timezone' => 'Asia/Shanghai',
                    'privacy_profile' => 1, // 1公开，2私密
                    'privacy_moments' => 1, // 1公开，2私密
                    'privacy_comments' => 1, // 1所有人可评论，2仅关注的人可评论
                    'show_online_status' => 1,
                    'allow_stranger_message' => 1,
                    'auto_save_draft' => 1,
                    'notification_sound' => 1,
                    'dark_mode' => 0
                ];
            }

            return $this->success($settings, 'success');
        } catch (\Exception $e) {
            return $this->error('获取通用设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新通用设置
     */
    public function updateGeneralSettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取请求参数
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                return $this->badRequest('参数错误');
            }

            // 过滤参数
            $settings = [
                'language' => $data['language'] ?? 'zh-CN',
                'timezone' => $data['timezone'] ?? 'Asia/Shanghai',
                'privacy_profile' => isset($data['privacy_profile']) ? intval($data['privacy_profile']) : 1,
                'privacy_moments' => isset($data['privacy_moments']) ? intval($data['privacy_moments']) : 1,
                'privacy_comments' => isset($data['privacy_comments']) ? intval($data['privacy_comments']) : 1,
                'show_online_status' => isset($data['show_online_status']) ? intval($data['show_online_status']) : 1,
                'allow_stranger_message' => isset($data['allow_stranger_message']) ? intval($data['allow_stranger_message']) : 1,
                'auto_save_draft' => isset($data['auto_save_draft']) ? intval($data['auto_save_draft']) : 1,
                'notification_sound' => isset($data['notification_sound']) ? intval($data['notification_sound']) : 1,
                'dark_mode' => isset($data['dark_mode']) ? intval($data['dark_mode']) : 0
            ];

            // 检查设置是否已存在
            $existing = Db::name('user_settings')
                ->where('user_id', $userId)
                ->find();

            if ($existing) {
                // 更新设置
                Db::name('user_settings')
                    ->where('user_id', $userId)
                    ->update($settings);
            } else {
                // 添加设置
                $settings['user_id'] = $userId;
                Db::name('user_settings')->insert($settings);
            }

            return $this->success($settings, '设置成功');
        } catch (\Exception $e) {
            return $this->error('更新通用设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取隐私设置
     */
    public function getPrivacySettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取用户的隐私设置
            $settings = Db::name('user_settings')
                ->where('user_id', $userId)
                ->field('user_id, privacy_profile, privacy_moments, privacy_comments, show_online_status, allow_stranger_message')
                ->find();

            // 如果没有设置，使用默认设置
            if (!$settings) {
                $settings = [
                    'user_id' => $userId,
                    'privacy_profile' => 1, // 1公开，2私密
                    'privacy_moments' => 1, // 1公开，2私密
                    'privacy_comments' => 1, // 1所有人可评论，2仅关注的人可评论
                    'show_online_status' => 1,
                    'allow_stranger_message' => 1
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return $this->error('获取隐私设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新隐私设置
     */
    public function updatePrivacySettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                return $this->badRequest('参数错误');
            }

            // 过滤参数
            $privacySettings = [
                'privacy_profile' => isset($data['privacy_profile']) ? intval($data['privacy_profile']) : 1,
                'privacy_moments' => isset($data['privacy_moments']) ? intval($data['privacy_moments']) : 1,
                'privacy_comments' => isset($data['privacy_comments']) ? intval($data['privacy_comments']) : 1,
                'show_online_status' => isset($data['show_online_status']) ? intval($data['show_online_status']) : 1,
                'allow_stranger_message' => isset($data['allow_stranger_message']) ? intval($data['allow_stranger_message']) : 1
            ];

            // 检查设置是否已存在
            $existing = Db::name('user_settings')
                ->where('user_id', $userId)
                ->find();

            if ($existing) {
                // 更新设置
                Db::name('user_settings')
                    ->where('user_id', $userId)
                    ->update($privacySettings);
            } else {
                // 添加设置
                $privacySettings['user_id'] = $userId;
                // 添加默认设置
                $privacySettings = array_merge([
                    'language' => 'zh-CN',
                    'timezone' => 'Asia/Shanghai',
                    'auto_save_draft' => 1,
                    'notification_sound' => 1,
                    'dark_mode' => 0
                ], $privacySettings);
                Db::name('user_settings')->insert($privacySettings);
            }

            return json([
                'code' => 200,
                'msg' => '隐私设置更新成功',
                'data' => $privacySettings
            ]);
        } catch (\Exception $e) {
            return $this->error('更新隐私设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取显示设置
     */
    public function getDisplaySettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            // 获取用户的显示设置
            $settings = Db::name('user_settings')
                ->where('user_id', $userId)
                ->field('user_id, language, timezone, dark_mode, notification_sound')
                ->find();

            // 如果没有设置，使用默认设置
            if (!$settings) {
                $settings = [
                    'user_id' => $userId,
                    'language' => 'zh-CN',
                    'timezone' => 'Asia/Shanghai',
                    'dark_mode' => 0,
                    'notification_sound' => 1
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return $this->error('获取显示设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新显示设置
     */
    public function updateDisplaySettings()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                return $this->badRequest('参数错误');
            }

            // 过滤参数
            $displaySettings = [
                'language' => $data['language'] ?? 'zh-CN',
                'timezone' => $data['timezone'] ?? 'Asia/Shanghai',
                'dark_mode' => isset($data['dark_mode']) ? intval($data['dark_mode']) : 0,
                'notification_sound' => isset($data['notification_sound']) ? intval($data['notification_sound']) : 1
            ];

            // 检查设置是否已存在
            $existing = Db::name('user_settings')
                ->where('user_id', $userId)
                ->find();

            if ($existing) {
                // 更新设置
                Db::name('user_settings')
                    ->where('user_id', $userId)
                    ->update($displaySettings);
            } else {
                // 添加设置
                $displaySettings['user_id'] = $userId;
                // 添加默认设置
                $displaySettings = array_merge([
                    'privacy_profile' => 1,
                    'privacy_moments' => 1,
                    'privacy_comments' => 1,
                    'show_online_status' => 1,
                    'allow_stranger_message' => 1,
                    'auto_save_draft' => 1
                ], $displaySettings);
                Db::name('user_settings')->insert($displaySettings);
            }

            return json([
                'code' => 200,
                'msg' => '显示设置更新成功',
                'data' => $displaySettings
            ]);
        } catch (\Exception $e) {
            return $this->error('更新显示设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取通知设置
     */
    public function getNotificationSettings()
    {
        try {
            // 清除之前的所有输出
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');

            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $settings = Db::name('user_notification_settings')
                ->where('user_id', $userId)
                ->find();

            if (!$settings) {
                $settings = [
                    'email_notification' => 1,
                    'sms_notification' => 0,
                    'push_notification' => 1,
                    'like_notification' => 1,
                    'comment_notification' => 1,
                    'follow_notification' => 1,
                    'message_notification' => 1,
                    'system_notification' => 1,
                    'notification_sound' => 1,
                    'quiet_hours_start' => '22:00',
                    'quiet_hours_end' => '08:00'
                ];
            }

            return $this->success($settings, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取通知设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新通知设置
     */
    public function updateNotificationSettings()
    {
        try {
            // 清除之前的所有输出
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');

            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                return $this->badRequest('参数错误');
            }

            $settings = [
                'email_notification' => isset($data['email_notification']) ? intval($data['email_notification']) : 1,
                'sms_notification' => isset($data['sms_notification']) ? intval($data['sms_notification']) : 0,
                'push_notification' => isset($data['push_notification']) ? intval($data['push_notification']) : 1,
                'like_notification' => isset($data['like_notification']) ? intval($data['like_notification']) : 1,
                'comment_notification' => isset($data['comment_notification']) ? intval($data['comment_notification']) : 1,
                'follow_notification' => isset($data['follow_notification']) ? intval($data['follow_notification']) : 1,
                'message_notification' => isset($data['message_notification']) ? intval($data['message_notification']) : 1,
                'system_notification' => isset($data['system_notification']) ? intval($data['system_notification']) : 1,
                'notification_sound' => isset($data['notification_sound']) ? intval($data['notification_sound']) : 1,
                'quiet_hours_start' => $data['quiet_hours_start'] ?? '22:00',
                'quiet_hours_end' => $data['quiet_hours_end'] ?? '08:00',
                'update_time' => time()
            ];

            $existing = Db::name('user_notification_settings')
                ->where('user_id', $userId)
                ->find();

            if ($existing) {
                Db::name('user_notification_settings')
                    ->where('user_id', $userId)
                    ->update($settings);
            } else {
                $settings['user_id'] = $userId;
                $settings['create_time'] = time();
                Db::name('user_notification_settings')->insert($settings);
            }

            return $this->success($settings, '通知设置更新成功');
        } catch (\Exception $e) {
            return $this->error('更新通知设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取实名认证信息
     */
    public function getRealnameAuth()
    {
        try {
            // 清除之前的所有输出
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');

            $userId = session('user_id') ?: cookie('user_id');
            if (!$userId) {
                return $this->unauthorized();
            }

            $auth = Db::name('user_realname_auth')
                ->where('user_id', $userId)
                ->find();

            if ($auth) {
                unset($auth['id_card']);
            }

            return $this->success($auth, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取实名认证信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 提交实名认证
     */
    public function submitRealnameAuth()
    {
        try {
            // 清除之前的所有输出
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');

            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return $this->unauthorized();
            }

            $request = request();
            $realName = $request->post('real_name', '');
            $idCard = $request->post('id_card', '');
            $idCardFrontFile = $request->file('id_card_front');
            $idCardBackFile = $request->file('id_card_back');
            $handheldIdCardFile = $request->file('handheld_id_card');

            if (!$realName || !$idCard) {
                return $this->badRequest('请填写完整信息');
            }

            if (!$idCardFrontFile || !$idCardBackFile || !$handheldIdCardFile) {
                return $this->badRequest('请上传所有照片');
            }

            if (!preg_match('/^[1-9]\d{5}(18|19|20)\d{2}((0[1-9])|(1[0-2]))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$/', $idCard)) {
                return $this->badRequest('身份证号格式不正确');
            }

            $uploadPath = public_path('uploads' . DIRECTORY_SEPARATOR . 'realname_auth');
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            $idCardFront = $this->uploadImage($idCardFrontFile, $uploadPath);
            $idCardBack = $this->uploadImage($idCardBackFile, $uploadPath);
            $handheldIdCard = $this->uploadImage($handheldIdCardFile, $uploadPath);

            if (!$idCardFront || !$idCardBack || !$handheldIdCard) {
                return $this->error('图片上传失败');
            }

            $existing = Db::name('user_realname_auth')
                ->where('user_id', $userId)
                ->find();

            if ($existing && $existing['status'] == 1) {
                return $this->badRequest('您已通过实名认证');
            }

            $authData = [
                'user_id' => $userId,
                'real_name' => $realName,
                'id_card' => $idCard,
                'id_card_front' => '/uploads/realname_auth/' . $idCardFront,
                'id_card_back' => '/uploads/realname_auth/' . $idCardBack,
                'handheld_id_card' => '/uploads/realname_auth/' . $handheldIdCard,
                'status' => 0,
                'create_time' => time(),
                'update_time' => time()
            ];

            if ($existing) {
                unset($authData['create_time']);
                Db::name('user_realname_auth')
                    ->where('user_id', $userId)
                    ->update($authData);
            } else {
                Db::name('user_realname_auth')->insert($authData);
            }

            return $this->success($authData, '实名认证提交成功，请等待审核');
        } catch (\Exception $e) {
            return $this->error('提交实名认证失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传图片
     */
    private function uploadImage($file, $uploadPath)
    {
        if (!$file) {
            return null;
        }

        $ext = pathinfo($file->getOriginalName(), PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        $filepath = $uploadPath . DIRECTORY_SEPARATOR . $filename;

        $file->move($uploadPath, $filename);

        return $filename;
    }
}
