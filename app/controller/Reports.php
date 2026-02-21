<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\Session;

class Reports extends BaseFrontendController
{
    /**
     * 初始化方法,确保 session 正确启动
     */
    protected function initialize()
    {
        parent::initialize();
        $userId = Session::get('user_id') ?: cookie('user_id');
        if ($userId && !Session::has('user_id')) {
            Session::set('user_id', $userId);
        }
    }

    /**
     * 添加举报
     */
    public function add()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?: cookie('user_id');
            if (!$userId) {
                return json([
                    'code' => 401,
                    'msg' => '请先登录'
                ]);
            }

            $momentId = input('moment_id', 0);
            $reason = input('reason', '');
            $detail = input('detail', '');

            if (!$momentId) {
                return json([
                    'code' => 400,
                    'msg' => '参数错误'
                ]);
            }

            if (!$reason) {
                return json([
                    'code' => 400,
                    'msg' => '请选择举报原因'
                ]);
            }

            $moment = Db::name('moments')->where('id', $momentId)->find();
            if (!$moment) {
                return json([
                    'code' => 404,
                    'msg' => '动态不存在'
                ]);
            }

            $exists = Db::name('reports')
                ->where('reporter_id', $userId)
                ->where('moment_id', $momentId)
                ->find();

            if ($exists) {
                return json([
                    'code' => 400,
                    'msg' => '您已经举报过该动态'
                ]);
            }

            Db::name('reports')->insert([
                'reporter_id' => $userId,
                'moment_id' => $momentId,
                'reported_user_id' => $moment['user_id'],
                'type' => 1,
                'reason' => $reason,
                'evidence_urls' => $detail ? json_encode([$detail]) : null,
                'status' => 0,
                'create_time' => time()
            ]);

            return json([
                'code' => 200,
                'msg' => '举报成功，感谢您的反馈'
            ]);
        } catch (\Exception $e) {
            return $this->error('举报失败: ' . $e->getMessage());
        }
    }
}
