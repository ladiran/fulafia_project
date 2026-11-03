<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested correction to a graduate's permanent record. Never deleted. Once decided it can no
 * longer change, so the history of who asked for what and who approved it stays as it was.
 */
class GraduateAmendment extends Model
{
    protected $attributes = ['status' => 'requested'];

    protected $fillable = [
        'graduate_id', 'kind', 'field', 'graduate_result_id', 'old_value', 'new_value', 'reason',
        'requested_by', 'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['old_value' => 'array', 'new_value' => 'array', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Corrections are part of the permanent record and cannot be deleted.'));
        static::updating(function (self $amendment) {
            if ($amendment->getOriginal('status') !== 'requested') {
                throw new \LogicException('A decided correction cannot be changed.');
            }
        });
    }

    public function graduate(): BelongsTo
    {
        return $this->belongsTo(Graduate::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(GraduateResult::class, 'graduate_result_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /** One line for lists: what was changed, from what, to what. */
    public function summary(): string
    {
        if ($this->kind === 'field') {
            $label = \App\Services\GraduateAmendmentService::FIELDS[$this->field] ?? $this->field;

            return "{$label}: ".($this->old_value['value'] ?? '(not set)').' to '.($this->new_value['value'] ?? '(blank)');
        }

        $code = $this->result?->course_code ?? 'course';
        $fmt = fn (?array $v) => $v ? collect([
            'CA 1' => $v['ca'] ?? null, 'CA 2' => $v['ca2'] ?? null, 'Exam' => $v['exam'] ?? null, 'Flag' => $v['flag'] ?? null,
        ])->filter(fn ($x) => $x !== null && $x !== '')->map(fn ($x, $k) => "{$k} {$x}")->implode(', ') : '(not recorded)';

        return "{$code}: ".$fmt($this->old_value).' to '.$fmt($this->new_value);
    }
}
