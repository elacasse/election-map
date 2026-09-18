<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ElectoralDistrict
 * 
 * @property int $id
 * @property int $election_id
 * @property int $source_district_number
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Election $election
 * @property Collection|Candidate[] $candidates
 * @property Collection|DistrictResult[] $district_results
 *
 * @package App\Models
 */
class ElectoralDistrict extends Model
{
	protected $table = 'electoral_districts';

	protected $casts = [
		'election_id' => 'int',
		'source_district_number' => 'int'
	];

	protected $fillable = [
		'election_id',
		'source_district_number',
		'name'
	];

	public function election()
	{
		return $this->belongsTo(Election::class);
	}

	public function candidates()
	{
		return $this->hasMany(Candidate::class);
	}

	public function district_results()
	{
		return $this->hasMany(DistrictResult::class);
	}
}
