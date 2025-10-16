<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationCompare extends Model
{
    protected $table = 'regulation_compares';

    protected $fillable = [
        'uuid',
        'title',
        'old_url',
        'new_url',
        'meta',
        'summary',
        'changes',
    ];

    protected $casts = [
        'meta' => 'array',
        'summary' => 'array',
        'changes' => 'array',
    ];
}
