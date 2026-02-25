<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateOtherTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 上传文件表
        $this->table('uploads')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('file_name', 'string', ['limit' => 255, 'comment' => '文件名'])
            ->addColumn('file_path', 'string', ['limit' => 500, 'comment' => '文件路径'])
            ->addColumn('file_url', 'string', ['limit' => 500, 'comment' => '文件URL'])
            ->addColumn('file_size', 'integer', ['comment' => '文件大小'])
            ->addColumn('file_type', 'string', ['limit' => 50, 'comment' => '文件类型'])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'comment' => 'MIME类型'])
            ->addColumn('file_ext', 'string', ['limit' => 20, 'comment' => '文件扩展名'])
            ->addColumn('storage', 'string', ['limit' => 20, 'default' => 'local', 'comment' => '存储方式:local,oss,cos,qiniu'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('file_type')
            ->addIndex('create_time')
            ->create();

        // 链接表
        $this->table('link')
            ->addColumn('url', 'string', ['limit' => 500, 'comment' => '链接地址'])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '链接标题'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true, 'comment' => '链接描述'])
            ->addColumn('image', 'string', ['limit' => 500, 'null' => true, 'comment' => '链接图片'])
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('create_time')
            ->create();

        // 登录日志表
        $this->table('login_logs')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('login_type', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '登录类型:1-账号密码,2-手机验证码,3-微信,4-QQ'])
            ->addColumn('login_ip', 'string', ['limit' => 45, 'comment' => '登录IP'])
            ->addColumn('login_location', 'string', ['limit' => 100, 'null' => true, 'comment' => '登录地点'])
            ->addColumn('device_type', 'string', ['limit' => 20, 'default' => 'web', 'comment' => '设备类型:web,ios,android'])
            ->addColumn('user_agent', 'string', ['limit' => 500, 'null' => true, 'comment' => '用户代理'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1-成功,0-失败'])
            ->addColumn('fail_reason', 'string', ['limit' => 200, 'null' => true, 'comment' => '失败原因'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('login_ip')
            ->addIndex('create_time')
            ->create();

        // 名片访问记录表
        $this->table('card_visitors')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID(名片主人)'])
            ->addColumn('visitor_id', 'integer', ['comment' => '访客ID'])
            ->addColumn('visitor_nickname', 'string', ['limit' => 50, 'null' => true, 'comment' => '访客昵称'])
            ->addColumn('visitor_avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '访客头像'])
            ->addColumn('visit_time', 'integer', ['default' => 0, 'comment' => '访问时间'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('visitor_id')
            ->addIndex('visit_time')
            ->create();

        // 访客记录表
        $this->table('visitors')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('visitor_id', 'integer', ['comment' => '访客ID'])
            ->addColumn('visitor_nickname', 'string', ['limit' => 50, 'null' => true, 'comment' => '访客昵称'])
            ->addColumn('visitor_avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '访客头像'])
            ->addColumn('visit_count', 'integer', ['default' => 1, 'comment' => '访问次数'])
            ->addColumn('last_visit_time', 'integer', ['default' => 0, 'comment' => '最后访问时间'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('visitor_id')
            ->addIndex('last_visit_time')
            ->create();

        // 用户违规记录表
        $this->table('user_violations')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('violation_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '违规类型:1-发布违规内容,2-骚扰他人,3-恶意举报'])
            ->addColumn('violation_content', 'string', ['limit' => 500, 'comment' => '违规内容'])
            ->addColumn('reporter_id', 'integer', ['null' => true, 'comment' => '举报人ID'])
            ->addColumn('handler_id', 'integer', ['null' => true, 'comment' => '处理人ID'])
            ->addColumn('handle_result', 'string', ['limit' => 200, 'null' => true, 'comment' => '处理结果'])
            ->addColumn('handle_time', 'integer', ['null' => true, 'comment' => '处理时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '状态:0-待处理,1-已处理'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('violation_type')
            ->addIndex('status')
            ->addIndex('create_time')
            ->create();

        // 用户处罚记录表
        $this->table('user_punishments')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('punishment_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '处罚类型:1-警告,2-禁言,3-封禁'])
            ->addColumn('punishment_reason', 'string', ['limit' => 500, 'comment' => '处罚原因'])
            ->addColumn('start_time', 'integer', ['comment' => '开始时间'])
            ->addColumn('end_time', 'integer', ['null' => true, 'comment' => '结束时间'])
            ->addColumn('handler_id', 'integer', ['comment' => '处理人ID'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1-生效中,0-已解除'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('punishment_type')
            ->addIndex('status')
            ->addIndex('create_time')
            ->create();
    }
}
