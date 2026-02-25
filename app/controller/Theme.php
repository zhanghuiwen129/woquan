<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Themes
{
    // 主题页面
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
            'isLogin' => !empty($userId),
            'current_url' => '/themes'
        ]);
        return View::fetch('index/themes');
    }
    /**
     * 获取主题列表
     */
    public function getThemesList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取主题列表
            $themes = Db::name('themes')
                ->field('id, name, description, preview_image, is_default, create_time, status')
                ->where('status', 1)
                ->order('is_default', 'desc')
                ->order('create_time', 'desc')
                ->select();

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 如果用户已登录，获取用户当前使用的主题
            $currentTheme = null;
            if ($currentUserId) {
                $userTheme = Db::name('user_themes')
                    ->where('user_id', $currentUserId)
                    ->find();

                if ($userTheme) {
                    $currentTheme = $userTheme['theme_id'];
                }
            }

            // 如果没有用户主题，获取默认主题
            if (!$currentTheme) {
                $defaultTheme = Db::name('themes')
                    ->where('is_default', 1)
                    ->where('status', 1)
                    ->find();

                if ($defaultTheme) {
                    $currentTheme = $defaultTheme['id'];
                } else if (!empty($themes)) {
                    // 如果没有默认主题，使用第一个主题
                    $currentTheme = $themes[0]['id'];
                }
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $themes,
                    'current_theme' => $currentTheme
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取主题列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前用户使用的主题
     */
    public function getCurrentTheme()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 初始化主题信息
            $themeInfo = null;

            // 如果用户已登录，获取用户当前使用的主题
            if ($currentUserId) {
                $userTheme = Db::name('user_themes')
                    ->where('user_id', $currentUserId)
                    ->find();

                if ($userTheme) {
                    $themeInfo = Db::name('themes')
                        ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                        ->where('id', $userTheme['theme_id'])
                        ->where('status', 1)
                        ->find();
                }
            }

            // 如果没有用户主题，获取默认主题
            if (!$themeInfo) {
                $themeInfo = Db::name('themes')
                    ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                    ->where('is_default', 1)
                    ->where('status', 1)
                    ->find();

                // 如果没有默认主题，使用第一个主题
                if (!$themeInfo) {
                    $themeInfo = Db::name('themes')
                        ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                        ->where('status', 1)
                        ->order('create_time', 'desc')
                        ->find();
                }
            }

            if (!$themeInfo) {
                return json(['code' => 404, 'msg' => '主题不存在']);
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $themeInfo
            ]);
        } catch (\Exception $e) {
            return $this->error('获取当前主题失败: ' . $e->getMessage());
        }
    }

    /**
     * 设置用户主题
     */
    public function setUserTheme()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            if (!$currentUserId) {
                return json(['code' => 401, 'msg' => '未登录']);
            }

            // 获取请求参数
            $themeId = input('theme_id') ?: input('id');

            if (!$themeId) {
                return json(['code' => 400, 'msg' => '主题ID不能为空']);
            }

            // 检查主题是否存在
            $theme = Db::name('themes')
                ->where('id', $themeId)
                ->where('status', 1)
                ->find();

            if (!$theme) {
                return json(['code' => 404, 'msg' => '主题不存在或已被禁用']);
            }

            // 检查用户是否已设置过主题
            $userTheme = Db::name('user_themes')
                ->where('user_id', $currentUserId)
                ->find();

            if ($userTheme) {
                // 更新主题
                Db::name('user_themes')
                    ->where('user_id', $currentUserId)
                    ->update([
                        'theme_id' => $themeId,
                        'update_time' => time()
                    ]);
            } else {
                // 添加主题设置
                Db::name('user_themes')->insert([
                    'user_id' => $currentUserId,
                    'theme_id' => $themeId,
                    'create_time' => time(),
                    'update_time' => time()
                ]);
            }

            return json(['code' => 200, 'msg' => '设置主题成功', 'data' => ['theme_id' => $themeId]]);
        } catch (\Exception $e) {
            return $this->error('设置主题失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取主题详情
     */
    public function getThemeDetail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $themeId = input('theme_id') ?: input('id');

            if (!$themeId) {
                return json(['code' => 400, 'msg' => '主题ID不能为空']);
            }

            // 获取主题详情
            $theme = Db::name('themes')
                ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                ->where('id', $themeId)
                ->where('status', 1)
                ->find();

            if (!$theme) {
                return json(['code' => 404, 'msg' => '主题不存在或已被禁用']);
            }

            return json(['code' => 200, 'msg' => 'success', 'data' => $theme]);
        } catch (\Exception $e) {
            return $this->error('获取主题详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取默认主题
     */
    public function getDefaultTheme()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取默认主题
            $defaultTheme = Db::name('themes')
                ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                ->where('is_default', 1)
                ->where('status', 1)
                ->find();

            // 如果没有默认主题，使用第一个主题
            if (!$defaultTheme) {
                $defaultTheme = Db::name('themes')
                    ->field('id, name, description, preview_image, is_default, css_url, js_url, create_time, status')
                    ->where('status', 1)
                    ->order('create_time', 'desc')
                    ->find();
            }

            if (!$defaultTheme) {
                return json(['code' => 404, 'msg' => '主题不存在']);
            }

            return json(['code' => 200, 'msg' => 'success', 'data' => $defaultTheme]);
        } catch (\Exception $e) {
            return $this->error('获取默认主题失败: ' . $e->getMessage());
        }
    }
}
