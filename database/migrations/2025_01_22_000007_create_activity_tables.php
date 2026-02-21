<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateActivityTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 活动表
        $this->table('activities')
            ->addColumn('title', 'string', ['limit' => 100, 'comment' => '活动标题'])
            ->addColumn('content', 'text', ['comment' => '活动内容'])
            ->addColumn('cover_image', 'string', ['limit' => 255, 'comment' => '活动封面图片'])
            ->addColumn('type', 'integer', ['limit' => 4, 'default' => 1, 'signed' => false, 'comment' => '活动类型:1-线上活动,2-线下活动'])
            ->addColumn('start_time', 'integer', ['comment' => '活动开始时间'])
            ->addColumn('end_time', 'integer', ['comment' => '活动结束时间'])
            ->addColumn('location', 'string', ['limit' => 255, 'default' => '', 'comment' => '活动地点(线下活动必填)'])
            ->addColumn('organizer_id', 'integer', ['comment' => '活动组织者ID'])
            ->addColumn('organizer_name', 'string', ['limit' => 50, 'comment' => '活动组织者名称'])
            ->addColumn('participant_count', 'integer', ['default' => 0, 'comment' => '参与人数'])
            ->addColumn('max_participants', 'integer', ['default' => 0, 'comment' => '最大参与人数(0表示无限制)'])
            ->addColumn('status', 'integer', ['limit' => 4, 'default' => 1, 'signed' => false, 'comment' => '活动状态:0-未发布,1-进行中,2-已结束,3-已取消'])
            ->addColumn('is_hot', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否热门活动:0-否,1-是'])
            ->addColumn('sort', 'integer', ['limit' => 4, 'default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['comment' => '更新时间'])
            ->addIndex('status')
            ->addIndex('is_hot')
            ->addIndex('start_time')
            ->addIndex('organizer_id')
            ->create();

        // 活动参与表
        $this->table('activity_participants')
            ->addColumn('activity_id', 'integer', ['comment' => '活动ID'])
            ->addColumn('user_id', 'integer', ['comment' => '参与用户ID'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'comment' => '参与用户昵称'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'default' => '', 'comment' => '参与用户头像'])
            ->addColumn('participant_time', 'integer', ['comment' => '参与时间'])
            ->addColumn('status', 'integer', ['limit' => 4, 'default' => 1, 'signed' => false, 'comment' => '参与状态:1-已参与,2-已取消'])
            ->addIndex('user_id', ['name' => 'idx_user_id'])
            ->addIndex('participant_time', ['name' => 'idx_participant_time'])
            ->create();

        // 运营活动表
        $this->table('operations')
            ->addColumn('title', 'string', ['limit' => 100, 'comment' => '活动标题'])
            ->addColumn('description', 'text', ['comment' => '活动描述'])
            ->addColumn('cover', 'string', ['limit' => 255, 'comment' => '活动封面'])
            ->addColumn('start_time', 'integer', ['comment' => '开始时间'])
            ->addColumn('end_time', 'integer', ['comment' => '结束时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1-未开始,2-进行中,3-已结束,4-已下架'])
            ->addColumn('participant_count', 'integer', ['default' => 0, 'comment' => '参与人数'])
            ->addColumn('view_count', 'integer', ['default' => 0, 'comment' => '浏览人数'])
            ->addColumn('creator_id', 'integer', ['comment' => '创建人ID'])
            ->addColumn('create_time', 'integer', ['comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['comment' => '更新时间'])
            ->addIndex('status')
            ->addIndex('start_time')
            ->addIndex('end_time')
            ->addIndex('creator_id')
            ->create();

        // 运营活动参与表
        $this->table('operation_participants')
            ->addColumn('operation_id', 'integer', ['comment' => '活动ID'])
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('participant_time', 'integer', ['comment' => '参与时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1-已参与,2-已完成,3-已获奖'])
            ->addColumn('reward_id', 'integer', ['null' => true, 'comment' => '获得的奖励ID'])
            ->addIndex('operation_id', ['name' => 'idx_operation_id'])
            ->addIndex('user_id', ['name' => 'idx_user_id'])
            ->addIndex('participant_time', ['name' => 'idx_participant_time'])
            ->addIndex('status', ['name' => 'idx_status'])
            ->create();

        // 运营活动奖励表
        $this->table('operation_rewards')
            ->addColumn('operation_id', 'integer', ['comment' => '活动ID'])
            ->addColumn('reward_name', 'string', ['limit' => 100, 'comment' => '奖励名称'])
            ->addColumn('reward_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '奖励类型:1-实物,2-虚拟币,3-优惠券'])
            ->addColumn('reward_value', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => '奖励价值'])
            ->addColumn('total_count', 'integer', ['default' => 0, 'comment' => '总数量'])
            ->addColumn('remain_count', 'integer', ['default' => 0, 'comment' => '剩余数量'])
            ->addColumn('icon', 'string', ['limit' => 255, 'null' => true, 'comment' => '奖励图标'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('operation_id')
            ->create();

        // 运营活动奖励记录表
        $this->table('operation_reward_records')
            ->addColumn('operation_id', 'integer', ['comment' => '活动ID'])
            ->addColumn('reward_id', 'integer', ['comment' => '奖励ID'])
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '状态:0-待领取,1-已领取'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('operation_id')
            ->addIndex('reward_id')
            ->addIndex('user_id')
            ->addIndex('status')
            ->create();
    }
}
