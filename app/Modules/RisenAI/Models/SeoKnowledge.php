<?php

namespace App\Modules\RisenAI\Models;

use Illuminate\Database\Eloquent\Model;

class SeoKnowledge extends Model
{
    protected $table = 'seo_knowledge_base';

    protected $fillable = [
        'category',
        'topic',
        'content',
        'keywords',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
