<?php

namespace app\model;

use think\Model;

class Favorite extends Model
{
    protected $name = 'favorites';
    protected $autoWriteTimestamp = false;
}
