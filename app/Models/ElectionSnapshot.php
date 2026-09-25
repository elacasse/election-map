<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ElectionSnapshot
 *
 * @property int $id
 * @property int $election_id
 * @property Carbon $captured_at
 * @property string|null $source_etag
 * @property Carbon|null $source_last_modified_at
 * @property Carbon|null $source_updated_at
 * @property string $results_hash
 * @property bool $results_final
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Election $election
 * @property Collection|DistrictResult[] $district_results
 * @property ElectionStatistic|null $election_statistic
 * @property Collection|PartyResult[] $party_results
 *
 * @package App\Models
 */
class ElectionSnapshot extends Model
{
    protected $table = 'election_snapshots';
    protected $casts = [
        'election_id'             => 'int',
        'captured_at'             => 'datetime',
        'source_last_modified_at' => 'datetime',
        'source_updated_at'       => 'datetime',
        'results_final'           => 'bool',
    ];
    protected $fillable = [
        'election_id',
        'captured_at',
        'source_etag',
        'source_last_modified_at',
        'source_updated_at',
        'results_hash',
        'results_final',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class);
    }

    public function districtResults()
    {
        return $this->hasMany(DistrictResult::class, 'snapshot_id');
    }

    public function statistics()
    {
        return $this->hasOne(ElectionStatistic::class, 'snapshot_id');
    }

    public function partyResults()
    {
        return $this->hasMany(PartyResult::class, 'snapshot_id');
    }

    public function candidateResults()
    {
        return $this->hasMany(
            CandidateResult::class,
            'snapshot_id'
        );
    }
}
