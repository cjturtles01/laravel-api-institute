<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordPressPost extends Model
{
    protected $connection = 'wordpress';
    protected $table = 'posts'; // Your actual WP posts table name
    protected $primaryKey = 'ID';
    public $timestamps = false;

    protected $fillable = [
        'post_title',
        'post_content',
        'post_status',
        'post_type',
        'post_author',
        'post_date',
    ];
}
