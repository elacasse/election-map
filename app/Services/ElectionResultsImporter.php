<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DistrictResult;
use App\Models\Election;
use App\Models\ElectionParty;
use App\Models\ElectionSnapshot;
use App\Models\ElectionStatistic;
use App\Models\ElectoralDistrict;
use App\Models\PartyResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class ElectionResultsImporter
{
    /**
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws \Illuminate\Http\Client\RequestException
     * @throws JsonException|\Illuminate\Contracts\Cache\LockTimeoutException
     */
    public function import(Election $election): ?ElectionSnapshot
    {
        return Cache::lock(
            "election:{$election->id}:results-import",
            60
        )->block(10, function () use ($election) {
            return $this->performImport($election);
        });
    }

    /**
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws \Illuminate\Http\Client\RequestException
     * @throws JsonException
     */
    private function performImport(Election $election): ?ElectionSnapshot
    {
        $url = config("elections.results_url_{$election->year}");

        if (!$url) {
            throw new RuntimeException(
                'RESULTS_URL is not configured.'
            );
        }

        $lastSnapshot = ElectionSnapshot::query()
                                        ->where('election_id', $election->id)
                                        ->latest('id')
                                        ->first();

        /*
         * Les validateurs HTTP sont conservés dans le cache.
         *
         * On utilise les valeurs du dernier snapshot comme fallback
         * si le cache vient d'être vidé ou si l'application redémarre.
         */
        $etagCacheKey = "election:{$election->id}:results-etag";

        $lastModifiedCacheKey = "election:{$election->id}:results-last-modified";

        $knownEtag = Cache::get(
            $etagCacheKey,
            $lastSnapshot?->source_etag
        );

        $knownLastModified = Cache::get(
            $lastModifiedCacheKey,
            $lastSnapshot?->source_last_modified_at ?
                $lastSnapshot->source_last_modified_at
                    ->utc()
                    ->format('D, d M Y H:i:s \G\M\T')
                : null
        );

        /*
         * GET conditionnel.
         *
         * Si l'ETag est connu, If-None-Match est préférable.
         * Sinon on utilise Last-Modified comme fallback.
         */
        $headers = [];

        if ($knownEtag) {
            $headers['If-None-Match'] = $knownEtag;
        } elseif ($knownLastModified) {
            $headers['If-Modified-Since'] = $knownLastModified;
        }

        $response = Http::withHeaders($headers)
                        ->timeout(30)
                        ->retry(2, 500)
                        ->get($url);

        /*
         * 304 = le document source n'a pas changé.
         */
        if ($response->status() === 304) {
            return null;
        }

        $response->throw();

        $contents = $response->body();

        $etag         = $response->header('ETag');
        $lastModified = $response->header('Last-Modified');

        /*
         * On utilise json_decode avec JSON_THROW_ON_ERROR afin
         * qu'un JSON invalide ne soit jamais traité comme des
         * résultats valides.
         */
        $results = json_decode(
            $response->body(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($results)) {
            throw new RuntimeException(
                'Election results response is not a JSON object.'
            );
        }

        $this->validateResultsStructure($results);

        /*
         * Le hash ne représente que les résultats variables.
         *
         * Les noms de candidats, noms de partis, timestamps de la
         * source, etc. ne doivent pas provoquer un nouveau snapshot.
         */
        $hash = $this->calculateResultsHash(
            $results
        );

        /*
         * Le document HTTP peut avoir changé sans que les résultats
         * eux-mêmes aient changé.
         *
         * On met donc à jour les validateurs en cache, mais on
         * n'écrit rien dans la base de données.
         */
        if (
            $lastSnapshot !== null &&
            hash_equals($lastSnapshot->results_hash, $hash)
        ) {
            $this->storeHttpValidators(
                $etagCacheKey,
                $lastModifiedCacheKey,
                $etag,
                $lastModified
            );

            return null;
        }

        $this->archiveDownloadedResults(
            $election,
            $contents
        );

        /*
         * Tout l'import SQL est atomique.
         *
         * Lors du premier snapshot seulement, les données de
         * référence sont créées :
         *
         * - partis
         * - circonscriptions
         * - candidats
         *
         * Les imports suivants n'y touchent plus.
         */
        $snapshot = DB::transaction(
            function () use (
                $election,
                $results,
                $etag,
                $lastModified,
                $hash,
                $lastSnapshot
            ) {
                if ($lastSnapshot === null) {
                    $this->storeReferenceData(
                        $election,
                        $results
                    );
                }

                $references = $this->loadReferenceData(
                    $election
                );

                $snapshot = ElectionSnapshot::query()->create([
                    'election_id'             => $election->id,
                    'captured_at'             => now()->utc(),
                    'source_etag'             => $etag,
                    'source_last_modified_at' => $lastModified ? Carbon::parse($lastModified)->utc() : null,
                    'source_updated_at'       => $this->parseNullableSourceDate($results['statistiques']['iso8601DateMAJ'] ?? null),
                    'results_hash'            => $hash,
                    'results_final'           => (bool) ($results['statistiques']['isResultatsFinaux'] ?? false),
                ]);

                $this->storeElectionStatistics(
                    $snapshot,
                    $results['statistiques']
                );

                $this->storePartyResults(
                    $snapshot,
                    $results['statistiques']['partisPolitiques'] ?? [],
                    $references['parties']
                );

                $this->storeDistrictResults(
                    $snapshot,
                    $results['circonscriptions'],
                    $references['districts'],
                    $references['candidates']
                );

                return $snapshot;
            }
        );

        /*
         * On ne met à jour le cache HTTP qu'une fois la transaction
         * SQL complétée avec succès.
         */
        $this->storeHttpValidators(
            $etagCacheKey,
            $lastModifiedCacheKey,
            $etag,
            $lastModified
        );

        return $snapshot;
    }

    /**
     * Vérifie la structure minimale attendue du JSON.
     */
    private function validateResultsStructure(
        array $results
    ): void {
        if (!isset($results['statistiques']) || !is_array($results['statistiques'])) {
            throw new RuntimeException(
                'Missing or invalid statistiques section.'
            );
        }

        if (!isset($results['circonscriptions']) || !is_array($results['circonscriptions'])) {
            throw new RuntimeException('Missing or invalid circonscriptions section.');
        }

        if (!isset($results['statistiques']['partisPolitiques']) || !is_array($results['statistiques']['partisPolitiques'])) {
            throw new RuntimeException(
                'Missing or invalid partisPolitiques section.'
            );
        }
    }

    /**
     * Crée les partis, circonscriptions et candidats.
     *
     * Cette méthode n'est appelée que lors du premier import
     * d'une élection.
     */
    private function storeReferenceData(Election $election, array $results): void
    {
        $parties = collect();

        foreach ($results['statistiques']['partisPolitiques'] ?? [] as $partyData) {
            /** @var ElectionParty|null $party */
            $party = ElectionParty::query()->firstOrCreate(
                [
                    'election_id'         => $election->id,
                    'source_party_number' => $partyData['numeroPartiPolitique'],
                ],
                [
                    'name'            => $partyData['nomPartiPolitique'],
                    'abbreviation'    => $partyData['abreviationPartiPolitique'],
                    'candidate_count' => $partyData['nbCandidat'],
                ]
            );

            $parties->put(
                (int) $partyData['numeroPartiPolitique'],
                $party
            );
        }

        foreach ($results['circonscriptions'] as $districtData) {
            $district = ElectoralDistrict::query()->firstOrCreate(
                [
                    'election_id'            => $election->id,
                    'source_district_number' => $districtData['numeroCirconscription'],
                ],
                [
                    'name' => $districtData['nomCirconscription'],
                ]
            );

            foreach ($districtData['candidats'] ?? [] as $candidateData) {
                $partyNumber = (int) $candidateData['numeroPartiPolitique'];

                /** @var ElectionParty|null $party */
                $party = $parties->get($partyNumber);

                /*
                 * Le numéro 0 peut représenter un candidat
                 * sans parti politique.
                 */
                if (
                    $partyNumber !== 0 &&
                    $party === null
                ) {
                    throw new RuntimeException(
                        "Unknown party {$partyNumber} "
                        . "for candidate "
                        . $candidateData['numeroCandidat'] . '.'
                    );
                }

                Candidate::query()->firstOrCreate(
                    [
                        'election_id'             => $election->id,
                        'source_candidate_number' => $candidateData['numeroCandidat'],
                    ],
                    [
                        'electoral_district_id' => $district->id,
                        'election_party_id'     => $party?->id,
                        'last_name'             => $candidateData['nom'],
                        'first_name'            => $candidateData['prenom'],
                    ]
                );
            }
        }
    }

    /**
     * Charge les données de référence en mémoire afin d'éviter
     * de faire des requêtes firstOrCreate pour chaque snapshot.
     *
     * @return array{
     *     parties: Collection<int, ElectionParty>,
     *     districts: Collection<int, ElectoralDistrict>,
     *     candidates: Collection<int, Candidate>
     * }
     */
    private function loadReferenceData(
        Election $election
    ): array {
        return [
            'parties' => ElectionParty::query()
                                      ->where('election_id', $election->id)
                                      ->get()
                                      ->keyBy('source_party_number'),

            'districts' => ElectoralDistrict::query()
                                            ->where('election_id', $election->id)
                                            ->get()
                                            ->keyBy('source_district_number'),

            'candidates' => Candidate::query()
                                     ->where('election_id', $election->id)
                                     ->get()
                                     ->keyBy('source_candidate_number'),
        ];
    }

    private function storeElectionStatistics(
        ElectionSnapshot $snapshot,
        array $statistics
    ): void {
        ElectionStatistic::query()->create([
            'snapshot_id'                             => $snapshot->id,
            'polling_station_count'                   => $statistics['nbBureauVote'],
            'polling_station_completed_count'         => $statistics['nbBureauVoteRempli'],
            'polling_station_completed_rate'          => $statistics['tauxBureauVoteRempli'],
            'valid_vote_count'                        => $statistics['nbVoteValide'],
            'rejected_vote_count'                     => $statistics['nbVoteRejete'],
            'cast_vote_count'                         => $statistics['nbVoteExerce'],
            'registered_voter_count'                  => $statistics['nbElecteurInscrit'],
            'participation_rate'                      => $statistics['tauxParticipationTotal'],
            'electoral_district_count'                => $statistics['nbCirconscription'],
            'electoral_district_with_result_count'    => $statistics['nbCirconscriptionAvecResultat'],
            'electoral_district_without_result_count' => $statistics['nbCirconscriptionSansResultat'],
            'electoral_district_without_result_rate'  => $statistics['tauxCirconscriptionSansResultat'],
        ]);
    }

    /**
     * @param  Collection<int, ElectionParty>  $parties
     */
    private function storePartyResults(
        ElectionSnapshot $snapshot,
        array $partyResults,
        Collection $parties
    ): void {
        foreach ($partyResults as $partyData) {
            $partyNumber = (int) $partyData['numeroPartiPolitique'];

            /** @var ElectionParty|null $party */
            $party = $parties->get($partyNumber);

            if ($party === null) {
                throw new RuntimeException("Unknown election party {$partyNumber}.");
            }

            PartyResult::query()->create([
                'snapshot_id'            => $snapshot->id,
                'election_party_id'      => $party->id,
                'vote_count'             => $partyData['nbVoteTotal'],
                'vote_rate'              => $partyData['tauxVoteTotal'],
                'leading_district_count' => $partyData['nbCirconscriptionsEnAvance'],
                'leading_district_rate'  => $partyData['tauxCirconscriptionsEnAvance'],
            ]);
        }
    }

    /**
     * @param  Collection<int, ElectoralDistrict>  $districts
     * @param  Collection<int, Candidate>  $candidates
     */
    private function storeDistrictResults(
        ElectionSnapshot $snapshot,
        array $districtResults,
        Collection $districts,
        Collection $candidates
    ): void {
        foreach ($districtResults as $districtData) {
            $districtNumber = (int) $districtData['numeroCirconscription'];

            /**
             * @var ElectoralDistrict|null $district
             */
            $district = $districts->get($districtNumber);

            if ($district === null) {
                throw new RuntimeException("Unknown electoral district {$districtNumber}.");
            }

            DistrictResult::query()->create([
                'snapshot_id'                     => $snapshot->id,
                'electoral_district_id'           => $district->id,
                'polling_station_completed_count' => $districtData['nbBureauComplete'],
                'polling_station_count'           => $districtData['nbBureauTotal'],
                'valid_vote_count'                => $districtData['nbVoteValide'],
                'rejected_vote_count'             => $districtData['nbVoteRejete'],
                'cast_vote_count'                 => $districtData['nbVoteExerce'],
                'registered_voter_count'          => $districtData['nbElecteurInscrit'],
                'valid_vote_rate'                 => $districtData['tauxVoteValide'],
                'rejected_vote_rate'              => $districtData['tauxVoteRejete'],
                'participation_rate'              => $districtData['tauxParticipation'],
                'results_final'                   => (bool) $districtData['isResultatsFinaux'],
                'source_updated_at'               => $this->parseNullableSourceDate($districtData['iso8601DateMAJ'] ?? null),
            ]);

            $this->storeCandidateResults(
                $snapshot,
                $districtData['candidats'] ?? [],
                $candidates
            );
        }
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    private function storeCandidateResults(
        ElectionSnapshot $snapshot,
        array $candidateResults,
        Collection $candidates
    ): void {
        foreach ($candidateResults as $candidateData) {
            $candidateNumber = (int) $candidateData['numeroCandidat'];

            /** @var Candidate|null $candidate */
            $candidate = $candidates->get($candidateNumber);

            if ($candidate === null) {
                throw new RuntimeException("Unknown candidate {$candidateNumber}.");
            }

            CandidateResult::query()->create([
                'snapshot_id'        => $snapshot->id,
                'candidate_id'       => $candidate->id,
                'vote_count'         => $candidateData['nbVoteTotal'],
                'vote_rate'          => $candidateData['tauxVote'],
                'advance_vote_count' => $candidateData['nbVoteAvance'],
            ]);
        }
    }

    /**
     * Produit un hash représentant uniquement les données de
     * résultats susceptibles d'évoluer pendant l'élection.
     *
     * Les timestamps et données descriptives ne sont pas inclus.
     *
     * @throws JsonException
     */
    private function calculateResultsHash(
        array $results
    ): string {
        $statistics = $results['statistiques'];

        $normalized = [
            'statistics' => [
                'polling_station_count'                   => (int) $statistics['nbBureauVote'],
                'polling_station_completed_count'         => (int) $statistics['nbBureauVoteRempli'],
                'polling_station_completed_rate'          => $this->normalizeNumber($statistics['tauxBureauVoteRempli']),
                'valid_vote_count'                        => (int) $statistics['nbVoteValide'],
                'rejected_vote_count'                     => (int) $statistics['nbVoteRejete'],
                'cast_vote_count'                         => (int) $statistics['nbVoteExerce'],
                'registered_voter_count'                  => (int) $statistics['nbElecteurInscrit'],
                'participation_rate'                      => $this->normalizeNumber($statistics['tauxParticipationTotal']),
                'electoral_district_count'                => (int) $statistics['nbCirconscription'],
                'electoral_district_with_result_count'    => (int) $statistics['nbCirconscriptionAvecResultat'],
                'electoral_district_without_result_count' => (int) $statistics['nbCirconscriptionSansResultat'],
                'electoral_district_without_result_rate'  => $this->normalizeNumber($statistics['tauxCirconscriptionSansResultat']),
                'results_final'                           => (bool) ($statistics['isResultatsFinaux'] ?? false),
            ],

            'parties'   => [],
            'districts' => [],
        ];

        foreach ($statistics['partisPolitiques'] ?? [] as $party) {
            $partyNumber = (int) $party['numeroPartiPolitique'];

            $normalized['parties'][$partyNumber] = [
                'vote_count'             => (int) $party['nbVoteTotal'],
                'vote_rate'              => $this->normalizeNumber($party['tauxVoteTotal']),
                'leading_district_count' => (int) $party['nbCirconscriptionsEnAvance'],
                'leading_district_rate'  => $this->normalizeNumber($party['tauxCirconscriptionsEnAvance']),
            ];
        }

        foreach ($results['circonscriptions'] as $district) {
            $districtNumber = (int) $district['numeroCirconscription'];

            $normalized['districts'][$districtNumber] = [
                'polling_station_completed_count' => (int) $district['nbBureauComplete'],
                'polling_station_count'           => (int) $district['nbBureauTotal'],
                'valid_vote_count'                => (int) $district['nbVoteValide'],
                'rejected_vote_count'             => (int) $district['nbVoteRejete'],
                'cast_vote_count'                 => (int) $district['nbVoteExerce'],
                'registered_voter_count'          => (int) $district['nbElecteurInscrit'],
                'valid_vote_rate'                 => $this->normalizeNumber($district['tauxVoteValide']),
                'rejected_vote_rate'              => $this->normalizeNumber($district['tauxVoteRejete']),
                'participation_rate'              => $this->normalizeNumber($district['tauxParticipation']),
                'results_final'                   => (bool) $district['isResultatsFinaux'],
                'candidates'                      => [],
            ];

            foreach ($district['candidats'] ?? [] as $candidate) {
                $candidateNumber = (int) $candidate['numeroCandidat'];

                $normalized['districts'][$districtNumber]['candidates'][$candidateNumber] = [
                    'vote_count'         => (int) $candidate['nbVoteTotal'],
                    'vote_rate'          => $this->normalizeNumber($candidate['tauxVote']),
                    'advance_vote_count' => (int) $candidate['nbVoteAvance'],
                ];
            }

            ksort($normalized['districts'][$districtNumber]['candidates']);
        }

        ksort($normalized['parties']);
        ksort($normalized['districts']);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * Normalise les nombres décimaux afin que 1, 1.0 et 1.000
     * produisent la même représentation pour le hash.
     */
    private function normalizeNumber(
        int|float|string|null $value
    ): string {
        if ($value === null || $value === '') {
            return '0';
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('Unexpected non-numeric election result value.');
        }

        $normalized = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function parseNullableSourceDate(?string $date): ?Carbon
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return Carbon::parse(str_replace(',', '.', $date))->utc();
    }

    private function storeHttpValidators(
        string $etagCacheKey,
        string $lastModifiedCacheKey,
        ?string $etag,
        ?string $lastModified
    ): void {
        if ($etag !== null) {
            Cache::forever($etagCacheKey, $etag);
        }

        if ($lastModified !== null) {
            Cache::forever($lastModifiedCacheKey, $lastModified);
        }
    }

    private function archiveDownloadedResults(Election $election, string $contents): void
    {
        $filename = sprintf(
            'election-results/%d/resultats-%d-%s.json',
            $election->year,
            $election->year,
            now()->format('Y-m-d_H-i-s')
        );

        if (! Storage::disk('local')->put($filename, $contents)) {
            throw new RuntimeException("Unable to archive downloaded election results to $filename.");
        }
    }
}
