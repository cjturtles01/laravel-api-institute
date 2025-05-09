<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersModel extends Model
{
    protected $connection = 'wordpress';
    protected $table = 'users'; // Your actual WP posts table name
    protected $primaryKey = 'ID';
    public $timestamps = false;

    protected $fillable = [
        'user_login',
        'user_pass',
        'user_nicename',
        'user_email',
        'user_registered',
        'user_activation_key',
        'user_status',
        'display_name'
    ];

    
}
