<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'candidate_id',
        'vote_count',
        'vote_rate',
        'advance_vote_count',
    ];

    protected $casts = [
        'snapshot_id' => 'integer',
        'candidate_id' => 'integer',

        'vote_count' => 'integer',
        'vote_rate' => 'decimal:4',
        'advance_vote_count' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(
            ElectionSnapshot::class,
            'snapshot_id'
        );
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
