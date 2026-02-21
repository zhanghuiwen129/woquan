<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;
use think\facade\Response;

/**
 * 风控管理控制器
 */
class RiskControl extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 风控管理首页
    public function index()
    {
        View::assign([
            'admin_name' => Session::get('admin_name')
        ]);
        return View::fetch('admin/riskcontrol_index');
    }

    // 内容风控自动化配置
    public function contentRiskConfig()
    {
        // 获取现有配置
        $config = Db::name('risk_control_config')->where('type', 'content')->find();
        
        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name')
        ]);
        
        return View::fetch('admin/riskcontrol_content_config');
    }

    // 保存内容风控配置
    public function saveContentRiskConfig()
    {
        if (Request::isPost()) {
            $params = Request::param();
            
            try {
                $data = [
                    'type' => 'content',
                    'config' => json_encode($params),
                    'update_time' => time()
                ];
                
                // 检查是否已存在配置
                $existing = Db::name('risk_control_config')->where('type', 'content')->find();
                if ($existing) {
                    Db::name('risk_control_config')->where('id', $existing['id'])->update($data);
                } else {
                    $data['create_time'] = time();
                    Db::name('risk_control_config')->insert($data);
                }
                
                return json(['code' => 200, 'msg' => '配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '配置保存失败：' . $e->getMessage()]);
            }
        }
    }

    // 账号风控基础配置
    public function accountRiskConfig()
    {
        // 获取现有配置
        $config = Db::name('risk_control_config')->where('type', 'account')->find();
        
        View::assign([
            'config' => $config,
            'admin_name' => Session::get('admin_name')
        ]);
        
        return View::fetch('admin/riskcontrol_account_config');
    }

    // 保存账号风控配置
    public function saveAccountRiskConfig()
    {
        if (Request::isPost()) {
            $params = Request::param();
            
            try {
                $data = [
                    'type' => 'account',
                    'config' => json_encode($params),
                    'update_time' => time()
                ];
                
                // 检查是否已存在配置
                $existing = Db::name('risk_control_config')->where('type', 'account')->find();
                if ($existing) {
                    Db::name('risk_control_config')->where('id', $existing['id'])->update($data);
                } else {
                    $data['create_time'] = time();
                    Db::name('risk_control_config')->insert($data);
                }
                
                return json(['code' => 200, 'msg' => '配置保存成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '配置保存失败：' . $e->getMessage()]);
            }
        }
    }

    // 违规内容列表
    public function violatingContent()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $type = Request::param('type', '');
        $status = Request::param('status', '');
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($type) {
            $where[] = ['type', '=', $type];
        }
        if ($status !== '') {
            $where[] = ['status', '=', $status];
        }
        if ($keyword) {
            $where[] = ['content', 'like', "%{$keyword}%"];
        }
        
        $contents = Db::name('violating_contents')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'contents' => $contents,
            'type' => $type,
            'status' => $status,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name')
        ]);
        
        return View::fetch('admin/riskcontrol_violating_content');
    }

    // 处理违规内容
    public function handleViolatingContent()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $action = Request::param('action', '');
            
            if (empty($id) || empty($action)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                $content = Db::name('violating_contents')->where('id', $id)->find();
                if (!$content) {
                    return json(['code' => 404, 'msg' => '违规内容不存在']);
                }
                
                // 根据不同操作处理
                if ($action == 'delete') {
                    // 删除违规内容
                    Db::name('violating_contents')->where('id', $id)->delete();
                    // 同时删除原始内容
                    if ($content['type'] == 'moment') {
                        Db::name('moments')->where('id', $content['target_id'])->delete();
                    } elseif ($content['type'] == 'comment') {
                        Db::name('comments')->where('id', $content['target_id'])->delete();
                    }
                } elseif ($action == 'ignore') {
                    // 忽略违规内容
                    Db::name('violating_contents')->where('id', $id)->update([
                        'status' => 2,
                        'update_time' => time()
                    ]);
                }
                
                return json(['code' => 200, 'msg' => '处理成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '处理失败：' . $e->getMessage()]);
            }
        }
    }

    // 违规账号列表
    public function violatingAccounts()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $status = Request::param('status', '');
        $keyword = Request::param('keyword', '');
        
        $where = [];
        if ($status !== '') {
            $where[] = ['status', '=', $status];
        }
        if ($keyword) {
            $where[] = ['username|nickname', 'like', "%{$keyword}%"];
        }
        
        $accounts = Db::name('violating_accounts')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'accounts' => $accounts,
            'status' => $status,
            'keyword' => $keyword,
            'admin_name' => Session::get('admin_name')
        ]);
        
        return View::fetch('admin/riskcontrol_violating_account');
    }

    // 处理违规账号
    public function handleViolatingAccount()
    {
        if (Request::isPost()) {
            $id = Request::param('id', 0);
            $action = Request::param('action', '');
            $days = Request::param('days', 0);
            
            if (empty($id) || empty($action)) {
                return json(['code' => 400, 'msg' => '参数错误']);
            }
            
            try {
                $account = Db::name('violating_accounts')->where('id', $id)->find();
                if (!$account) {
                    return json(['code' => 404, 'msg' => '违规账号不存在']);
                }
                
                // 根据不同操作处理
                if ($action == 'warn') {
                    // 警告处理
                    Db::name('violating_accounts')->where('id', $id)->update([
                        'status' => 1,
                        'update_time' => time()
                    ]);
                } elseif ($action == 'ban') {
                    // 封禁账号
                    if (empty($days)) {
                        return json(['code' => 400, 'msg' => '请指定封禁天数']);
                    }
                    
                    Db::name('violating_accounts')->where('id', $id)->update([
                        'status' => 2,
                        'ban_end_time' => time() + ($days * 24 * 3600),
                        'update_time' => time()
                    ]);
                    
                    // 更新用户状态为封禁
                    Db::name('user')->where('id', $account['user_id'])->update([
                        'status' => 0,
                        'ban_end_time' => time() + ($days * 24 * 3600)
                    ]);
                } elseif ($action == 'unban') {
                    // 解除封禁
                    Db::name('violating_accounts')->where('id', $id)->update([
                        'status' => 3,
                        'update_time' => time()
                    ]);
                    
                    // 更新用户状态为正常
                    Db::name('user')->where('id', $account['user_id'])->update([
                        'status' => 1,
                        'ban_end_time' => 0
                    ]);
                } elseif ($action == 'delete') {
                    // 删除账号
                    Db::name('violating_accounts')->where('id', $id)->delete();
                    Db::name('user')->where('id', $account['user_id'])->delete();
                }
                
                return json(['code' => 200, 'msg' => '处理成功']);
            } catch (\Exception $e) {
                return json(['code' => 500, 'msg' => '处理失败：' . $e->getMessage()]);
            }
        }
    }

    // 风控日志查询
    public function riskLogs()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $type = Request::param('type', '');
        $keyword = Request::param('keyword', '');
        $startDate = Request::param('start_date', '');
        $endDate = Request::param('end_date', '');
        
        $where = [];
        if ($type) {
            $where[] = ['type', '=', $type];
        }
        if ($keyword) {
            $where[] = ['content', 'like', "%{$keyword}%"];
        }
        if ($startDate) {
            $where[] = ['create_time', '>=', strtotime($startDate)];
        }
        if ($endDate) {
            $where[] = ['create_time', '<=', strtotime($endDate) + 86399];
        }
        
        $logs = Db::name('risk_logs')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'logs' => $logs,
            'type' => $type,
            'keyword' => $keyword,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'admin_name' => Session::get('admin_name')
        ]);
        
        return View::fetch('admin/riskcontrol_logs');
    }

    // 导出风控日志
    public function exportRiskLogs()
    {
        $startDate = Request::param('start_date', '');
        $endDate = Request::param('end_date', '');
        $type = Request::param('type', '');
        
        $where = [];
        if ($startDate) {
            $where[] = ['create_time', '>=', strtotime($startDate)];
        }
        if ($endDate) {
            $where[] = ['create_time', '<=', strtotime($endDate) + 86399];
        }
        if ($type) {
            $where[] = ['type', '=', $type];
        }
        
        $logs = Db::name('risk_logs')
            ->where($where)
            ->order('create_time desc')
            ->select();
        
        // 生成CSV内容
        $csvContent = "日志时间,类型,内容,IP地址\n";
        foreach ($logs as $log) {
            $csvContent .= "".date('Y-m-d H:i:s', $log['create_time']) . ",";
            $csvContent .= "".($log['type'] == 'content' ? '内容风控' : '账号风控') . ",";
            $csvContent .= "".addslashes($log['content']) . ",";
            $csvContent .= "".$log['ip'] . "\n";
        }
        
        // 输出CSV文件
        return Response::create($csvContent, 'csv')
            ->header('Content-Disposition', 'attachment;filename="risk_logs_' . date('YmdHis') . '.csv"')
            ->header('Content-Type', 'text/csv;charset=utf-8');
    }
}
