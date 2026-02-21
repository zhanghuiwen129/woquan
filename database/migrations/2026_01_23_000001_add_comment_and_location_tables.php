<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddCommentAndLocationTables extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Any other destructive changes will result in an error when trying to
     * rollback the migration.
     */

    public function change()
    {
        // 向用户表添加位置字段
        $table = $this->table('user');

        // 添加用户位置字段
        $table->addColumn('last_latitude', 'decimal', [
                'after' => 'can_speak',
                'null' => true,
                'precision' => 10,
                'scale' => 7,
                'comment' => '最后位置-纬度',
                'default' => null
            ])
            ->addColumn('last_longitude', 'decimal', [
                'after' => 'last_latitude',
                'null' => true,
                'precision' => 10,
                'scale' => 7,
                'comment' => '最后位置-经度',
                'default' => null
            ])
            ->addColumn('last_city', 'string', [
                'after' => 'last_longitude',
                'null' => true,
                'limit' => 50,
                'comment' => '最后位置-城市',
                'default' => null
            ])
            ->addColumn('last_location_time', 'integer', [
                'after' => 'last_city',
                'null' => true,
                'signed' => true,
                'comment' => '最后位置更新时间',
                'default' => null
            ])
            ->update();

        // 创建评论点赞表
        $this->table('comment_likes', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '评论点赞表'
            ])
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => '主键ID'
            ])
            ->addColumn('user_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '用户ID'
            ])
            ->addColumn('comment_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '评论ID'
            ])
            ->addColumn('create_time', 'integer', [
                'null' => false,
                'signed' => true,
                'default' => 0,
                'comment' => '点赞时间'
            ])
            ->addIndex(['user_id', 'comment_id'], ['unique' => true, 'name' => 'user_comment'])
            ->addIndex(['comment_id'], ['name' => 'comment_id'])
            ->create();

        // 创建用户位置记录表
        $this->table('user_locations', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '用户位置记录表'
            ])
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => '主键ID'
            ])
            ->addColumn('user_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '用户ID'
            ])
            ->addColumn('latitude', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 7,
                'signed' => true,
                'comment' => '纬度'
            ])
            ->addColumn('longitude', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 7,
                'signed' => true,
                'comment' => '经度'
            ])
            ->addColumn('address', 'string', [
                'null' => true,
                'limit' => 255,
                'comment' => '详细地址',
                'default' => null
            ])
            ->addColumn('city', 'string', [
                'null' => true,
                'limit' => 50,
                'comment' => '城市',
                'default' => null
            ])
            ->addColumn('district', 'string', [
                'null' => true,
                'limit' => 50,
                'comment' => '区县',
                'default' => null
            ])
            ->addColumn('ip', 'string', [
                'null' => true,
                'limit' => 45,
                'comment' => 'IP地址',
                'default' => null
            ])
            ->addColumn('create_time', 'integer', [
                'null' => true,
                'signed' => true,
                'comment' => '创建时间',
                'default' => null
            ])
            ->addColumn('update_time', 'integer', [
                'null' => true,
                'signed' => true,
                'comment' => '更新时间',
                'default' => null
            ])
            ->addIndex(['user_id'], ['name' => 'user_id'])
            ->addIndex(['latitude'], ['name' => 'latitude'])
            ->addIndex(['longitude'], ['name' => 'longitude'])
            ->addIndex(['city'], ['name' => 'city'])
            ->addIndex(['create_time'], ['name' => 'create_time'])
            ->create();
    }

    /**
     * Down Method.
     */
    public function down()
    {
        // 回滚时删除新增的表
        $this->table('comment_likes')->drop()->save();
        $this->table('user_locations')->drop()->save();

        // 回滚时删除user表新增的字段
        $this->table('user')
            ->removeColumn('last_latitude')
            ->removeColumn('last_longitude')
            ->removeColumn('last_city')
            ->removeColumn('last_location_time')
            ->update();
    }
}
