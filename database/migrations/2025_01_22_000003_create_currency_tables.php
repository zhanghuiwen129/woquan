<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCurrencyTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 用户货币表
        $this->table('user_currency')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('currency_id', 'integer', ['comment' => '货币ID'])
            ->addColumn('balance', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'comment' => '余额'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('user_id')
            ->addIndex('currency_id')
            ->create();

        // 货币类型表
        $this->table('currency_types')
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '货币名称'])
            ->addColumn('code', 'string', ['limit' => 20, 'comment' => '货币代码'])
            ->addColumn('symbol', 'string', ['limit' => 10, 'comment' => '货币符号'])
            ->addColumn('icon', 'string', ['limit' => 255, 'null' => true, 'comment' => '图标URL'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '描述'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1启用,0禁用'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('code')
            ->addIndex('status')
            ->create();

        // 货币日志表
        $this->table('currency_logs')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('currency_id', 'integer', ['comment' => '货币ID'])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => '变动金额'])
            ->addColumn('before_balance', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => '变动前余额'])
            ->addColumn('after_balance', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => '变动后余额'])
            ->addColumn('type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '类型:1充值,2消费,3赠送,4奖励,5退款'])
            ->addColumn('description', 'string', ['limit' => 200, 'comment' => '说明'])
            ->addColumn('related_id', 'integer', ['default' => 0, 'comment' => '关联ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('currency_id')
            ->addIndex('create_time')
            ->create();
    }
}
