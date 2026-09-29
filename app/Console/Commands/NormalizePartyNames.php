<?php

namespace App\Console\Commands;

use App\Models\ElectionParty;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('election:backfill-party-names {year? : Election year}')]
#[Description('Normalize election party names and abbreviations using source_party_number')]
class NormalizePartyNames extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $year = $this->argument('year');

        $names = [
            0  => ['Candidats indépendants', 'Ind.'],
            2  => ['Bloc pot', 'BP'],
            6  => ['Parti libéral du Québec', 'PLQ'],
            7  => ['Parti marxiste-léniniste du Québec', 'PMLQ'],
            8  => ['Parti québécois', 'PQ'],
            10 => ['Parti vert du Québec', 'PVQ'],
            22 => ['Parti conservateur du Québec', 'PCQ'],
            23 => ['Parti nul', 'PN'],
            27 => ['Coalition avenir Québec', 'CAQ'],
            29 => ['Équipe autonomiste', 'EA'],
            37 => ['Parti 51', 'P51'],
            40 => ['Québec solidaire', 'QS'],
            42 => ['Parti culinaire du Québec', 'PCuQ'],

            99209 => ['Union nationale', 'UN'],
            99236 => ['Parti accès propriété et équité', 'PAPE'],
            99237 => ['Climat Québec', 'CQ'],
            99263 => ['Parti canadien du Québec', 'PCQ'],
            99275 => ["L'union fait la force", 'UFF'],
            99281 => ['Parti humain du Québec', 'PHQ'],
            99285 => ['Bloc Montréal', 'BM'],
            99286 => ['Démocratie directe', 'DD'],
            99291 => ['Parti libertarien du Québec', 'PLiQ'],
            99293 => ['Alliance pour la famille et les communautés', 'AFC'],

            99330 => ['Québec innovant', 'QI'],
            99331 => ['Présence Québec', 'PRQ'],
            99341 => ['Parti accès propriété et équité', 'PAPE'],
            99352 => ['Parti populaire du Québec', 'PPQ'],
        ];

        /** @noinspection DuplicatedCode */
        $query = ElectionParty::query()
            ->with('election')
            ->orderBy('source_party_number');

        if ($year !== null) {
            $query->whereHas(
                'election',
                fn ($query) => $query->where('year', (int) $year),
            );
        }

        $parties = $query->get();

        if ($parties->isEmpty()) {
            $this->error(
                $year === null
                    ? 'No election parties found.'
                    : "No election parties found for {$year}.",
            );

            return self::FAILURE;
        }

        $updated = 0;
        $missing = [];

        /** @var ElectionParty $party */
        foreach ($parties as $party) {
            $normalized = $names[$party->source_party_number] ?? null;

            if ($normalized === null) {
                $missing[] = [
                    $party->election?->year,
                    $party->source_party_number,
                    $party->abbreviation,
                    $party->name,
                ];

                continue;
            }

            [$name, $abbreviation] = $normalized;

            if (
                $party->name === $name
                && $party->abbreviation === $abbreviation
            ) {
                continue;
            }

            $oldName         = $party->name;
            $oldAbbreviation = $party->abbreviation;

            $party->update([
                'name'         => $name,
                'abbreviation' => $abbreviation,
            ]);

            $updated++;

            $this->line(
                sprintf(
                    '%d | %-6d | %s (%s) -> %s (%s)',
                    $party->election?->year,
                    $party->source_party_number,
                    $oldName,
                    $oldAbbreviation,
                    $name,
                    $abbreviation,
                ),
            );
        }

        $this->newLine();

        $this->info(
            sprintf(
                'Updated %d party name(s)%s.',
                $updated,
                $year === null ? '' : " for {$year}",
            ),
        );

        if ($missing !== []) {
            $this->newLine();
            $this->warn('Parties without configured names:');

            $this->table(
                [
                    'Year',
                    'Source #',
                    'Abbreviation',
                    'Name',
                ],
                $missing,
            );
        }

        return self::SUCCESS;
    }
}
