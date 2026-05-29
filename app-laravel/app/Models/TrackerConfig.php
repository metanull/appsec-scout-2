<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackerConfig extends Model
{
    protected $table = 'tracker_config';

    protected $fillable = ['key', 'value'];
}
