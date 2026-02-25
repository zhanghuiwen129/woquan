<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Monitor extends AdminController
{
    
    public function performance()
    {
        $responseTime = 120;
        $performance = [
            'response_time' => $responseTime,
            'memory_usage' => 64,
            'cpu_usage' => 45,
            'requests_per_second' => 150,
            'response_time_percent' => 60,
            'memory_usage_percent' => 64,
            'cpu_usage_percent' => 45,
            'requests_percent' => 75,
            'response_time_max' => $responseTime * 1.5,
            'response_time_min' => $responseTime * 0.5,
            'response_time_p95' => $responseTime * 1.2
        ];

        View::assign([
            'performance' => $performance
        ]);

        return View::fetch('admin/monitor/performance');
    }
    
    public function server()
    {
        $server = [
            'disk_usage' => 65,
            'memory_usage' => 70,
            'cpu_usage' => 50,
            'uptime' => '30天 12小时'
        ];
        
        View::assign([
            'server' => $server
        ]);
        
        return View::fetch('admin/monitor/server');
    }
    
    public function database()
    {
        $connections = 25;
        $maxConnections = 100;
        $database = [
            'table_size' => '2.5GB',
            'connections' => $connections,
            'slow_queries' => 3,
            'queries_per_second' => 500,
            'connections_percent' => round($connections / $maxConnections * 100),
            'queries_percent' => 75,
            'active_connections' => 20,
            'idle_connections' => 5,
            'max_connections' => $maxConnections,
            'total_queries' => 5000000,
            'avg_query_time' => 2.5
        ];

        View::assign([
            'database' => $database
        ]);

        return View::fetch('admin/monitor/database');
    }
}
