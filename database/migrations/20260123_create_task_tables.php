<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateTaskTables extends Migrator
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 创建任务表
        $this->create('task', function (\think\migration\db\Table $table) {
            $table->increments('id');
            $table->string('name', 100)->comment('任务名称');
            $table->string('description', 500)->comment('任务描述');
            $table->integer('points')->default(0)->comment('任务积分');
            $table->string('type', 20)->comment('任务类型：daily, growth');
            $table->string('icon', 50)->default('fa-tasks')->comment('任务图标');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('状态：1激活，0未激活');
            $table->timestamps();
        });
        
        // 创建任务记录表
        $this->create('task_record', function (\think\migration\db\Table $table) {
            $table->increments('id');
            $table->integer('user_id')->comment('用户ID');
            $table->integer('task_id')->comment('任务ID');
            $table->tinyInteger('status')->default(1)->comment('状态：1已完成，0未完成');
            $table->integer('create_time')->comment('创建时间');
            $table->index(['user_id', 'task_id']);
        });
        
        // 插入默认任务数据
        $this->insertDefaultTasks();
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->dropTable('task_record');
        $this->dropTable('task');
    }
    
    /**
     * 插入默认任务数据
     */
    protected function insertDefaultTasks()
    {
        // 插入每日任务
        $dailyTasks = [
            ['name' => '每日登录', 'description' => '每天登录一次即可获得积分', 'points' => 5, 'type' => 'daily', 'icon' => 'fa-sign-in', 'sort' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => '发布动态', 'description' => '发布一条新动态', 'points' => 10, 'type' => 'daily', 'icon' => 'fa-pencil', 'sort' => 2, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => '评论互动', 'description' => '评论3条动态', 'points' => 8, 'type' => 'daily', 'icon' => 'fa-comment', 'sort' => 3, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => '点赞动态', 'description' => '点赞5条动态', 'points' => 5, 'type' => 'daily', 'icon' => 'fa-thumbs-up', 'sort' => 4, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ];
        
        // 插入成长任务
        $growthTasks = [
            ['name' => '完善个人资料', 'description' => '完善个人头像和昵称', 'points' => 20, 'type' => 'growth', 'icon' => 'fa-user', 'sort' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => '首次关注', 'description' => '关注3个用户', 'points' => 15, 'type' => 'growth', 'icon' => 'fa-heart', 'sort' => 2, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => '首次发布', 'description' => '发布第一条动态', 'points' => 25, 'type' => 'growth', 'icon' => 'fa-star', 'sort' => 3, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ];
        
        // 执行插入
        $this->batchInsert('task', $dailyTasks);
        $this->batchInsert('task', $growthTasks);
    }
}
