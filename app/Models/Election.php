<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class Election
 *
 * @property int $id
 * @property int $year
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Collection|Candidate[] $candidates
 * @property Collection|ElectionParty[] $election_parties
 * @property Collection|ElectionSnapshot[] $election_snapshots
 * @property Collection|ElectoralDistrict[] $electoral_districts
 *
 * @package App\Models
 */
class Election extends Model
{
    use HasFactory;

    protected $table = 'elections';
    protected $casts = [
        'year' => 'int',
    ];
    protected $fillable = [
        'year',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class);
    }

    public function parties()
    {
        return $this->hasMany(ElectionParty::class);
    }

    public function snapshots()
    {
        return $this->hasMany(ElectionSnapshot::class);
    }

    public function districts()
    {
        return $this->hasMany(ElectoralDistrict::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(ElectionSnapshot::class)
            ->latestOfMany();
    }
}
