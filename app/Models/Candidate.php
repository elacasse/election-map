<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Candidate
 *
 * @property int $id
 * @property int $election_id
 * @property int $electoral_district_id
 * @property int|null $election_party_id
 * @property int $source_candidate_number
 * @property string $last_name
 * @property string $first_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Election $election
 * @property ElectionParty|null $election_party
 * @property ElectoralDistrict $electoral_district
 *
 * @package App\Models
 */
class Candidate extends Model
{
    protected $table = 'candidates';
    protected $casts = [
        'election_id'             => 'int',
        'electoral_district_id'   => 'int',
        'election_party_id'       => 'int',
        'source_candidate_number' => 'int',
    ];
    protected $fillable = [
        'election_id',
        'electoral_district_id',
        'election_party_id',
        'source_candidate_number',
        'last_name',
        'first_name',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class);
    }

    public function party()
    {
        return $this->belongsTo(
            ElectionParty::class,
            'election_party_id'
        );
    }

    public function district()
    {
        return $this->belongsTo(
            ElectoralDistrict::class,
            'electoral_district_id'
        );
    }

    public function results()
    {
        return $this->hasMany(CandidateResult::class);
    }
}
