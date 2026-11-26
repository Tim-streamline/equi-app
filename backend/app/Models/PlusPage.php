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
        $priceCents = (int) ($this->protocol_price_cents ?? 14700);
        $content = array_replace(static::defaults(), $this->content, ['price' => '€ '.number_format($priceCents / 100, $priceCents % 100 === 0 ? 0 : 2, ',', '.')]);
        return ['protocolPriceCents' => $priceCents,
            'content' => $content,
            'memberFaqVideo' => static::libraryItemTitled($content['memberFaqVideoTitle'] ?? null),
            'heroImageUrl' => '/api/protocol-page/images/hero?v='.$heroVersion,
            'portraitImageUrl' => $this->portrait_path ? '/api/protocol-page/images/portrait?v='.$portraitVersion : null];
    }

    /** The FAQ video is found by its library title, so the editor can swap it without a developer. */
    private static function libraryItemTitled(?string $title): ?array
    {
        $title = mb_strtolower(trim((string) $title));
        $item = $title === '' ? null : LibraryItem::query()
            ->whereRaw('lower(trim(title)) = ?', [$title])
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->first(['id', 'format', 'title']);

        return $item ? ['id' => $item->id, 'format' => $item->format, 'title' => $item->title] : null;
    }
}
