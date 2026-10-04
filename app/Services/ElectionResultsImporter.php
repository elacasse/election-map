<?php

namespace App\Services;

use App\Enums\DistrictResultStatus;
use App\Events\ElectionSnapshotCreated;
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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class ElectionResultsImporter
{
    /**
     * @throws ConnectionException
     * @throws RequestException
     * @throws JsonException|LockTimeoutException
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
     * @throws ConnectionException
     * @throws RequestException
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

        $snapshot = $this->performImportFromContents(
            $election,
            $contents,
            $etag,
            $lastModified,
            true
        );

        /*
         * On ne met à jour le cache HTTP qu'une fois l'import
         * SQL complété avec succès.
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
     * Importe un document de résultats déjà chargé en mémoire.
     *
     * @throws JsonException
     */
    private function performImportFromContents(
        Election $election,
        string $contents,
        ?string $etag = null,
        ?string $lastModified = null,
        bool $archive = false
    ): ?ElectionSnapshot {
        $lastSnapshot = ElectionSnapshot::query()
            ->where('election_id', $election->id)
            ->latest('id')
            ->first();

        /*
         * On utilise json_decode avec JSON_THROW_ON_ERROR afin
         * qu'un JSON invalide ne soit jamais traité comme des
         * résultats valides.
         */
        $results = json_decode(
            $contents,
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

        if (
            $lastSnapshot !== null &&
            hash_equals($lastSnapshot->results_hash, $hash)
        ) {
            return null;
        }

        /*
         * Un résultat téléchargé est archivé. Un fichier fourni
         * manuellement se trouve déjà dans le stockage local.
         */
        if ($archive) {
            $this->archiveDownloadedResults(
                $election,
                $contents
            );
        }

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
                $hash
            ) {
                $this->storeReferenceData(
                    $election,
                    $results
                );

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

                $districtAnalysis = $this->analyzeDistrictResults(
                    $results['circonscriptions']
                );

                $this->storePartyResults(
                    $snapshot,
                    $results['statistiques']['partisPolitiques'] ?? [],
                    $references['parties'],
                    $districtAnalysis['party_counts']
                );

                $this->storeDistrictResults(
                    $snapshot,
                    $results['circonscriptions'],
                    $references['districts'],
                    $references['candidates'],
                    $districtAnalysis['statuses']
                );

                return $snapshot;
            }
        );

        ElectionSnapshotCreated::dispatch($snapshot);

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
                    'vote_count'      => (int) $candidate['nbVoteTotal'],
                    'vote_rate'       => $this->normalizeNumber($candidate['tauxVote']),
                    'lead_vote_count' => (int) $candidate['nbVoteAvance'],
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

        if ($value === 'n.d.') {
            return '0';
        } elseif (!is_numeric($value)) {
            throw new RuntimeException("Unexpected non-numeric election result value. [$value]");
        }

        $normalized = sprintf('%.10F', (float) $value)
                |> (fn ($x) => rtrim($x, '0'))
                |> (fn ($x) => rtrim($x, '.'));

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function archiveDownloadedResults(Election $election, string $contents): void
    {
        $filename = sprintf(
            'election-results/%d/resultats-%d-%s.json',
            $election->year,
            $election->year,
            now()->format('Y-m-d_H-i-s')
        );

        if (!Storage::disk('local')->put($filename, $contents)) {
            throw new RuntimeException("Unable to archive downloaded election results to $filename.");
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
        $candidateCounts = $this->getCandidateCountsByParty($results);
        $parties         = collect();

        foreach ($results['statistiques']['partisPolitiques'] ?? [] as $partyData) {
            $partyNumber = (int) $partyData['numeroPartiPolitique'];
            /** @var ElectionParty|null $party */
            $party = ElectionParty::query()->firstOrCreate(
                [
                    'election_id'         => $election->id,
                    'source_party_number' => $partyData['numeroPartiPolitique'],
                ],
                [
                    'name'            => $partyData['nomPartiPolitique'],
                    'abbreviation'    => $partyData['abreviationPartiPolitique'],
                    'candidate_count' => $candidateCounts[$partyNumber] ?? 0,
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
                        .'for candidate '
                        .$candidateData['numeroCandidat'].'.'
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

    private function getCandidateCountsByParty(array $data): array
    {
        $counts = [];

        foreach ($data['circonscriptions'] as $district) {
            foreach ($district['candidats'] as $candidate) {
                $partyNumber = (int) $candidate['numeroPartiPolitique'];

                $counts[$partyNumber] = ($counts[$partyNumber] ?? 0) + 1;
            }
        }

        return $counts;
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

    private function parseNullableSourceDate(?string $date): ?Carbon
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return Carbon::parse(str_replace(',', '.', $date))->utc();
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
            'participation_rate'                      => $this->parseNullableNumber($statistics['tauxParticipationTotal'] ?? null),
            'electoral_district_count'                => $statistics['nbCirconscription'],
            'electoral_district_with_result_count'    => $statistics['nbCirconscriptionAvecResultat'],
            'electoral_district_without_result_count' => $statistics['nbCirconscriptionSansResultat'],
            'electoral_district_without_result_rate'  => $statistics['tauxCirconscriptionSansResultat'],
        ]);
    }

    private function parseNullableNumber(
        int|float|string|null $value
    ): ?float {
        if ($value === null || $value === '' || $value === 'n.d.') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException("Unexpected non-numeric election result value. [$value]");
        }

        return (float) $value;
    }

    /**
     * Analyse les résultats des circonscriptions afin de déterminer
     * leur statut et les compteurs cumulatifs par parti.
     *
     * @param  array<int, array<string, mixed>>  $districts
     * @return array{
     *     statuses: array<int, \App\Enums\DistrictResultStatus>,
     *     party_counts: array<int, array{
     *         won: int,
     *         projected: int,
     *         leading: int
     *     }>
     * }
     */
    private function analyzeDistrictResults(array $districts): array
    {
        $statuses    = [];
        $partyCounts = [];

        foreach ($districts as $district) {
            $districtNumber = (int) $district['numeroCirconscription'];
            $candidates     = $district['candidats'] ?? [];

            if ($candidates === []) {
                continue;
            }

            usort(
                $candidates,
                fn (array $a, array $b): int => ((int) $b['nbVoteTotal']) <=> ((int) $a['nbVoteTotal'])
            );

            $leader   = $candidates[0];
            $runnerUp = $candidates[1] ?? null;

            if ((int) $leader['nbVoteTotal'] === 0) {
                continue;
            }

            if (
                $runnerUp !== null &&
                (int) $leader['nbVoteTotal'] === (int) $runnerUp['nbVoteTotal']
            ) {
                continue;
            }

            $status = $this->determineDistrictStatus(
                $district,
                $leader
            );

            $statuses[$districtNumber] = $status;

            $partyNumber = (int) $leader['numeroPartiPolitique'];

            $partyCounts[$partyNumber] ??= [
                'won'       => 0,
                'projected' => 0,
                'leading'   => 0,
            ];

            /*
            * Les catégories sont cumulatives :
            *
            * élu ⊂ projeté ⊂ en avance
            */
            $partyCounts[$partyNumber]['leading']++;

            if (
                $status === DistrictResultStatus::Projected ||
                $status === DistrictResultStatus::Elected
            ) {
                $partyCounts[$partyNumber]['projected']++;
            }

            if ($status === DistrictResultStatus::Elected) {
                $partyCounts[$partyNumber]['won']++;
            }
        }

        return [
            'statuses'     => $statuses,
            'party_counts' => $partyCounts,
        ];
    }

    private function determineDistrictStatus(
        array $district,
        array $leader
    ): DistrictResultStatus {
        if ((bool) ($district['isResultatsFinaux'] ?? false)) {
            return DistrictResultStatus::Elected;
        }

        $participationRate = $this->parseNullableNumber(
            $district['tauxParticipation'] ?? null
        ) ?? 100.0;

        $estimatedFinalVoteCount = (int) ceil(
            (int) $district['nbElecteurInscrit']
            * ($participationRate / 100)
        );

        $remainingVoteCount = max(
            0,
            $estimatedFinalVoteCount
                - (int) $district['nbVoteExerce']
        );

        $leadVoteCount = (int) ($leader['nbVoteAvance'] ?? 0);

        if ($leadVoteCount > $remainingVoteCount) {
            return DistrictResultStatus::Projected;
        }

        return DistrictResultStatus::Leading;
    }

    /**
     * @param  Collection<int, ElectionParty>  $parties
     */
    private function storePartyResults(
        ElectionSnapshot $snapshot,
        array $partyResults,
        Collection $parties,
        array $partyDistrictCounts,
    ): void {
        foreach ($partyResults as $partyData) {
            $partyNumber = (int) $partyData['numeroPartiPolitique'];

            /** @var ElectionParty|null $party */
            $party = $parties->get($partyNumber);

            if ($party === null) {
                throw new RuntimeException("Unknown election party {$partyNumber}.");
            }

            $counts = $partyDistrictCounts[$partyNumber] ?? [
                'won'       => 0,
                'projected' => 0,
                'leading'   => 0,
            ];

            PartyResult::query()->create([
                'snapshot_id'              => $snapshot->id,
                'election_party_id'        => $party->id,
                'vote_count'               => $partyData['nbVoteTotal'],
                'vote_rate'                => $partyData['tauxVoteTotal'],
                'leading_district_count'   => $counts['leading'],
                'won_district_count'       => $counts['won'],
                'projected_district_count' => $counts['projected'],
                'leading_district_rate'    => $partyData['tauxCirconscriptionsEnAvance'],
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
        Collection $candidates,
        array $districtStatuses,
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
                'participation_rate'              => $this->parseNullableNumber($districtData['tauxParticipation'] ?? null),
                'results_final'                   => (bool) $districtData['isResultatsFinaux'],
                'status'                          => $districtStatuses[$districtNumber]?->value ?? null,
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
                'snapshot_id'     => $snapshot->id,
                'candidate_id'    => $candidate->id,
                'vote_count'      => $candidateData['nbVoteTotal'],
                'vote_rate'       => $candidateData['tauxVote'],
                'lead_vote_count' => $candidateData['nbVoteAvance'],
            ]);
        }
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

    /**
     * @throws JsonException|LockTimeoutException
     */
    public function importFile(
        Election $election,
        string $filename
    ): ?ElectionSnapshot {
        if (!Storage::disk('local')->exists($filename)) {
            throw new RuntimeException(
                "Election results file does not exist: {$filename}"
            );
        }

        $contents = Storage::disk('local')->get($filename);

        return Cache::lock(
            "election:{$election->id}:results-import",
            60
        )->block(10, function () use ($election, $contents) {
            return $this->performImportFromContents(
                $election,
                $contents
            );
        });
    }

}
