<?php

namespace App\Console\Commands;

use App\Models\CandidateResult;
use App\Models\Election;
use App\Models\ElectionSnapshot;
use App\Models\PartyResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('app:backfill-won-district-counts {year?}')]
#[Description('Recalculate won district counts for existing election snapshots')]
class BackfillWonDistrictCounts extends Command
{
    public function handle(): int
    {
        $year = $this->argument('year');

        $query = Election::query();

        if ($year !== null) {
            $query->where('year', (int) $year);
        }

        $elections = $query
            ->orderBy('year')
            ->get();

        if ($elections->isEmpty()) {
            $this->error(
                $year !== null
                    ? "Election {$year} not found."
                    : 'No elections found.'
            );

            return self::FAILURE;
        }

        foreach ($elections as $election) {
            /** @var Election $election */

            $this->info(
                "Recalculating election {$election->year}..."
            );

            ElectionSnapshot::query()
                ->where('election_id', $election->id)
                ->orderBy('id')
                ->eachById(function (ElectionSnapshot $snapshot): void {
                    $this->backfillSnapshot($snapshot);
                });
        }

        $this->info('Won district counts recalculated successfully.');

        return self::SUCCESS;
    }

    private function backfillSnapshot(
        ElectionSnapshot $snapshot
    ): void {
        DB::transaction(function () use ($snapshot): void {
            $winnerPartyIds = CandidateResult::query()
                ->select([
                    'candidate_results.vote_count',
                    'candidates.electoral_district_id as district_id',
                    'candidates.election_party_id as party_id',
                ])
                ->join(
                    'candidates',
                    'candidates.id',
                    '=',
                    'candidate_results.candidate_id'
                )
                ->join(
                    'district_results',
                    function ($join): void {
                        $join
                            ->on(
                                'district_results.snapshot_id',
                                '=',
                                'candidate_results.snapshot_id'
                            )
                            ->on(
                                'district_results.electoral_district_id',
                                '=',
                                'candidates.electoral_district_id'
                            );
                    }
                )
                ->where(
                    'candidate_results.snapshot_id',
                    $snapshot->id
                )
                ->where(
                    'district_results.results_final',
                    true
                )
                ->orderBy('district_id')
                ->orderByDesc('candidate_results.vote_count')
                ->get()
                ->groupBy('district_id')
                ->map(
                    function (Collection $results): ?int {
                        /** @var CandidateResult|null $result */
                        $result = $results->first();

                        return $result === null
                            ? null
                            : (int) $result->getAttribute('party_id');
                    }
                )
                ->filter()
                ->countBy();

            PartyResult::query()
                ->where('snapshot_id', $snapshot->id)
                ->update([
                    'won_district_count' => 0,
                ]);

            foreach ($winnerPartyIds as $partyId => $count) {
                PartyResult::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->where('election_party_id', $partyId)
                    ->update([
                        'won_district_count' => $count,
                    ]);
            }

            $this->line(
                "Snapshot {$snapshot->id}: "
                .$winnerPartyIds->sum()
                .' won districts.'
            );
        });
    }
}
