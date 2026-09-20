<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class PartyResult
 *
 * @property int $id
 * @property int $snapshot_id
 * @property int $election_party_id
 * @property int $vote_count
 * @property float $vote_rate
 * @property int $leading_district_count
 * @property float $leading_district_rate
 *
 * @property ElectionParty $election_party
 * @property ElectionSnapshot $election_snapshot
 *
 * @package App\Models
 */
class PartyResult extends Model
{
    protected $table = 'party_results';
    public $timestamps = false;

    protected $casts = [
        'snapshot_id' => 'int',
        'election_party_id' => 'int',
        'vote_count' => 'int',
        'vote_rate' => 'decimal:4',
        'leading_district_count' => 'int',
        'leading_district_rate' => 'decimal:4'
    ];

    protected $fillable = [
        'snapshot_id',
        'election_party_id',
        'vote_count',
        'vote_rate',
        'leading_district_count',
        'leading_district_rate'
    ];

    public function party()
    {
        return $this->belongsTo(ElectionParty::class, 'election_party_id');
    }

    public function snapshot()
    {
        return $this->belongsTo(ElectionSnapshot::class, 'snapshot_id');
    }
}
