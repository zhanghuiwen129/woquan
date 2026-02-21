<?php

namespace app\database\seeders;

use think\migration\Seeder;

class InitialDataSeeder extends Seeder
{
    public function run()
    {
        // 插入管理员用户
        $adminData = [
            'username' => 'admin',
            'password' => md5('123456'),
            'email' => '',
            'name' => '用户admin',
            'img' => './assets/img/tx.png',
            'url' => '',
            'homeimg' => '-1',
            'sign' => 'Miaoo - 更简洁，更优雅',
            'essqx' => '1',
            'esseam' => '1',
            'regtime' => date('Y-m-d H:i:s'),
            'regip' => '127.0.0.1',
            'logtime' => date('Y-m-d H:i:s'),
            'logip' => '127.0.0.1',
            'ban' => '0',
            'bantime' => 'false',
            'passid' => $this->generateRandomString(64)
        ];
        
        $this->table('user')->insert($adminData)->save();
        
        // 插入默认配置
        $adminConfig = [
            'name' => 'Lan - 更简洁，更优雅',
            'subtitle' => 'Lan - 更简洁，更优雅',
            'icon' => './assets/img/favicon.png',
            'logo' => './assets/img/logo.png',
            'zt' => '1',
            'username' => 'admin',
            'homimg' => './assets/img/homeimg.jpg',
            'sign' => 'Miaoo - 更简洁，更优雅',
            'music' => '-1',
            'essgs' => '10',
            'commgs' => '10',
            'lnkzt' => '0',
            'regqx' => '0',
            'kqsy' => '0',
            'comaud' => '0',
            'ptpaud' => '0',
            'ptpfan' => '1',
            'loginkg' => '1',
            'notname' => '0',
            'imgpres' => '0',
            'rosdomain' => '0',
            'daymode' => '0',
            'gotop' => '0',
            'search' => '0',
            'videoauplay' => '1',
            'regverify' => '0',
            'pagepass' => '',
            'emydz' => 'smtp.qq.com',
            'emssl' => 'ssl',
            'emduk' => '465',
            'emkey' => '',
            'emzh' => '',
            'emfs' => '',
            'emfszm' => '',
            'date' => date('Y-m-d H:i:s'),
            'copyright' => 'Miaoo',
            'beian' => '',
            'topes' => '',
            'scfont' => '',
            'viscomm' => '-1',
            'musplay' => '1'
        ];
        
        $this->table('admin')->insert($adminConfig)->save();
    }
    
    private function generateRandomString($length = 64)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
}
