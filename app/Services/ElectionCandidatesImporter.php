<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionParty;
use App\Models\ElectoralDistrict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ElectionCandidatesImporter
{
    /**
     * @throws JsonException
     */
    public function import(Election $election, string $filename): void
    {
        if (!is_file($filename)) {
            throw new RuntimeException(
                "Candidates file does not exist: {$filename}"
            );
        }

        $contents = file_get_contents($filename);

        if ($contents === false) {
            throw new RuntimeException(
                "Unable to read candidates file: {$filename}"
            );
        }

        $candidates = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($candidates)) {
            throw new RuntimeException(
                'Candidates file is not a JSON array.'
            );
        }

        DB::transaction(function () use ($election, $candidates) {
            $this->storeReferenceData(
                $election,
                $candidates
            );
        });
    }

    private function storeReferenceData(
        Election $election,
        array $candidates
    ): void {
        $candidateCounts = collect($candidates)
            ->groupBy(
                fn (array $candidate) => (int) ($candidate['afpparp_numero'] ?? 0)
            )
            ->map(
                fn (Collection $partyCandidates) => $partyCandidates->count()
            );

        $parties = collect();

        foreach ($candidates as $candidateData) {
            $partyNumber = (int) ($candidateData['afpparp_numero'] ?? 0);

            /*
             * Le numéro 0 représente les candidats sans
             * affiliation à un parti politique.
             */
            if ($partyNumber === 0) {
                continue;
            }

            $party = ElectionParty::query()->updateOrCreate(
                [
                    'election_id'         => $election->id,
                    'source_party_number' => $partyNumber,
                ],
                [
                    'name'            => $candidateData['nom_parti'],
                    'abbreviation'    => $candidateData['abreviation_parti'],
                    'candidate_count' => $candidateCounts->get($partyNumber, 0),
                ]
            );

            $parties->put(
                $partyNumber,
                $party
            );
        }

        foreach ($candidates as $candidateData) {
            $districtNumber = (int) $candidateData['code_circonscription'];

            $district = ElectoralDistrict::query()->updateOrCreate(
                [
                    'election_id'            => $election->id,
                    'source_district_number' => $districtNumber,
                ],
                [
                    'name' => $candidateData['nom_circonscription'],
                ]
            );

            $partyNumber = (int) ($candidateData['afpparp_numero'] ?? 0);

            /** @var ElectionParty $party */
            $party = $partyNumber === 0
                ? null
                : $parties->get($partyNumber);

            if ($partyNumber !== 0 && $party === null) {
                throw new RuntimeException(
                    "Unknown party {$partyNumber} for candidate {$candidateData['numero']}."
                );
            }

            Candidate::query()->updateOrCreate(
                [
                    'election_id'             => $election->id,
                    'source_candidate_number' => $candidateData['numero'],
                ],
                [
                    'electoral_district_id' => $district->id,
                    'election_party_id'     => $party?->id,
                    'last_name'             => $candidateData['nom_bulletin_vote'],
                    'first_name'            => $candidateData['prenom_bulletin_vote'],
                ]
            );
        }
    }
}
