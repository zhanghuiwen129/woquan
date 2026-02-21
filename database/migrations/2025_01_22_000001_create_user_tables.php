<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateUserTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 用户表
        $this->table('user')
            ->addColumn('username', 'string', ['limit' => 50, 'comment' => '用户名'])
            ->addColumn('password', 'string', ['limit' => 255, 'comment' => '密码(bcrypt加密)'])
            ->addColumn('email', 'string', ['limit' => 100, 'null' => true, 'comment' => '邮箱'])
            ->addColumn('mobile', 'string', ['limit' => 20, 'null' => true, 'comment' => '手机号'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'default' => '', 'comment' => '昵称'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '头像URL'])
            ->addColumn('real_name', 'string', ['limit' => 50, 'null' => true, 'comment' => '真实姓名'])
            ->addColumn('gender', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '性别:0未知,1男,2女'])
            ->addColumn('birthday', 'date', ['null' => true, 'comment' => '生日'])
            ->addColumn('bio', 'string', ['limit' => 500, 'null' => true, 'comment' => '个人简介'])
            ->addColumn('occupation', 'string', ['limit' => 100, 'null' => true, 'comment' => '职业'])
            ->addColumn('interests', 'text', ['null' => true, 'comment' => '兴趣爱好JSON'])
            ->addColumn('province', 'string', ['limit' => 50, 'default' => '', 'comment' => '省份'])
            ->addColumn('city', 'string', ['limit' => 50, 'default' => '', 'comment' => '城市'])
            ->addColumn('district', 'string', ['limit' => 50, 'default' => '', 'comment' => '区县'])
            ->addColumn('url', 'string', ['limit' => 255, 'null' => true, 'comment' => '个人网址'])
            ->addColumn('homeimg', 'string', ['limit' => 255, 'null' => true, 'comment' => '主页背景图'])
            ->addColumn('sign', 'string', ['limit' => 500, 'null' => true, 'comment' => '个性签名'])
            ->addColumn('card_background', 'string', ['limit' => 255, 'null' => true, 'comment' => '名片背景图'])
            ->addColumn('card_theme_color', 'string', ['limit' => 20, 'default' => '#1890ff', 'comment' => '名片主题色'])
            ->addColumn('card_layout', 'string', ['limit' => 50, 'default' => 'default', 'comment' => '名片布局模板'])
            ->addColumn('card_privacy', 'text', ['null' => true, 'comment' => '隐私设置JSON'])
            ->addColumn('card_stealth', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '隐身访问:0否,1是'])
            ->addColumn('vip_level', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => 'VIP等级'])
            ->addColumn('coins', 'integer', ['default' => 0, 'comment' => '金币数量'])
            ->addColumn('experience', 'integer', ['default' => 0, 'comment' => '经验值'])
            ->addColumn('level', 'integer', ['default' => 1, 'comment' => '用户等级'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0禁用'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['null' => true, 'comment' => '更新时间'])
            ->addColumn('last_login_time', 'integer', ['null' => true, 'comment' => '最后登录时间'])
            ->addColumn('last_login_ip', 'string', ['limit' => 50, 'null' => true, 'comment' => '最后登录IP'])
            ->addColumn('register_ip', 'string', ['limit' => 45, 'default' => '', 'comment' => '注册IP'])
            ->addColumn('banned_until', 'timestamp', ['null' => true, 'comment' => '封禁截止时间'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'comment' => '软删除时间'])
            ->addColumn('device_info', 'text', ['null' => true, 'comment' => '设备信息JSON'])
            ->addIndex('email')
            ->addIndex('mobile')
            ->addIndex('nickname')
            ->addIndex('status')
            ->addIndex('level')
            ->create();

        // 用户详情表
        $this->table('user_profiles')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('real_name', 'string', ['limit' => 50, 'default' => '', 'comment' => '真实姓名'])
            ->addColumn('id_card', 'string', ['limit' => 20, 'default' => '', 'comment' => '身份证号'])
            ->addColumn('education', 'string', ['limit' => 20, 'default' => '', 'comment' => '学历'])
            ->addColumn('occupation', 'string', ['limit' => 50, 'default' => '', 'comment' => '职业'])
            ->addColumn('company', 'string', ['limit' => 100, 'default' => '', 'comment' => '公司'])
            ->addColumn('income_range', 'string', ['limit' => 20, 'default' => '', 'comment' => '收入范围'])
            ->addColumn('hobby_tags', 'text', ['null' => true, 'comment' => '兴趣爱好标签JSON'])
            ->addColumn('signature', 'string', ['limit' => 500, 'default' => '', 'comment' => '个性签名'])
            ->addColumn('website', 'string', ['limit' => 255, 'default' => '', 'comment' => '个人网站'])
            ->addColumn('social_wechat', 'string', ['limit' => 50, 'default' => '', 'comment' => '微信号'])
            ->addColumn('social_qq', 'string', ['limit' => 20, 'default' => '', 'comment' => 'QQ号'])
            ->addColumn('social_weibo', 'string', ['limit' => 100, 'default' => '', 'comment' => '微博'])
            ->addColumn('background_image', 'string', ['limit' => 500, 'default' => '', 'comment' => '主页背景图'])
            ->addColumn('theme_preference', 'string', ['limit' => 20, 'default' => 'default', 'comment' => '主题偏好'])
            ->addColumn('privacy_settings', 'text', ['null' => true, 'comment' => '隐私设置JSON'])
            ->addColumn('notification_settings', 'text', ['null' => true, 'comment' => '通知设置JSON'])
            ->addColumn('create_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('update_time', 'timestamp', ['null' => true, 'comment' => '更新时间'])
            ->addIndex('real_name')
            ->addIndex('occupation')
            ->create();

        // 用户分组表
        $this->table('user_groups')
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '分组名称'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '分组描述'])
            ->addColumn('color', 'string', ['limit' => 20, 'default' => '#1890ff', 'comment' => '分组颜色'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('sort')
            ->create();

        // 用户分组关系表
        $this->table('user_group_relation')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('group_id', 'integer', ['comment' => '分组ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('group_id')
            ->create();

        // 用户标签表
        $this->table('user_tags')
            ->addColumn('tag_name', 'string', ['limit' => 50, 'comment' => '标签名称'])
            ->addColumn('tag_color', 'string', ['limit' => 20, 'default' => '#1890ff', 'comment' => '标签颜色'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '标签描述'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->create();

        // 用户标签关系表
        $this->table('user_tag_relation')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('tag_id', 'integer', ['comment' => '标签ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('tag_id')
            ->create();
    }
}
