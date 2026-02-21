<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMiscTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 评论扩展表
        $this->table('comm')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('content', 'text', ['comment' => '评论内容'])
            ->addColumn('parent_id', 'integer', ['default' => 0, 'comment' => '父评论ID'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'comment' => '用户昵称'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '用户头像'])
            ->addColumn('likes', 'integer', ['default' => 0, 'comment' => '点赞数'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0删除'])
            ->addColumn('create_time', 'integer', ['comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('moment_id')
            ->addIndex('parent_id')
            ->addIndex('create_time')
            ->create();

        // 系统扩展配置表
        $this->table('configx')
            ->addColumn('config_name', 'string', ['limit' => 100, 'comment' => '配置名称'])
            ->addColumn('config_key', 'string', ['limit' => 100, 'comment' => '配置键'])
            ->addColumn('config_value', 'text', ['null' => true, 'comment' => '配置值'])
            ->addColumn('config_type', 'string', ['limit' => 20, 'default' => 'text', 'comment' => '配置类型'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true, 'comment' => '描述'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addIndex('config_key')
            ->addIndex('sort')
            ->create();

        // 举报表
        $this->table('reports')
            ->addColumn('reporter_id', 'integer', ['comment' => '举报人ID'])
            ->addColumn('reported_id', 'integer', ['comment' => '被举报人ID'])
            ->addColumn('target_id', 'integer', ['comment' => '举报对象ID'])
            ->addColumn('target_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '对象类型:1-动态,2-评论,3-用户'])
            ->addColumn('report_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '举报类型:1-违规内容,2-垃圾广告,3-色情低俗,4-政治敏感'])
            ->addColumn('report_reason', 'string', ['limit' => 500, 'comment' => '举报原因'])
            ->addColumn('evidence', 'text', ['null' => true, 'comment' => '证据JSON'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '状态:0-待处理,1-已处理,2-已驳回'])
            ->addColumn('handler_id', 'integer', ['null' => true, 'comment' => '处理人ID'])
            ->addColumn('handle_result', 'string', ['limit' => 200, 'null' => true, 'comment' => '处理结果'])
            ->addColumn('handle_time', 'integer', ['null' => true, 'comment' => '处理时间'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('reporter_id')
            ->addIndex('reported_id')
            ->addIndex('target_id')
            ->addIndex('target_type')
            ->addIndex('report_type')
            ->addIndex('status')
            ->addIndex('create_time')
            ->create();

        // 数据库迁移记录表
        $this->table('migrations')
            ->addColumn('version', 'string', ['limit' => 100, 'comment' => '迁移版本'])
            ->addColumn('start_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '开始时间'])
            ->addColumn('end_time', 'timestamp', ['null' => true, 'comment' => '结束时间'])
            ->addIndex('version')
            ->create();
    }
}
