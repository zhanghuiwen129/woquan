<?php

use think\migration\Migrator;

class AddCanSpeakFieldToUserTable extends Migrator
{
    public function change()
    {
        $table = $this->table('user');

        if (!$table->hasColumn('can_speak')) {
            $table->addColumn('can_speak', 'integer', [
                    'limit' => 1,
                    'default' => 1,
                    'signed' => false,
                    'comment' => '是否允许发言:1允许,0禁止',
                    'after' => 'status'
                ])
                ->update();
        }
    }

    public function down()
    {
        $table = $this->table('user');
        if ($table->hasColumn('can_speak')) {
            $table->removeColumn('can_speak')
                ->update();
        }
    }
}
