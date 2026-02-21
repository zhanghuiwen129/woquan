<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMessageTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 私信消息表
        $this->table('messages')
            ->addColumn('sender_id', 'integer', ['comment' => '发送者ID'])
            ->addColumn('receiver_id', 'integer', ['comment' => '接收者ID'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '消息内容'])
            ->addColumn('message_type', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '消息类型:1-文本,2-图片,3-视频'])
            ->addColumn('file_url', 'string', ['limit' => 255, 'default' => '', 'comment' => '文件URL(图片或视频)'])
            ->addColumn('is_read', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否已读:0-未读,1-已读'])
            ->addColumn('read_time', 'integer', ['default' => 0, 'comment' => '阅读时间'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addIndex('sender_id')
            ->addIndex('receiver_id')
            ->addIndex('is_read')
            ->addIndex('create_time')
            ->create();

        // 通知表
        $this->table('notifications')
            ->addColumn('user_id', 'integer', ['comment' => '接收用户ID'])
            ->addColumn('sender_id', 'integer', ['default' => 0, 'comment' => '发送者ID(0为系统)'])
            ->addColumn('type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '通知类型:1点赞,2评论,3关注,4私信,5系统通知'])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '标题'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '内容'])
            ->addColumn('target_id', 'integer', ['default' => 0, 'comment' => '目标ID'])
            ->addColumn('target_type', 'string', ['limit' => 50, 'default' => '', 'comment' => '目标类型'])
            ->addColumn('is_read', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否已读'])
            ->addColumn('read_time', 'timestamp', ['null' => true, 'comment' => '阅读时间'])
            ->addColumn('message_type', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '消息类型:1文本,2图片,3语音,4表情'])
            ->addColumn('file_url', 'string', ['limit' => 500, 'default' => '', 'comment' => '文件URL'])
            ->addColumn('file_name', 'string', ['limit' => 255, 'default' => '', 'comment' => '文件名'])
            ->addColumn('file_size', 'integer', ['default' => 0, 'comment' => '文件大小'])
            ->addColumn('duration', 'integer', ['default' => 0, 'comment' => '语音时长(秒)'])
            ->addColumn('is_recalled', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否撤回'])
            ->addColumn('recall_time', 'timestamp', ['null' => true, 'comment' => '撤回时间'])
            ->addColumn('create_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'comment' => '软删除时间'])
            ->addIndex('user_id')
            ->addIndex('sender_id')
            ->addIndex('type')
            ->addIndex('is_read')
            ->addIndex('create_time')
            ->addIndex(['user_id', 'is_read'], ['name' => 'user_read'])
            ->create();

        // 会话表
        $this->table('sessions')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('session_data', 'text', ['comment' => '会话数据JSON'])
            ->addColumn('expire_time', 'integer', ['comment' => '过期时间'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('expire_time')
            ->create();
    }
}
