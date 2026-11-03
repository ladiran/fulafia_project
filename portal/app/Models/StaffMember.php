<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class StaffMember extends Model
{
    public const CATEGORIES = [
        'academic' => 'Academic Staff',
        'technical' => 'Technical Staff',
        'administrative' => 'Administrative Staff',
    ];

    /** Academic cadres, most senior first. */
    public const CADRES = [
        'Emeritus Professor',
        'Distinguished Professor',
        'Professor',
        'Associate Professor',
        'Senior Lecturer',
        'Lecturer I',
        'Lecturer II',
        'Assistant Lecturer',
        'Graduate Assistant',
    ];

    protected $attributes = ['category' => 'academic'];

    protected $fillable = [
        'user_id', 'category', 'cadre', 'title', 'name', 'position', 'email', 'photo_media_id',
        'research_interests', 'bio', 'publications', 'sort_order', 'is_published',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'photo_media_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fullName(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->name);
    }

    /** The current Head of Department, whatever their own cadre is. */
    public function isHod(): bool
    {
        return $this->category === 'academic' && $this->user?->role === User::ROLE_PRINCIPAL_ADMIN;
    }

    /** What to print under the name: the cadre for academic staff, the position otherwise. */
    public function subtitle(): string
    {
        return collect([$this->isHod() ? 'Head of Department' : null, $this->category === 'academic' ? $this->cadre : null, $this->position])
            ->filter()->unique()->implode(' · ');
    }

    /**
     * Academic order: by cadre, except that the Head of Department always comes straight after the
     * Distinguished Professor(s), whatever the HoD's own cadre. Ties go to the manual order, then the name.
     */
    public function academicRank(): float
    {
        if ($this->isHod()) {
            return array_search('Distinguished Professor', self::CADRES, true) + 0.5;
        }
        $index = array_search($this->cadre, self::CADRES, true);

        return $index === false ? 99 : $index;
    }

    /** @param Collection<int, self> $members */
    public static function ordered(Collection $members): Collection
    {
        return $members->sortBy([
            fn (self $a, self $b) => array_search($a->category, array_keys(self::CATEGORIES)) <=> array_search($b->category, array_keys(self::CATEGORIES)),
            fn (self $a, self $b) => ($a->category === 'academic' ? $a->academicRank() : 0) <=> ($b->category === 'academic' ? $b->academicRank() : 0),
            fn (self $a, self $b) => $a->sort_order <=> $b->sort_order,
            fn (self $a, self $b) => strcasecmp($a->name, $b->name),
        ])->values();
    }

    /** @param Collection<int, self> $members  @return Collection<string, Collection<int, self>> keyed by category, in display order */
    public static function directory(Collection $members): Collection
    {
        $ordered = self::ordered($members)->groupBy('category');

        return collect(array_keys(self::CATEGORIES))->mapWithKeys(fn ($key) => [$key => $ordered->get($key, collect())])
            ->filter(fn ($group) => $group->isNotEmpty());
    }
}
