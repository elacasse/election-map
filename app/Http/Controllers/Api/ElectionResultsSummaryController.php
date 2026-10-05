<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Return a summary of the latest results for an election.
 *
 * The summary provides the high-level data required by the election results
 * interface, including the timestamp and final status of the latest snapshot,
 * party standings, and district-level result information.
 *
 * At least five parties are included. All parties winning or leading in a
 * district are retained, even when this results in more than five entries.
 */
class ElectionResultsSummaryController extends Controller
{
    private const array PRIORITY_PARTY_NUMBERS = [
        27, // Coalition avenir Québec
        6,  // Parti libéral du Québec
        40, // Québec solidaire
        8,  // Parti québécois
        22, // Parti conservateur du Québec
    ];

    /**
     * Return the latest results summary for the given election.
     *
     * The election is resolved through route model binding using its year.
     *
     * If no results snapshot exists yet, the response contains the election
     * metadata and the predefined priority parties with zero won or leading
     * districts. The district list is empty.
     */
    public function __invoke(Election $election): JsonResponse
    {
        $snapshot = $election
            ->latestSnapshot()
            ->with([
                'partyResults' => fn ($query) => $query
                    ->with('party')
                    ->orderByDesc('leading_district_count'),
            ])
            ->first();

        if ($snapshot === null) {

            $parties = $election
                ->parties()
                ->whereIn('source_party_number', self::PRIORITY_PARTY_NUMBERS)
                ->get()
                ->sortBy(
                    fn ($party): int => array_search(
                        $party->source_party_number,
                        self::PRIORITY_PARTY_NUMBERS,
                        true
                    )
                )
                ->values()
                ->map(
                    fn ($party): array => [
                        'name'         => $party->name,
                        'abbreviation' => $party->abbreviation,
                        'color'        => $party->color
                            ? "#{$party->color}"
                            : null,
                        'won_district_count'       => 0,
                        'projected_district_count' => 0,
                        'leading_district_count'   => 0,
                        'vote_rate'                => 0,
                        'vote_count'               => 0,
                    ]
                );

            return response()->json([
                'election' => [
                    'year'          => $election->year,
                    'captured_at'   => null,
                    'results_final' => false,
                ],
                'parties'   => $parties,
                'districts' => [],
            ]);
        }

        $districtRows = DB::table('candidate_results')
            ->select([
                'candidates.electoral_district_id',
                'electoral_districts.source_district_number',
                'candidates.election_party_id',
                'election_parties.color as party_color',
                'candidate_results.vote_count',
                'district_results.results_final',
                'district_results.status',
            ])
            ->join(
                'candidates',
                'candidates.id',
                '=',
                'candidate_results.candidate_id'
            )
            ->join(
                'electoral_districts',
                'electoral_districts.id',
                '=',
                'candidates.electoral_district_id'
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
            ->leftJoin(
                'election_parties',
                'election_parties.id',
                '=',
                'candidates.election_party_id'
            )
            ->where(
                'candidate_results.snapshot_id',
                $snapshot->id
            )
            ->where(
                'district_results.cast_vote_count',
                '>',
                0
            )
            ->orderBy('candidates.electoral_district_id')
            ->orderByDesc('candidate_results.vote_count')
            ->get();

        $districts = $districtRows
            ->groupBy('electoral_district_id')
            ->map(function ($results): array {
                $first  = $results->first();
                $second = $results->skip(1)->first();

                $isTied = (
                    $second !== null &&
                    $first->vote_count === $second->vote_count
                );

                return [
                    'source_district_number' => (int) $first->source_district_number,

                    'party_color' => $isTied
                        ? null
                        : $first->party_color,

                    'results_final' => (bool) $first->results_final,

                    'status' => $isTied
                        ? null
                        : $first->status,
                ];
            })
            ->values();

        /**
         * Order parties for display in the election summary.
         *
         * Parties are ordered according to the following rules:
         *
         * 1. Parties winning or leading in at least one district come first.
         * 2. Independent candidates with results come after political parties
         *    that also have results.
         * 3. Parties are ranked by the number of districts they are leading.
         * 4. Ties are resolved using the number of districts already won.
         * 5. Parties with no won or leading districts use a predefined priority.
         * 6. The party name provides a deterministic final ordering.
         */
        $sortedPartyResults = $snapshot->partyResults
            ->sortBy(function ($result): array {
                $hasDistrict = (
                    $result->won_district_count > 0 ||
                    $result->leading_district_count > 0
                );

                $priority = array_search(
                    $result->party->source_party_number,
                    self::PRIORITY_PARTY_NUMBERS,
                    true
                );

                return [
                    // Les partis ayant des résultats passent toujours avant 0 / 0.
                    $hasDistrict ? 0 : 1,

                    // Les candidats indépendants ayant un siège passent après
                    // tous les autres partis ayant des résultats.
                    $hasDistrict && $result->party->source_party_number === 0
                        ? 1
                        : 0,

                    // Tri décroissant des circonscriptions en avance.
                    -$result->leading_district_count,

                    // Puis projetées.
                    -$result->projected_district_count,

                    // Puis gagnées, si nécessaire.
                    -$result->won_district_count,

                    // Parmi les 0 / 0, priorité aux partis principaux.
                    $priority === false
                        ? PHP_INT_MAX
                        : $priority,

                    // Ordre stable pour les autres.
                    $result->party->name,
                ];
            })
            ->values();

        $partyWithDistrictCount = $sortedPartyResults
            ->filter(
                fn ($result): bool => $result->won_district_count > 0 ||
                    $result->leading_district_count > 0
            )
            ->count();

        $partyResults = $sortedPartyResults
            ->take(max(5, $partyWithDistrictCount))
            ->values();

        return response()->json([
            'election' => [
                'year'          => $election->year,
                'captured_at'   => $snapshot->captured_at,
                'results_final' => $snapshot->results_final,
            ],

            'parties' => $partyResults->map(
                fn ($result) => [
                    'name'         => $result->party->name,
                    'abbreviation' => $result->party->abbreviation,
                    'color'        => $result->party->color
                        ? "#{$result->party->color}"
                        : null,
                    'won_district_count'       => $result->won_district_count,
                    'projected_district_count' => $result->projected_district_count,
                    'leading_district_count'   => $result->leading_district_count,
                    'vote_rate'                => $result->vote_rate,
                    'vote_count'               => $result->vote_count,
                ]
            )->values(),

            'districts' => $districts,
        ]);
    }
}
