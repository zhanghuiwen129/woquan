<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\model\Authorization as AuthorizationModel;
use app\model\Software as SoftwareModel;
use app\service\LicenseService;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;

class Authorization extends AdminController
{

    
    // 授权列表
    public function index()
    {
        // 获取所有授权
        $authorizations = \think\facade\Db::name('authorizations')
            ->order('id DESC')
            ->select();

        // 获取所有软件用于显示名称
        $softwareList = \think\facade\Db::name('software')->select();
        $softwareMap = [];
        foreach ($softwareList as $software) {
            $softwareMap[$software['id']] = $software['software_name'];
        }

        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'authorizations' => $authorizations,
            'softwareMap' => $softwareMap,
        ]);

        return View::fetch('admin/authorization/index');
    }
    
    // 授权详情
    public function detail($id)
    {
        // 获取授权详情
        $authorization = AuthorizationModel::getAuthorizationById($id);
        if (!$authorization) {
            return "授权不存在";
        }
        
        // 获取软件信息
        $software = SoftwareModel::getSoftwareById($authorization->software_id);
        
        // 处理功能权限
        if (!empty($authorization->features)) {
            $authorization->features = json_decode($authorization->features, true);
        } else {
            $authorization->features = [];
        }
        
        // 为每个功能权限添加标志位
        $featureList = ['articles', 'comments', 'chat', 'vip', 'storage', 'payment'];
        foreach ($featureList as $feature) {
            $authorization->{$feature . '_checked'} = in_array($feature, $authorization->features);
        }
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'authorization' => $authorization,
            'software' => $software,
        ]);
        
        return View::fetch('admin/authorization/detail');
    }
    
    // 添加授权页面
    public function add()
    {
        // 获取所有软件用于选择
        $softwareList = SoftwareModel::getAllSoftware();
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'softwareList' => $softwareList,
        ]);
        
        return View::fetch('admin/authorization/add');
    }
    
    // 保存授权
    public function save()
    {
        
        // 获取表单数据
        $data = Request::post();
        
        // 验证数据
        if (empty($data['license_number']) || empty($data['software_id'])) {
            return "授权编号和软件ID不能为空";
        }
        
        // 检查授权编号是否已存在
        $existing = AuthorizationModel::getAuthorizationByLicenseNumber($data['license_number']);
        if ($existing) {
            return "该授权编号已存在";
        }
        
        // 处理永久授权
        if (isset($data['is_permanent']) && $data['is_permanent']) {
            $data['end_time'] = 0;
        } else {
            // 确保开始时间早于结束时间
            if (strtotime($data['start_time']) >= strtotime($data['end_time'])) {
                return "开始时间必须早于结束时间";
            }
            
            // 转换为时间戳
            $data['start_time'] = strtotime($data['start_time']);
            $data['end_time'] = strtotime($data['end_time']);
        }
        
        // 处理域名和IP
        $data['domain'] = isset($data['domains']) ? trim($data['domains']) : '';
        $data['server_ip'] = isset($data['ips']) ? trim($data['ips']) : '';
        
        // 处理功能权限
        if (isset($data['features']) && is_array($data['features'])) {
            $data['features'] = json_encode($data['features']);
        } else {
            $data['features'] = null;
        }
        
        // 添加授权
        $authorization = AuthorizationModel::addAuthorization($data);
        if ($authorization) {
            return redirect('/admin/authorization/index.html');
        } else {
            return "添加授权失败";
        }
    }
    
    // 编辑授权页面
    public function edit($id)
    {
        // 获取授权详情
        $authorization = AuthorizationModel::getAuthorizationById($id);
        if (!$authorization) {
            return "授权不存在";
        }
        
        // 获取所有软件
        $softwareList = SoftwareModel::getAllSoftware();
        
        // 处理时间显示
        if ($authorization->end_time != 0) {
            $authorization->start_time = date('Y-m-d', $authorization->start_time);
            $authorization->end_time = date('Y-m-d', $authorization->end_time);
            $authorization->is_permanent = 0;
        } else {
            $authorization->is_permanent = 1;
        }
        
        // 处理功能权限
        if (!empty($authorization->features)) {
            $authorization->features = json_decode($authorization->features, true);
        } else {
            $authorization->features = [];
        }
        
        // 为每个功能权限添加标志位
        $featureList = ['articles', 'comments', 'chat', 'vip', 'storage', 'payment'];
        foreach ($featureList as $feature) {
            $authorization->{$feature . '_checked'} = in_array($feature, $authorization->features);
        }
        
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'authorization' => $authorization,
            'softwareList' => $softwareList,
        ]);
        
        return View::fetch('admin/authorization/edit');
    }
    
    // 更新授权
    public function update()
    {
        
        // 获取表单数据
        $data = Request::post();
        $id = $data['id'];
        
        // 验证数据
        if (empty($id) || empty($data['license_number']) || empty($data['software_id'])) {
            return "授权ID、编号和软件ID不能为空";
        }
        
        // 检查授权编号是否已存在（排除当前授权）
        $existing = AuthorizationModel::getAuthorizationByLicenseNumber($data['license_number']);
        if ($existing && $existing->id != $id) {
            return "该授权编号已存在";
        }
        
        // 处理永久授权
        if (isset($data['is_permanent']) && $data['is_permanent']) {
            $data['end_time'] = 0;
        } else {
            // 确保开始时间早于结束时间
            if (strtotime($data['start_time']) >= strtotime($data['end_time'])) {
                return "开始时间必须早于结束时间";
            }
            
            // 转换为时间戳
            $data['start_time'] = strtotime($data['start_time']);
            $data['end_time'] = strtotime($data['end_time']);
        }
        
        // 处理域名和IP
        $data['domain'] = isset($data['domains']) ? trim($data['domains']) : '';
        $data['server_ip'] = isset($data['ips']) ? trim($data['ips']) : '';
        
        // 处理功能权限
        if (isset($data['features']) && is_array($data['features'])) {
            $data['features'] = json_encode($data['features']);
        } else {
            $data['features'] = null;
        }
        
        // 更新授权
        $result = AuthorizationModel::updateAuthorization($id, $data);
        if ($result) {
            return redirect('/admin/authorization/index.html');
        } else {
            return "更新授权失败";
        }
    }
    
    // 删除授权
    public function delete($id)
    {
        // 删除授权
        $result = AuthorizationModel::deleteAuthorization($id);
        if ($result) {
            return redirect('/admin/authorization/index.html');
        } else {
            return "删除授权失败";
        }
    }

    // 权限管理
    public function permissions()
    {
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
        ]);

        return View::fetch('admin/authorization/permissions');
    }

    // 角色分配
    public function roles()
    {
        // 分配给模板
        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
        ]);

        return View::fetch('admin/authorization/roles');
    }

    // 批量生成授权
    public function batch()
    {
        $softwareList = SoftwareModel::getAllSoftware();

        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'softwareList' => $softwareList,
        ]);

        return View::fetch('admin/authorization/batch');
    }

    // 执行批量生成
    public function batchGenerate()
    {
        $data = Request::post();

        // 验证数据
        if (empty($data['software_id']) || empty($data['count'])) {
            return json(['code' => 400, 'msg' => '软件ID和生成数量不能为空']);
        }

        $softwareId = intval($data['software_id']);
        $count = intval($data['count']);

        // 限制单次生成数量
        if ($count < 1 || $count > 1000) {
            return json(['code' => 400, 'msg' => '生成数量必须在1-1000之间']);
        }

        // 处理时间
        if (isset($data['is_permanent']) && $data['is_permanent']) {
            $endTime = 0;
        } else {
            if (empty($data['start_time']) || empty($data['end_time'])) {
                return json(['code' => 400, 'msg' => '开始时间和结束时间不能为空']);
            }
            if (strtotime($data['start_time']) >= strtotime($data['end_time'])) {
                return json(['code' => 400, 'msg' => '开始时间必须早于结束时间']);
            }
            $startTime = strtotime($data['start_time']);
            $endTime = strtotime($data['end_time']);
        }

        // 生成授权码
        $generated = [];
        $existingCodes = \think\facade\Db::name('authorizations')->column('license_number');
        $time = time();

        // 处理域名和IP
        $domains = isset($data['domains']) ? $data['domains'] : '';
        $ips = isset($data['ips']) ? $data['ips'] : '';
        $features = isset($data['features']) ? $data['features'] : [];

        for ($i = 0; $i < $count; $i++) {
            $licenseNumber = $this->generateLicenseNumber();

            // 确保唯一性
            while (in_array($licenseNumber, $existingCodes)) {
                $licenseNumber = $this->generateLicenseNumber();
            }

            $existingCodes[] = $licenseNumber;

            $authData = [
                'license_number' => $licenseNumber,
                'software_id' => $softwareId,
                'domain' => $domains,
                'server_ip' => $ips,
                'start_time' => isset($startTime) ? $startTime : $time,
                'end_time' => $endTime,
                'status' => isset($data['status']) ? intval($data['status']) : 1,
                'features' => !empty($features) ? json_encode($features) : null,
                'create_time' => $time,
                'update_time' => $time,
            ];

            $result = \think\facade\Db::name('authorizations')->insertGetId($authData);

            if ($result) {
                $generated[] = [
                    'id' => $result,
                    'license_number' => $licenseNumber,
                    'start_time' => date('Y-m-d', $authData['start_time']),
                    'end_time' => $endTime ? date('Y-m-d', $endTime) : '永久',
                    'domain' => $domains ?: '不限',
                ];
            }
        }

        return json([
            'code' => 200,
            'msg' => "成功生成" . count($generated) . "个授权码",
            'data' => $generated
        ]);
    }

    // 生成授权码
    private function generateLicenseNumber()
    {
        return LicenseService::generateLicenseNumber();
    }

    // 授权统计
    public function statistics()
    {
        // 总授权数
        $total = \think\facade\Db::name('authorizations')->count();

        // 有效授权数
        $valid = \think\facade\Db::name('authorizations')->where('status', 1)->count();

        // 无效授权数
        $invalid = \think\facade\Db::name('authorizations')->where('status', 0)->count();

        // 永久授权数
        $permanent = \think\facade\Db::name('authorizations')->where('end_time', 0)->count();

        // 已过期授权数
        $expired = \think\facade\Db::name('authorizations')
            ->where('end_time', '<', time())
            ->where('end_time', '>', 0)
            ->count();

        // 即将过期授权（30天内）
        $expiringSoon = \think\facade\Db::name('authorizations')
            ->where('end_time', '>', time())
            ->where('end_time', '<', time() + 86400 * 30)
            ->count();

        // 按软件统计
        $bySoftware = \think\facade\Db::name('authorizations')
            ->field('software_id, COUNT(*) as count')
            ->group('software_id')
            ->select()
            ->toArray();

        $softwareList = SoftwareModel::getAllSoftware()->toArray();
        $softwareMap = [];
        foreach ($softwareList as $software) {
            $softwareMap[$software['id']] = $software['software_name'];
        }

        foreach ($bySoftware as &$item) {
            $item['software_name'] = $softwareMap[$item['software_id']] ?? '未知软件';
        }

        // 最近7天新增授权
        $last7Days = \think\facade\Db::name('authorizations')
            ->where('create_time', '>=', time() - 86400 * 7)
            ->count();

        // 最近30天新增授权
        $last30Days = \think\facade\Db::name('authorizations')
            ->where('create_time', '>=', time() - 86400 * 30)
            ->count();

        View::assign([
            'admin_username' => Session::get('admin_username'),
            'admin_name' => Session::get('admin_username', '管理员'),
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'permanent' => $permanent,
            'expired' => $expired,
            'expiring_soon' => $expiringSoon,
            'by_software' => $bySoftware,
            'last_7_days' => $last7Days,
            'last_30_days' => $last30Days,
        ]);

        return View::fetch('admin/authorization/statistics');
    }

    // 导出授权
    public function export()
    {
        $softwareId = Request::get('software_id', 0);
        $status = Request::get('status', -1);

        $query = \think\facade\Db::name('authorizations')
            ->alias('a')
            ->join('software s', 'a.software_id = s.id', 'LEFT')
            ->field('a.*, s.software_name');

        if ($softwareId > 0) {
            $query->where('a.software_id', $softwareId);
        }

        if ($status >= 0) {
            $query->where('a.status', $status);
        }

        $authorizations = $query->select()->toArray();

        // 生成CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="authorizations_' . date('YmdHis') . '.csv"');

        $output = fopen('php://output', 'w');

        // 添加BOM，防止中文乱码
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // CSV头部
        fputcsv($output, ['授权编号', '软件名称', '开始时间', '结束时间', '状态', '创建时间']);

        // 写入数据
        foreach ($authorizations as $auth) {
            fputcsv($output, [
                $auth['license_number'],
                $auth['software_name'] ?? '未知',
                $auth['start_time'] > 0 ? date('Y-m-d H:i:s', $auth['start_time']) : '-',
                $auth['end_time'] == 0 ? '永久' : date('Y-m-d H:i:s', $auth['end_time']),
                $auth['status'] == 1 ? '有效' : '无效',
                $auth['create_time'] > 0 ? date('Y-m-d H:i:s', $auth['create_time']) : '-'
            ]);
        }

        fclose($output);
        exit;
    }

    // 搜索授权
    public function search()
    {
        $keyword = Request::get('keyword', '');
        $page = Request::get('page', 1);
        $pageSize = Request::get('page_size', 20);

        $query = \think\facade\Db::name('authorizations')
            ->alias('a')
            ->join('software s', 'a.software_id = s.id', 'LEFT')
            ->field('a.*, s.software_name');

        if (!empty($keyword)) {
            $query->where('a.license_number', 'like', '%' . $keyword . '%');
        }

        $total = $query->count();
        $authorizations = $query
            ->order('a.id DESC')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $authorizations,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => ceil($total / $pageSize)
            ]
        ]);
    }

    // 生成授权码
    public function generateCode()
    {
        $licenseNumber = LicenseService::generateLicenseNumber();
        
        return json([
            'code' => 200,
            'msg' => '生成成功',
            'data' => [
                'license_number' => $licenseNumber
            ]
        ]);
    }
}
