<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateHiddenMomentsTable extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        $this->table('hidden_moments')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('moment_id')
            ->addIndex(['user_id', 'moment_id'], ['unique' => true])
            ->create();
    }
}
