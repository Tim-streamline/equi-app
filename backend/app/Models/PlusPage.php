<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlusPage extends Model
{
    public const PAGE_ID = '7f3098ab-d3de-440e-a6a4-ec9a482172c4';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected function casts(): array { return ['content' => 'array']; }

    public static function defaults(): array
    {
        return json_decode(file_get_contents(resource_path('plus/content.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function current(): self
    {
        return static::find(self::PAGE_ID) ?? new static(['id' => self::PAGE_ID, 'content' => static::defaults()]);
    }

    public function payload(): array
    {
        $heroVersion = substr(hash('sha256', $this->hero_path ?? 'bundled-v1'), 0, 16);
        $portraitVersion = substr(hash('sha256', $this->portrait_path ?? ''), 0, 16);
        return ['content' => $this->content,
            'heroImageUrl' => '/api/plus-page/images/hero?v='.$heroVersion,
            'portraitImageUrl' => $this->portrait_path ? '/api/plus-page/images/portrait?v='.$portraitVersion : null];
    }
}
