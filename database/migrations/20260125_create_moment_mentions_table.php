<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMomentMentionsTable extends Migrator
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->table('mentions')
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'comment' => 'ID'])
            ->addColumn('moment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '动态ID'])
            ->addColumn('user_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '被@用户ID'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '创建时间'])
            ->addIndex(['moment_id'], ['name' => 'idx_moment_id'])
            ->addIndex(['user_id'], ['name' => 'idx_user_id'])
            ->addIndex(['moment_id', 'user_id'], ['name' => 'idx_moment_user', 'unique' => true])
            ->create();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->dropTable('mentions');
    }
}
