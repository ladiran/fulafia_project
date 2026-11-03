<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Editable public-site settings, with defaults from config/portal.php. */
class SiteSetting extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    /** Setting keys the admin can edit, with their labels. */
    public const KEYS = [
        'hero_title' => 'Home page title',
        'hero_text' => 'Home page introduction',
        'address' => 'Address',
        'phone' => 'Phone',
        'email' => 'Public email',
        'office_hours' => 'Office hours',
    ];

    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$cache ??= static::pluck('value', 'key')->all();

        return self::$cache[$key] ?? $default ?? config("portal.site.{$key}");
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
