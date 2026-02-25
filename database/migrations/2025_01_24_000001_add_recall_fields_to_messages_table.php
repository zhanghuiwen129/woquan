<?php

use think\migration\Migrator;

class AddRecallFieldsToMessagesTable extends Migrator
{
    public function change()
    {
        $table = $this->table('messages');
        
        // 添加撤回相关字段
        $table
            ->addColumn('is_recalled', 'integer', [
                'limit' => 1,
                'default' => 0,
                'signed' => false,
                'comment' => '是否撤回：0-未撤回，1-已撤回',
                'after' => 'read_time'
            ])
            ->addColumn('recall_time', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => '撤回时间（时间戳）',
                'after' => 'is_recalled'
            ])
            ->update();
    }
}
