<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateThemeTables extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 主题模板表
        $this->table('theme_templates')
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '主题名称'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '主题描述'])
            ->addColumn('preview_image', 'string', ['limit' => 500, 'null' => true, 'comment' => '预览图'])
            ->addColumn('css_file', 'string', ['limit' => 255, 'null' => true, 'comment' => 'CSS文件路径'])
            ->addColumn('js_file', 'string', ['limit' => 255, 'null' => true, 'comment' => 'JS文件路径'])
            ->addColumn('is_system', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否系统主题:0否,1是'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1启用,0禁用'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('is_system')
            ->addIndex('status')
            ->addIndex('sort')
            ->create();

        // 主题规则表
        $this->table('theme_rules')
            ->addColumn('theme_id', 'integer', ['comment' => '主题ID'])
            ->addColumn('rule_name', 'string', ['limit' => 100, 'comment' => '规则名称'])
            ->addColumn('rule_type', 'string', ['limit' => 20, 'comment' => '规则类型'])
            ->addColumn('rule_value', 'text', ['comment' => '规则值JSON'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('theme_id')
            ->create();

        // 用户主题表
        $this->table('user_themes')
            ->addColumn('user_id', 'integer', ['comment' => '用户ID'])
            ->addColumn('theme_id', 'integer', ['comment' => '主题ID'])
            ->addColumn('custom_css', 'text', ['null' => true, 'comment' => '自定义CSS'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex('user_id')
            ->addIndex('theme_id')
            ->create();

        // 名片模板表
        $this->table('card_templates')
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '模板名称'])
            ->addColumn('description', 'string', ['limit' => 200, 'null' => true, 'comment' => '模板描述'])
            ->addColumn('preview_image', 'string', ['limit' => 500, 'null' => true, 'comment' => '预览图'])
            ->addColumn('template_code', 'text', ['comment' => '模板代码'])
            ->addColumn('theme_color', 'string', ['limit' => 20, 'default' => '#1890ff', 'comment' => '主题色'])
            ->addColumn('is_system', 'integer', ['limit' => 1, 'default' => 0, 'signed' => false, 'comment' => '是否系统模板:0否,1是'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态:1启用,0禁用'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addIndex('is_system')
            ->addIndex('status')
            ->addIndex('sort')
            ->create();
    }
}
