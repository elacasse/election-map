<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DistrictResult
 *
 * @property int $id
 * @property int $snapshot_id
 * @property int $electoral_district_id
 * @property int $polling_station_completed_count
 * @property int $polling_station_count
 * @property int $valid_vote_count
 * @property int $rejected_vote_count
 * @property int $cast_vote_count
 * @property int $registered_voter_count
 * @property float $valid_vote_rate
 * @property float $rejected_vote_rate
 * @property float $participation_rate
 * @property bool $results_final
 * @property Carbon|null $source_updated_at
 *
 * @property ElectoralDistrict $electoral_district
 * @property ElectionSnapshot $election_snapshot
 *
 * @package App\Models
 */
class DistrictResult extends Model
{
    protected $table = 'district_results';
    public $timestamps = false;

    protected $casts = [
        'snapshot_id' => 'int',
        'electoral_district_id' => 'int',
        'polling_station_completed_count' => 'int',
        'polling_station_count' => 'int',
        'valid_vote_count' => 'int',
        'rejected_vote_count' => 'int',
        'cast_vote_count' => 'int',
        'registered_voter_count' => 'int',
        'valid_vote_rate' => 'decimal:4',
        'rejected_vote_rate' => 'decimal:4',
        'participation_rate' => 'decimal:4',
        'results_final' => 'bool',
        'source_updated_at' => 'datetime'
    ];

    protected $fillable = [
        'snapshot_id',
        'electoral_district_id',
        'polling_station_completed_count',
        'polling_station_count',
        'valid_vote_count',
        'rejected_vote_count',
        'cast_vote_count',
        'registered_voter_count',
        'valid_vote_rate',
        'rejected_vote_rate',
        'participation_rate',
        'results_final',
        'source_updated_at'
    ];

    public function electoralDistrict()
    {
        return $this->belongsTo(ElectoralDistrict::class);
    }

    public function snapshot()
    {
        return $this->belongsTo(ElectionSnapshot::class, 'snapshot_id');
    }
}
