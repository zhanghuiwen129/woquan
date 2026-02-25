<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreatePermissionTables extends Migrator
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 创建角色表
        $this->table('role')
            ->addColumn(Column::string('name', 50)->setComment('角色名称'))
            ->addColumn(Column::string('description', 255)->setNullable()->setComment('角色描述'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::integer('status')->setDefault(1)->setComment('状态：0禁用，1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->setComment('角色表')
            ->create();

        // 创建权限表
        $this->table('permission')
            ->addColumn(Column::string('name', 100)->setComment('权限名称'))
            ->addColumn(Column::string('route', 100)->setNullable()->setComment('路由'))
            ->addColumn(Column::string('method', 10)->setNullable()->setComment('请求方法'))
            ->addColumn(Column::string('description', 255)->setNullable()->setComment('权限描述'))
            ->addColumn(Column::integer('parent_id')->setDefault(0)->setComment('父权限ID'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::integer('status')->setDefault(1)->setComment('状态：0禁用，1启用'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->setComment('权限表')
            ->create();

        // 创建角色权限关联表
        $this->table('role_permission')
            ->addColumn(Column::integer('role_id')->setComment('角色ID'))
            ->addColumn(Column::integer('permission_id')->setComment('权限ID'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addIndex(['role_id', 'permission_id'], ['name' => 'idx_role_permission'])
            ->setComment('角色权限关联表')
            ->create();

        // 初始化权限数据
        $permissions = [
            ['name' => '后台管理', 'route' => 'admin/index', 'method' => 'GET', 'description' => '后台首页', 'parent_id' => 0, 'sort' => 0, 'status' => 1],
            ['name' => '管理员管理', 'route' => 'admin/admin', 'method' => 'GET', 'description' => '管理员列表', 'parent_id' => 0, 'sort' => 1, 'status' => 1],
            ['name' => '添加管理员', 'route' => 'admin/admin/create', 'method' => 'GET', 'description' => '添加管理员页面', 'parent_id' => 2, 'sort' => 0, 'status' => 1],
            ['name' => '保存管理员', 'route' => 'admin/admin/store', 'method' => 'POST', 'description' => '保存管理员', 'parent_id' => 2, 'sort' => 1, 'status' => 1],
            ['name' => '编辑管理员', 'route' => 'admin/admin/edit', 'method' => 'GET', 'description' => '编辑管理员页面', 'parent_id' => 2, 'sort' => 2, 'status' => 1],
            ['name' => '更新管理员', 'route' => 'admin/admin/update', 'method' => 'POST', 'description' => '更新管理员', 'parent_id' => 2, 'sort' => 3, 'status' => 1],
            ['name' => '删除管理员', 'route' => 'admin/admin/delete', 'method' => 'POST', 'description' => '删除管理员', 'parent_id' => 2, 'sort' => 4, 'status' => 1],
            ['name' => '角色管理', 'route' => 'admin/role', 'method' => 'GET', 'description' => '角色列表', 'parent_id' => 0, 'sort' => 2, 'status' => 1],
            ['name' => '添加角色', 'route' => 'admin/role/create', 'method' => 'GET', 'description' => '添加角色页面', 'parent_id' => 8, 'sort' => 0, 'status' => 1],
            ['name' => '保存角色', 'route' => 'admin/role/store', 'method' => 'POST', 'description' => '保存角色', 'parent_id' => 8, 'sort' => 1, 'status' => 1],
            ['name' => '编辑角色', 'route' => 'admin/role/edit', 'method' => 'GET', 'description' => '编辑角色页面', 'parent_id' => 8, 'sort' => 2, 'status' => 1],
            ['name' => '更新角色', 'route' => 'admin/role/update', 'method' => 'POST', 'description' => '更新角色', 'parent_id' => 8, 'sort' => 3, 'status' => 1],
            ['name' => '删除角色', 'route' => 'admin/role/delete', 'method' => 'POST', 'description' => '删除角色', 'parent_id' => 8, 'sort' => 4, 'status' => 1],
            ['name' => '权限管理', 'route' => 'admin/permission', 'method' => 'GET', 'description' => '权限列表', 'parent_id' => 0, 'sort' => 3, 'status' => 1],
            ['name' => '公告管理', 'route' => 'admin/announcement', 'method' => 'GET', 'description' => '公告列表', 'parent_id' => 0, 'sort' => 4, 'status' => 1],
            ['name' => '动态管理', 'route' => 'admin/moments', 'method' => 'GET', 'description' => '动态列表', 'parent_id' => 0, 'sort' => 5, 'status' => 1],
            ['name' => '删除动态', 'route' => 'admin/moments/delete', 'method' => 'POST', 'description' => '删除动态', 'parent_id' => 16, 'sort' => 0, 'status' => 1],
            ['name' => '话题管理', 'route' => 'admin/topic', 'method' => 'GET', 'description' => '话题列表', 'parent_id' => 0, 'sort' => 6, 'status' => 1],
            ['name' => '用户管理', 'route' => 'admin/user', 'method' => 'GET', 'description' => '用户列表', 'parent_id' => 0, 'sort' => 7, 'status' => 1],
            ['name' => '文章管理', 'route' => 'admin/article', 'method' => 'GET', 'description' => '文章列表', 'parent_id' => 0, 'sort' => 8, 'status' => 1],
            ['name' => '评论管理', 'route' => 'admin/comment', 'method' => 'GET', 'description' => '评论列表', 'parent_id' => 0, 'sort' => 9, 'status' => 1],
            ['name' => '数据统计', 'route' => 'admin/statistic', 'method' => 'GET', 'description' => '数据统计', 'parent_id' => 0, 'sort' => 10, 'status' => 1],
        ];

        foreach ($permissions as $permission) {
            $this->table('permission')->insert($permission);
        }

        // 初始化角色数据
        $roles = [
            ['name' => '超级管理员', 'description' => '拥有所有权限', 'sort' => 0, 'status' => 1],
            ['name' => '管理员', 'description' => '拥有大部分权限', 'sort' => 1, 'status' => 1],
            ['name' => '编辑', 'description' => '拥有内容编辑权限', 'sort' => 2, 'status' => 1],
            ['name' => '审核员', 'description' => '拥有内容审核权限', 'sort' => 3, 'status' => 1],
        ];

        foreach ($roles as $role) {
            $this->table('role')->insert($role);
        }

        // 为超级管理员分配所有权限
        $permissionIds = $this->query('SELECT id FROM permission')->fetchAll();
        foreach ($permissionIds as $permission) {
            $this->table('role_permission')->insert([
                'role_id' => 1,
                'permission_id' => $permission['id'],
                'create_time' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // 删除角色权限关联表
        $this->table('role_permission')->drop();
        // 删除权限表
        $this->table('permission')->drop();
        // 删除角色表
        $this->table('role')->drop();
    }
}
