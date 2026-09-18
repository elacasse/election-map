<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class ElectionStatistic
 * 
 * @property int $snapshot_id
 * @property int $polling_station_count
 * @property int $polling_station_completed_count
 * @property float $polling_station_completed_rate
 * @property int $valid_vote_count
 * @property int $rejected_vote_count
 * @property int $cast_vote_count
 * @property int $registered_voter_count
 * @property float $participation_rate
 * @property int $electoral_district_count
 * @property int $electoral_district_with_result_count
 * @property int $electoral_district_without_result_count
 * @property float $electoral_district_without_result_rate
 * 
 * @property ElectionSnapshot $election_snapshot
 *
 * @package App\Models
 */
class ElectionStatistic extends Model
{
	protected $table = 'election_statistics';
	protected $primaryKey = 'snapshot_id';
	public $incrementing = false;
	public $timestamps = false;

	protected $casts = [
		'snapshot_id' => 'int',
		'polling_station_count' => 'int',
		'polling_station_completed_count' => 'int',
		'polling_station_completed_rate' => 'float',
		'valid_vote_count' => 'int',
		'rejected_vote_count' => 'int',
		'cast_vote_count' => 'int',
		'registered_voter_count' => 'int',
		'participation_rate' => 'float',
		'electoral_district_count' => 'int',
		'electoral_district_with_result_count' => 'int',
		'electoral_district_without_result_count' => 'int',
		'electoral_district_without_result_rate' => 'float'
	];

	protected $fillable = [
		'polling_station_count',
		'polling_station_completed_count',
		'polling_station_completed_rate',
		'valid_vote_count',
		'rejected_vote_count',
		'cast_vote_count',
		'registered_voter_count',
		'participation_rate',
		'electoral_district_count',
		'electoral_district_with_result_count',
		'electoral_district_without_result_count',
		'electoral_district_without_result_rate'
	];

	public function election_snapshot()
	{
		return $this->belongsTo(ElectionSnapshot::class, 'snapshot_id');
	}
}
