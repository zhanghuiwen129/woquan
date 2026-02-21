<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateVipTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 用户VIP表
        $this->table('user_vip')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('vip_level', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => 'VIP等级'])
            ->addColumn('start_time', 'integer', ['default' => 0, 'comment' => 'VIP开始时间'])
            ->addColumn('end_time', 'integer', ['default' => 0, 'comment' => 'VIP结束时间'])
            ->addColumn('is_permanent', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否永久VIP:0否,1是'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('user_id')
            ->addIndex('vip_level')
            ->addIndex('end_time')
            ->create();

        // VIP等级表
        $this->table('vip_levels')
            ->addColumn('level', 'integer', ['limit' => 1, 'signed' => false, 'comment' => 'VIP等级'])
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '等级名称'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true, 'comment' => '等级描述'])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'comment' => '价格'])
            ->addColumn('duration', 'integer', ['default' => 0, 'comment' => '有效期(天)'])
            ->addColumn('privileges', 'text', ['null' => true, 'comment' => '特权列表JSON'])
            ->addColumn('icon', 'string', ['limit' => 255, 'null' => true, 'comment' => '图标URL'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1启用,0禁用'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('level')
            ->addIndex('status')
            ->addIndex('sort')
            ->create();

        // VIP订单表
        $this->table('vip_orders')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('vip_level', 'integer', ['limit' => 1, 'signed' => false, 'comment' => 'VIP等级'])
            ->addColumn('order_no', 'string', ['limit' => 50, 'comment' => '订单号'])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => '订单金额'])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'comment' => '支付方式'])
            ->addColumn('payment_time', 'integer', ['default' => 0, 'comment' => '支付时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '状态:0待支付,1已支付,2已取消,3已退款'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('order_no')
            ->addIndex('status')
            ->create();
    }
}
