<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    protected $fillable = ['base_url', 'api_key', 'model', 'tested_at', 'vision_tested_at', 'created_by'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'tested_at' => 'datetime', 'vision_tested_at' => 'datetime'];
    }

    protected $hidden = ['api_key'];
}
