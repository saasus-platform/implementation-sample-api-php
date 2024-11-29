<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeleteUserLog extends Model
{
    protected $table = 'delete_user_log';
    protected $fillable = ['tenant_id', 'user_id', 'email'];
    public $timestamps = false;
}
