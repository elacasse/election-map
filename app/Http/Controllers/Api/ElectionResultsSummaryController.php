<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Election;
use Illuminate\Http\JsonResponse;

class ElectionResultsSummaryController extends Controller
{
    public function __invoke(Election $election): JsonResponse
    {
        $snapshot = $election
            ->latestSnapshot()
            ->with([
                'partyResults' => fn ($query) => $query
                    ->with('party')
                    ->orderByDesc('leading_district_count')
                    ->limit(5),
            ])
            ->first();

        if ($snapshot === null) {
            return response()->json([
                'election' => [
                    'year'          => $election->year,
                    'captured_at'   => null,
                    'results_final' => false,
                ],
                'parties' => [],
            ]);
        }

        return response()->json([
            'election' => [
                'year'          => $election->year,
                'captured_at'   => $snapshot->captured_at,
                'results_final' => $snapshot->results_final,
            ],

            'parties' => $snapshot->partyResults->map(
                fn ($result) => [
                    'name'         => $result->party->name,
                    'abbreviation' => $result->party->abbreviation,
                    'color'        => $result->party->color
                        ? "#{$result->party->color}"
                        : null,
                    'won_district_count'     => $result->won_district_count,
                    'leading_district_count' => $result->leading_district_count,
                ]
            )->values(),
        ]);
    }
}
