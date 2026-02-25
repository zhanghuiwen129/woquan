<?php
declare (strict_types = 1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;

class Online extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $deviceType = Request::param('device_type', '');

        try {
            $currentTime = time();
            
            $query = Db::name('sessions')->alias('s')
                ->leftJoin('user u', 's.user_id = u.id')
                ->where('s.expire_time', '>', $currentTime);

            if ($keyword) {
                $escapedKeyword = Db::escape($keyword);
                $query->where('u.nickname', 'like', '%' . $escapedKeyword . '%')
                    ->whereOr('u.username', 'like', '%' . $escapedKeyword . '%');
            }

            if ($deviceType) {
                $query->where('s.device_type', $deviceType);
            }

            $sessions = $query->field('s.*, u.nickname, u.username, u.avatar')
                ->order('s.create_time', 'desc')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);

            View::assign([
                'sessions' => $sessions,
                'keyword' => $keyword,
                'device_type' => $deviceType,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);

            return View::fetch('admin/online/index');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . ' Line: ' . $e->getLine();
        }
    }

    public function statistics()
    {
        try {
            $currentTime = time();
            
            $totalOnline = Db::name('sessions')
                ->where('expire_time', '>', $currentTime)
                ->count();

            $todayVisits = Db::name('sessions')
                ->whereTime('create_time', 'today')
                ->count();

            $deviceStats = Db::name('sessions')
                ->where('expire_time', '>', $currentTime)
                ->group('device_type')
                ->column('COUNT(*) as count', 'device_type');

            $deviceTypes = [
                'mobile' => '手机',
                'tablet' => '平板',
                'desktop' => '电脑',
                'other' => '其他'
            ];

            $deviceData = [];
            foreach ($deviceTypes as $key => $label) {
                $deviceData[$key] = [
                    'label' => $label,
                    'count' => 0
                ];
            }

            foreach ($deviceStats as $stat) {
                $type = $stat['device_type'] ?? 'other';
                if (isset($deviceData[$type])) {
                    $deviceData[$type]['count'] = $stat['count'];
                }
            }

            View::assign([
                'total_online' => $totalOnline,
                'today_visits' => $todayVisits,
                'device_data' => $deviceData,
                'admin_name' => Session::get('admin_name', '管理员')
            ]);

            return View::fetch('admin/online/statistics');
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage() . ' Line: ' . $e->getLine();
        }
    }

    public function kickOut()
    {
        $id = Request::param('id/d');

        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::name('sessions')->where('id', $id)->delete();
            return json(['code' => 200, 'msg' => '踢出成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
