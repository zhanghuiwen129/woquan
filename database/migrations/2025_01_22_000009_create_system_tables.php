<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSystemTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 系统管理员表
        $this->table('admin')
            ->addColumn('username', 'string', ['limit' => 50, 'default' => '', 'comment' => '管理员用户名'])
            ->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => '管理员密码(bcrypt加密)'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'default' => '', 'comment' => '管理员昵称'])
            ->addColumn('email', 'string', ['limit' => 100, 'default' => '', 'comment' => '管理员邮箱'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'default' => '', 'comment' => '管理员头像'])
            ->addColumn('role', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '管理员角色:1-超级管理员,2-普通管理员'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1-正常,0-禁用'])
            ->addColumn('last_login_ip', 'string', ['limit' => 45, 'default' => '', 'comment' => '最后登录IP'])
            ->addColumn('last_login_time', 'integer', ['default' => 0, 'comment' => '最后登录时间'])
            ->addColumn('login_count', 'integer', ['default' => 0, 'comment' => '登录次数'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'comment' => '软删除时间'])
            ->addIndex('email')
            ->addIndex('status')
            ->addIndex('role')
            ->create();

        // 管理员操作日志表
        $this->table('admin_log')
            ->addColumn('admin_id', 'integer', ['comment' => '管理员ID'])
            ->addColumn('username', 'string', ['limit' => 50, 'comment' => '用户名'])
            ->addColumn('action', 'string', ['limit' => 100, 'comment' => '操作'])
            ->addColumn('ip', 'string', ['limit' => 45, 'comment' => 'IP'])
            ->addColumn('create_time', 'integer', ['comment' => '创建时间'])
            ->addIndex('admin_id', ['name' => 'idx_admin_id'])
            ->addIndex('create_time', ['name' => 'idx_create_time'])
            ->create();

        // 系统配置表
        $this->table('system_config')
            ->addColumn('config_key', 'string', ['limit' => 100, 'comment' => '配置键'])
            ->addColumn('config_value', 'text', ['null' => true, 'comment' => '配置值'])
            ->addColumn('config_name', 'string', ['limit' => 100, 'null' => true, 'comment' => '配置名称'])
            ->addColumn('config_type', 'string', ['limit' => 20, 'default' => 'text', 'comment' => '配置类型:text,textarea,number,select,radio,checkbox'])
            ->addColumn('config_group', 'string', ['limit' => 50, 'default' => 'base', 'comment' => '配置分组'])
            ->addColumn('config_options', 'text', ['null' => true, 'comment' => '配置选项(JSON)'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('config_group', ['name' => 'idx_config_group'])
            ->addIndex('sort', ['name' => 'idx_sort'])
            ->create();

        // 系统公告表
        $this->table('announcements')
            ->addColumn('title', 'string', ['limit' => 255, 'null' => true, 'comment' => '公告标题'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '公告内容'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态:0-禁用,1-启用'])
            ->addColumn('is_publish', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '是否发布:0-未发布,1-已发布'])
            ->addColumn('publish_time', 'integer', ['default' => 0, 'comment' => '发布时间'])
            ->addColumn('expire_time', 'integer', ['default' => 0, 'comment' => '过期时间'])
            ->addColumn('is_popup', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '是否弹窗:0-否,1-是'])
            ->addColumn('click_count', 'integer', ['default' => 0, 'comment' => '点击次数'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addColumn('admin_id', 'integer', ['default' => 0, 'comment' => '发布管理员ID'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->addIndex('is_publish', ['name' => 'idx_is_publish'])
            ->addIndex('publish_time', ['name' => 'idx_publish_time'])
            ->addIndex('create_time', ['name' => 'idx_create_time'])
            ->create();
    }
}
