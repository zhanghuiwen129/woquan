<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSocialTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 关注表
        $this->table('follows')
            ->addColumn('follower_id', 'integer', ['comment' => '关注者ID'])
            ->addColumn('following_id', 'integer', ['comment' => '被关注者ID'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0已取消'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'comment' => '软删除时间'])
            ->addIndex('following_id', ['name' => 'idx_following_id'])
            ->create();

        // 黑名单表
        $this->table('blacklist')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('block_id', 'integer', ['comment' => '被拉黑用户ID'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('create_time')
            ->create();

        // 好友分组表
        $this->table('friend_groups')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('group_name', 'string', ['limit' => 50, 'comment' => '分组名称'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->create();

        // 好友分组成员表
        $this->table('friend_group_members')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('group_id', 'integer', ['comment' => '分组ID'])
            ->addColumn('friend_id', 'integer', ['comment' => '好友ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('group_id')
            ->addIndex('friend_id')
            ->create();
    }
}
