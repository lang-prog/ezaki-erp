<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function boolean(string $key, bool $default = false): bool
    {
        if (! Schema::hasTable('platform_settings')) {
            return $default;
        }

        $value = static::query()->find($key)?->value;

        return is_array($value) ? (bool) ($value['enabled'] ?? $default) : $default;
    }
}
