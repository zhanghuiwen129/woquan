<?php

namespace app\controller;

use think\facade\Db;
use think\facade\Request;

class Emojis extends BaseFrontendController
{
    /**
     * 获取表情列表
     */
    public function getEmojiList()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $category = input('category', 'all');

        try {
            $emojiList = [];

            if ($category === 'all' || $category === 'default') {
                $defaultEmojis = $this->getDefaultEmojis();
                $emojiList['default'] = $defaultEmojis;
            }

            if ($category === 'all' || $category === 'custom') {
                $customEmojis = $this->getCustomEmojis($userId);
                $emojiList['custom'] = $customEmojis;
            }

            if ($category === 'all' || $category === 'recent') {
                $recentEmojis = $this->getRecentEmojis($userId);
                $emojiList['recent'] = $recentEmojis;
            }

            return $this->success($emojiList, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败，请稍后重试');
        }
    }

    /**
     * 获取默认表情
     */
    private function getDefaultEmojis()
    {
        $emojiDir = public_path() . 'static/emojis/default/';
        $emojis = [];

        if (is_dir($emojiDir)) {
            $files = scandir($emojiDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $emojis[] = [
                        'id' => 'default_' . pathinfo($file, PATHINFO_FILENAME),
                        'name' => pathinfo($file, PATHINFO_FILENAME),
                        'url' => '/static/emojis/default/' . $file,
                        'type' => 'default'
                    ];
                }
            }
        }

        return $emojis;
    }

    /**
     * 获取自定义表情
     */
    private function getCustomEmojis($userId)
    {
        $customEmojis = Db::name('emojis')
            ->where('user_id', $userId)
            ->where('type', 'custom')
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        return $customEmojis;
    }

    /**
     * 获取最近使用的表情
     */
    private function getRecentEmojis($userId)
    {
        $recentEmojis = Db::name('emoji_usage')
            ->alias('eu')
            ->leftJoin('emojis e', 'eu.emoji_id = e.id')
            ->where('eu.user_id', $userId)
            ->order('eu.use_time', 'desc')
            ->limit(20)
            ->field('e.*')
            ->select()
            ->toArray();

        return $recentEmojis;
    }

    /**
     * 上传自定义表情
     */
    public function uploadEmoji()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));

        if (empty($userId)) {
            return $this->unauthorized();
        }

        $file = request()->file('emoji');

        if (!$file) {
            return $this->badRequest('请选择表情图片');
        }

        try {
            $name = input('name', '');
            $category = input('category', 'custom');

            if (empty($name)) {
                $name = pathinfo($file->getOriginalName(), PATHINFO_FILENAME);
            }

            $uploadDir = public_path() . 'static/emojis/custom/';
            $fileName = uniqid('emoji_') . '.' . $file->extension();

            $file->move($uploadDir, $fileName);

            $emojiId = Db::name('emojis')->insertGetId([
                'user_id' => $userId,
                'name' => $name,
                'url' => '/static/emojis/custom/' . $fileName,
                'type' => $category,
                'create_time' => time()
            ]);

            return $this->success([
                'id' => $emojiId,
                'name' => $name,
                'url' => '/static/emojis/custom/' . $fileName,
                'type' => $category
            ], '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除自定义表情
     */
    public function deleteEmoji()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $emojiId = (int)input('emoji_id');

        if (empty($userId)) {
            return $this->unauthorized();
        }

        try {
            $emoji = Db::name('emojis')
                ->where('id', $emojiId)
                ->where('user_id', $userId)
                ->find();

            if (!$emoji) {
                return $this->notFound('表情不存在');
            }

            if ($emoji['type'] === 'default') {
                return $this->error('不能删除默认表情', 403);
            }

            $filePath = public_path() . ltrim($emoji['url'], '/');
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            Db::name('emojis')->where('id', $emojiId)->delete();
            Db::name('emoji_usage')->where('emoji_id', $emojiId)->delete();

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 记录表情使用
     */
    public function recordUsage()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $emojiId = (int)input('emoji_id');

        if (empty($userId) || empty($emojiId)) {
            return $this->badRequest('缺少参数');
        }

        try {
            $exists = Db::name('emoji_usage')
                ->where('user_id', $userId)
                ->where('emoji_id', $emojiId)
                ->find();

            if ($exists) {
                Db::name('emoji_usage')
                    ->where('user_id', $userId)
                    ->where('emoji_id', $emojiId)
                    ->update(['use_time' => time()]);
            } else {
                Db::name('emoji_usage')->insert([
                    'user_id' => $userId,
                    'emoji_id' => $emojiId,
                    'use_time' => time()
                ]);
            }

            return $this->success(null, '记录成功');
        } catch (\Exception $e) {
            return $this->error('记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 搜索表情
     */
    public function searchEmojis()
    {
        $userId = (int)(session('user_id') ?: cookie('user_id'));
        $keyword = input('keyword', '');

        if (empty($keyword)) {
            return $this->badRequest('请输入搜索关键词');
        }

        try {
            $results = Db::name('emojis')
                ->where('user_id', $userId)
                ->where('name', 'like', '%' . $keyword . '%')
                ->limit(20)
                ->select()
                ->toArray();

            return $this->success($results, '搜索成功');
        } catch (\Exception $e) {
            return $this->error('搜索失败: ' . $e->getMessage());
        }
    }
}
