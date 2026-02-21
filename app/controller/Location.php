<?php
declare (strict_types = 1);

namespace app\controller;

use think\Request;
use think\facade\Db;
use think\facade\Session;

class Location extends BaseFrontendController
{
    /**
     * 保存用户位置
     */
    public function save()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取POST数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['latitude']) || !isset($data['longitude'])) {
                return $this->badRequest('参数不完整');
            }

            $latitude = floatval($data['latitude']);
            $longitude = floatval($data['longitude']);
            $address = $data['address'] ?? '';
            $city = $data['city'] ?? '';
            $district = $data['district'] ?? '';

            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                return $this->badRequest('经纬度无效');
            }

            $userId = Session::get('user_id');
            
            if (!$userId) {
                return $this->unauthorized();
            }

            // 插入或更新位置记录
            $locationData = [
                'user_id' => $userId ?: 0,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $address,
                'city' => $city,
                'district' => $district,
                'ip' => request()->ip(),
                'create_time' => time(),
                'update_time' => time()
            ];

            // 查找是否存在最近的记录（5分钟内）
            $recentLocation = Db::name('user_locations')
                ->where('user_id', $userId ?: 0)
                ->where('ip', request()->ip())
                ->where('create_time', '>', time() - 300)
                ->find();

            if ($recentLocation) {
                // 更新记录
                Db::name('user_locations')
                    ->where('id', $recentLocation['id'])
                    ->update($locationData);
            } else {
                // 插入新记录
                Db::name('user_locations')->insert($locationData);
            }

            // 如果用户已登录，更新用户表的最后位置
            if ($userId) {
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'last_latitude' => $latitude,
                        'last_longitude' => $longitude,
                        'last_city' => $city,
                        'last_location_time' => time()
                    ]);
            }

            return $this->success([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'city' => $city,
                'district' => $district,
                'address' => $address
            ], '位置保存成功');

        } catch (\Exception $e) {
            return $this->error('保存位置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取附近的位置（基于经纬度）
     */
    public function nearby()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');

            $latitude = floatval(input('latitude'));
            $longitude = floatval(input('longitude'));
            $radius = intval(input('radius', 5000)); // 默认5公里范围
            $page = intval(input('page', 1));
            $limit = intval(input('limit', 20));
            $offset = ($page - 1) * $limit;

            if (!$latitude || !$longitude) {
                return $this->badRequest('经纬度不能为空');
            }

            // 计算范围（简单矩形范围，实际应用可用更精确的球形距离公式）
            $latRange = $radius / 111000; // 1度纬度约111km
            $lngRange = $radius / (111000 * cos(deg2rad($latitude)));

            // 查询附近的位置
            $locations = Db::name('user_locations')
                ->alias('l')
                ->leftJoin('user u', 'l.user_id = u.id')
                ->where('l.latitude', 'between', [$latitude - $latRange, $latitude + $latRange])
                ->where('l.longitude', 'between', [$longitude - $lngRange, $longitude + $lngRange])
                ->where('l.create_time', '>', time() - 86400) // 只显示24小时内的
                ->order('l.create_time', 'desc')
                ->limit($offset, $limit)
                ->field('l.*, u.nickname, u.avatar')
                ->select()
                ->toArray();

            // 计算实际距离
            foreach ($locations as &$location) {
                $location['distance'] = $this->calculateDistance(
                    $latitude,
                    $longitude,
                    $location['latitude'],
                    $location['longitude']
                );
            }

            // 按距离排序
            usort($locations, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });

            $total = count($locations);

            return $this->success([
                'locations' => $locations,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取成功');

        } catch (\Exception $e) {
            return $this->error('获取附近位置失败: ' . $e->getMessage());
        }
    }

    /**
     * 逆地理编码（经纬度转地址）
     */
    public function reverseGeocode()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');

            $latitude = floatval(input('latitude'));
            $longitude = floatval(input('longitude'));

            if (!$latitude || !$longitude) {
                return $this->badRequest('经纬度不能为空');
            }

            $address = $this->getAddressFromApi($latitude, $longitude);

            return $this->success($address, '获取成功');

        } catch (\Exception $e) {
            return $this->error('获取地址失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取热门地点
     */
    public function popular()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');

            $city = input('city', '');
            $limit = intval(input('limit', 20));

            $query = Db::name('user_locations')
                ->alias('l')
                ->leftJoin('user u', 'l.user_id = u.id')
                ->where('l.create_time', '>', time() - 604800); // 7天内

            if ($city) {
                $query->where('l.city', 'like', '%' . $city . '%');
            }

            // 按城市和区域分组统计
            $locations = $query
                ->field('l.city, l.district, l.address, COUNT(*) as checkin_count')
                ->group('l.city, l.district, l.address')
                ->order('checkin_count', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            return $this->success([
                'locations' => $locations
            ], '获取成功');

        } catch (\Exception $e) {
            return $this->error('获取热门地点失败: ' . $e->getMessage());
        }
    }

    /**
     * 计算两点间距离（Haversine公式）
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000; // 地球半径（米）

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return round($distance); // 返回米数，取整
    }

    /**
     * 从API获取地址（示例，需要配置实际API）
     */
    private function getAddressFromApi($latitude, $longitude)
    {
        // 这里可以接入腾讯地图API、高德地图API等
        // 示例使用腾讯地图逆地理编码
        // $key = '你的腾讯地图key';
        // $url = "https://apis.map.qq.com/ws/geocoder/v1/?location={$latitude},{$longitude}&key={$key}&get_poi=1";
        // $response = file_get_contents($url);
        // $result = json_decode($response, true);
        // return $result;

        // 暂时返回模拟数据
        return [
            'address' => '北京市东城区王府井大街',
            'city' => '北京市',
            'district' => '东城区',
            'province' => '北京',
            'formatted_address' => '北京市东城区王府井大街'
        ];
    }
}
