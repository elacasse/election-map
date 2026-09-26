<?php

namespace App\Console\Commands;

use App\Models\ElectionParty;
use Illuminate\Console\Command;

class BackfillPartyColors extends Command
{
    protected $signature = 'election:backfill-party-colors
                            {year? : Election year}';
    protected $description = 'Populate election party colors using source_party_number';

    public function handle(): int
    {
        $year = $this->argument('year');

        $colors = [
            0  => '808080', // Indépendants
            2  => '3A7D44', // Bloc pot
            6  => 'D71920', // Parti libéral du Québec
            7  => 'DB2C1B', // Parti marxiste-léniniste du Québec
            8  => '1E295C', // Parti québécois
            10 => '9ACD32', // Parti vert du Québec
            22 => '0055A5', // Parti conservateur du Québec
            23 => '2D2D2D', // Parti nul
            27 => '00A7B5', // Coalition avenir Québec
            29 => '6A4C93', // Équipe autonomiste
            37 => '003399', // Parti 51
            40 => 'F58220', // Québec solidaire
            42 => 'FEDD2E', // Parti culinaire du Québec

            99209 => '315C9B', // Union nationale
            99236 => '176B87', // Parti accès propriété et équité
            99237 => '39B54A', // Climat Québec
            99263 => '1F3A5F', // Parti canadien du Québec
            99275 => '8B1E3F', // L'union fait la force
            99281 => 'D94F70', // Parti humain du Québec
            99285 => '6F42C1', // Bloc Montréal
            99286 => 'E0A800', // Démocratie directe
            99291 => 'C99700', // Parti libertarien du Québec
            99293 => 'B5651D', // Alliance pour la famille et les communautés

            99330 => '6F42C1', // Québec innovant
            99331 => '2A9D8F', // Présence Québec
            99341 => '176B87', // PAPE - Équipe Québec debout
            99352 => '527A9E', // Parti populaire du Québec
        ];

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
            $color = $colors[$party->source_party_number] ?? null;

            if ($color === null) {
                $missing[] = [
                    $party->election?->year,
                    $party->source_party_number,
                    $party->abbreviation,
                    $party->name,
                ];

                continue;
            }

            if ($party->color === $color) {
                continue;
            }

            $party->update([
                'color' => $color,
            ]);

            $updated++;

            $this->line(
                sprintf(
                    '%d | %-6d | %-12s | #%s | %s',
                    $party->election?->year,
                    $party->source_party_number,
                    $party->abbreviation,
                    $color,
                    $party->name,
                ),
            );
        }

        $this->newLine();

        $this->info(
            sprintf(
                'Updated %d party color(s)%s.',
                $updated,
                $year === null ? '' : " for {$year}",
            ),
        );

        if ($missing !== []) {
            $this->newLine();
            $this->warn('Parties without a configured color:');

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
