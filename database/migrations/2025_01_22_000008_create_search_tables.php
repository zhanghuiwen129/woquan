<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSearchTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 搜索历史表
        $this->table('search_history')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('keyword', 'string', ['limit' => 200, 'comment' => '搜索关键词'])
            ->addColumn('search_count', 'integer', ['default' => 1, 'comment' => '搜索次数'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('keyword')
            ->addIndex('create_time')
            ->create();

        // 热门搜索表
        $this->table('hot_searches')
            ->addColumn('keyword', 'string', ['limit' => 200, 'comment' => '关键词'])
            ->addColumn('search_count', 'integer', ['default' => 0, 'comment' => '搜索次数'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1启用,0禁用'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('keyword')
            ->addIndex('sort')
            ->addIndex('status')
            ->create();
    }
}
