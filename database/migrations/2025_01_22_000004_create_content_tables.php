<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateContentTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 动态表
        $this->table('moments')
            ->addColumn('user_id', 'integer', ['default' => 1, 'comment' => '用户ID'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'default' => '', 'comment' => '用户昵称'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '用户头像'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '动态内容'])
            ->addColumn('images', 'text', ['null' => true, 'comment' => '图片列表JSON'])
            ->addColumn('videos', 'text', ['null' => true, 'comment' => '视频列表JSON'])
            ->addColumn('location', 'string', ['limit' => 100, 'default' => '', 'comment' => '位置信息'])
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true, 'comment' => '纬度'])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true, 'comment' => '经度'])
            ->addColumn('type', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '动态类型:1文本,2图片,3视频,4链接'])
            ->addColumn('privacy', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '隐私设置:1公开,2私密,3仅好友,4部分可见'])
            ->addColumn('is_top', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否置顶'])
            ->addColumn('top_expire_time', 'timestamp', ['null' => true, 'comment' => '置顶过期时间'])
            ->addColumn('is_recommend', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否推荐'])
            ->addColumn('likes', 'integer', ['default' => 0, 'comment' => '点赞数'])
            ->addColumn('comments', 'integer', ['default' => 0, 'comment' => '评论数'])
            ->addColumn('shares', 'integer', ['default' => 0, 'comment' => '分享数'])
            ->addColumn('views', 'integer', ['default' => 0, 'comment' => '浏览数'])
            ->addColumn('collect_count', 'integer', ['default' => 0, 'comment' => '收藏数'])
            ->addColumn('publish_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '发布时间'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0删除'])
            ->addColumn('is_anonymous', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否匿名发布'])
            ->addIndex('user_id')
            ->addIndex('create_time')
            ->addIndex('status')
            ->addIndex('is_top')
            ->create();

        // 动态媒体表
        $this->table('post_media')
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('media_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '媒体类型:1图片,2视频'])
            ->addColumn('media_url', 'string', ['limit' => 500, 'comment' => '媒体URL'])
            ->addColumn('thumbnail_url', 'string', ['limit' => 500, 'null' => true, 'comment' => '缩略图URL'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('moment_id')
            ->addIndex('media_type')
            ->create();

        // 动态评论表
        $this->table('comments')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('parent_id', 'integer', ['default' => 0, 'comment' => '父评论ID,0表示顶级评论'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'comment' => '用户昵称'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'null' => true, 'comment' => '用户头像'])
            ->addColumn('content', 'text', ['comment' => '评论内容'])
            ->addColumn('likes', 'integer', ['default' => 0, 'comment' => '点赞数'])
            ->addColumn('replies', 'integer', ['default' => 0, 'comment' => '回复数'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0删除'])
            ->addColumn('create_time', 'integer', ['comment' => '创建时间'])
            ->addIndex('moment_id')
            ->addIndex('parent_id')
            ->addIndex('user_id')
            ->create();

        // 动态点赞表
        $this->table('moment_likes')
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('create_time', 'integer', ['comment' => '点赞时间'])
            ->addIndex('moment_id', ['name' => 'idx_moment_id'])
            ->addIndex('user_id', ['name' => 'idx_user_id'])
            ->create();

        // 动态收藏表
        $this->table('collections')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('create_time', 'integer', ['comment' => '收藏时间'])
            ->addIndex('user_id')
            ->addIndex('moment_id')
            ->create();

        // 通用点赞表
        $this->table('likes')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('target_id', 'integer', ['comment' => '目标ID'])
            ->addColumn('target_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '目标类型:1动态,2评论'])
            ->addColumn('create_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'comment' => '软删除时间'])
            ->addIndex('user_id')
            ->addIndex('target_id')
            ->addIndex('target_type')
            ->addIndex('create_time')
            ->create();

        // 分享表
        $this->table('shares')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('target_id', 'integer', ['comment' => '目标ID'])
            ->addColumn('target_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '目标类型:1动态,2评论'])
            ->addColumn('share_type', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '分享类型:1朋友圈,2QQ,3微博,4链接'])
            ->addColumn('create_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('target_id')
            ->addIndex('target_type')
            ->addIndex('share_type')
            ->addIndex('create_time')
            ->create();
    }
}
