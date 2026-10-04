<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ElectionDistrictResultsController extends Controller
{
    public function __invoke(
        Election $election,
        int $district
    ): JsonResponse {
        $electoralDistrict = $election
            ->districts()
            ->where('source_district_number', $district)
            ->firstOrFail();

        $snapshot = $election
            ->latestSnapshot()
            ->first();

        if ($snapshot === null) {
            return response()->json([
                'district' => [
                    'source_district_number' => $electoralDistrict->source_district_number,
                    'name'                   => $electoralDistrict->name,
                    'results_final'          => false,
                    'status'                 => null,
                ],
                'candidates' => [],
            ]);
        }

        $districtResult = DB::table('district_results')
            ->where('snapshot_id', $snapshot->id)
            ->where('electoral_district_id', $electoralDistrict->id)
            ->first();

        $candidates = DB::table('candidates')
            ->select([
                'candidates.id',
                'candidates.first_name',
                'candidates.last_name',
                'election_parties.name as party_name',
                'election_parties.abbreviation as party_abbreviation',
                'election_parties.color as party_color',
                'candidate_results.vote_count',
                'candidate_results.vote_rate',
            ])
            ->leftJoin(
                'election_parties',
                'election_parties.id',
                '=',
                'candidates.election_party_id'
            )
            ->leftJoin(
                'candidate_results',
                function ($join) use ($snapshot): void {
                    $join
                        ->on(
                            'candidate_results.candidate_id',
                            '=',
                            'candidates.id'
                        )
                        ->where(
                            'candidate_results.snapshot_id',
                            '=',
                            $snapshot->id
                        );
                }
            )
            ->where(
                'candidates.electoral_district_id',
                $electoralDistrict->id
            )
            ->where(
                'candidates.election_id',
                $election->id
            )
            ->orderByDesc('candidate_results.vote_count')
            ->orderBy('candidates.last_name')
            ->orderBy('candidates.first_name')
            ->get()
            ->map(
                fn ($candidate): array => [
                    'id'                 => $candidate->id,
                    'first_name'         => $candidate->first_name,
                    'last_name'          => $candidate->last_name,
                    'party_name'         => $candidate->party_name,
                    'party_abbreviation' => $candidate->party_abbreviation,
                    'party_color'        => $candidate->party_color
                        ? "#{$candidate->party_color}"
                        : null,
                    'vote_count' => $candidate->vote_count !== null
                        ? (int) $candidate->vote_count
                        : null,
                    'vote_rate' => $candidate->vote_rate !== null
                        ? (float) $candidate->vote_rate
                        : null,
                ]
            );

        return response()->json([
            'district' => [
                'source_district_number' => $electoralDistrict->source_district_number,
                'name'                   => $electoralDistrict->name,
                'results_final'          => (bool) ($districtResult?->results_final ?? false),
                'status'                 => $districtResult?->status,
            ],
            'candidates' => $candidates,
        ]);
    }
}
