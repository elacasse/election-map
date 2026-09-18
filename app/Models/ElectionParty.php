<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ElectionParty
 * 
 * @property int $id
 * @property int $election_id
 * @property int $source_party_number
 * @property string $name
 * @property string $abbreviation
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Election $election
 * @property Collection|Candidate[] $candidates
 * @property Collection|PartyResult[] $party_results
 *
 * @package App\Models
 */
class ElectionParty extends Model
{
	protected $table = 'election_parties';

	protected $casts = [
		'election_id' => 'int',
		'source_party_number' => 'int'
	];

	protected $fillable = [
		'election_id',
		'source_party_number',
		'name',
		'abbreviation',
		'color'
	];

	public function election()
	{
		return $this->belongsTo(Election::class);
	}

	public function candidates()
	{
		return $this->hasMany(Candidate::class);
	}

	public function party_results()
	{
		return $this->hasMany(PartyResult::class);
	}
}
