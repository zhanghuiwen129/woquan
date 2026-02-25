<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateFavoriteAndTopicTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 收藏表
        $this->table('favorites')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('target_id', 'integer', ['comment' => '目标ID'])
            ->addColumn('target_type', 'integer', ['limit' => 1, 'signed' => false, 'comment' => '目标类型:1-动态,2-文章,3-商品'])
            ->addColumn('folder_id', 'integer', ['null' => true, 'comment' => '收藏夹ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('target_id')
            ->addIndex('target_type')
            ->addIndex('folder_id')
            ->create();

        // 收藏夹表
        $this->table('favorite_folders')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('folder_name', 'string', ['limit' => 50, 'comment' => '收藏夹名称'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '收藏夹描述'])
            ->addColumn('cover_image', 'string', ['limit' => 500, 'null' => true, 'comment' => '封面图'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('is_public', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否公开:0否,1是'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('user_id')
            ->addIndex('sort')
            ->create();

        // 话题表
        $this->table('topics')
            ->addColumn('topic_name', 'string', ['limit' => 50, 'comment' => '话题名称'])
            ->addColumn('topic_image', 'string', ['limit' => 500, 'null' => true, 'comment' => '话题图片'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true, 'comment' => '话题描述'])
            ->addColumn('moment_count', 'integer', ['default' => 0, 'comment' => '动态数量'])
            ->addColumn('follow_count', 'integer', ['default' => 0, 'comment' => '关注人数'])
            ->addColumn('is_hot', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否热门:0否,1是'])
            ->addColumn('is_recommend', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否推荐:0否,1是'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0禁用'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('topic_name')
            ->addIndex('is_hot')
            ->addIndex('is_recommend')
            ->addIndex('sort')
            ->addIndex('status')
            ->create();

        // 话题关注表
        $this->table('topic_follows')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('topic_id', 'integer', ['comment' => '话题ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('topic_id')
            ->addIndex('create_time')
            ->create();

        // 动态话题关联表
        $this->table('moment_topics')
            ->addColumn('moment_id', 'integer', ['comment' => '动态ID'])
            ->addColumn('topic_id', 'integer', ['comment' => '话题ID'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('moment_id')
            ->addIndex('topic_id')
            ->create();

        // 文章表
        $this->table('essay')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '文章标题'])
            ->addColumn('content', 'text', ['comment' => '文章内容'])
            ->addColumn('cover_image', 'string', ['limit' => 500, 'null' => true, 'comment' => '封面图'])
            ->addColumn('category', 'string', ['limit' => 50, 'null' => true, 'comment' => '分类'])
            ->addColumn('tags', 'text', ['null' => true, 'comment' => '标签JSON'])
            ->addColumn('view_count', 'integer', ['default' => 0, 'comment' => '浏览数'])
            ->addColumn('like_count', 'integer', ['default' => 0, 'comment' => '点赞数'])
            ->addColumn('comment_count', 'integer', ['default' => 0, 'comment' => '评论数'])
            ->addColumn('collect_count', 'integer', ['default' => 0, 'comment' => '收藏数'])
            ->addColumn('is_top', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否置顶:0否,1是'])
            ->addColumn('is_recommend', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否推荐:0否,1是'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1正常,0删除'])
            ->addColumn('publish_time', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '发布时间'])
            ->addColumn('create_time', 'integer', ['null' => true, 'comment' => '创建时间'])
            ->addIndex('user_id')
            ->addIndex('category')
            ->addIndex('status')
            ->addIndex('is_top')
            ->addIndex('is_recommend')
            ->addIndex('publish_time')
            ->create();

        // 动态草稿表
        $this->table('moment_drafts')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '动态内容'])
            ->addColumn('images', 'text', ['null' => true, 'comment' => '图片列表JSON'])
            ->addColumn('videos', 'text', ['null' => true, 'comment' => '视频列表JSON'])
            ->addColumn('location', 'string', ['limit' => 100, 'null' => true, 'comment' => '位置信息'])
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true, 'comment' => '纬度'])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true, 'comment' => '经度'])
            ->addColumn('type', 'integer', ['limit' => 1, 'null' => true, 'signed' => false, 'comment' => '动态类型:1文本,2图片,3视频,4链接'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('user_id')
            ->addIndex('create_time')
            ->create();
    }
}
