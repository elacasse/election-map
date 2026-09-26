<?php

namespace App\Console\Commands;

use App\Models\Election;
use App\Services\ElectionCandidatesImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

#[Signature('election:import-candidates {year} {file}')]
#[Description('Import election parties, districts and candidates from a candidatures JSON file')]
class ImportElectionCandidates extends Command
{
    public function handle(ElectionCandidatesImporter $importer): int
    {
        $year = (int) $this->argument('year');
        $file = $this->argument('file');

        $election = Election::query()
            ->where('year', $year)
            ->first();

        if ($election === null) {
            $this->error(
                "Election {$year} does not exist."
            );

            return self::FAILURE;
        }

        try {
            $importer->import(
                $election,
                $file
            );
        } catch (RuntimeException|JsonException $exception) {
            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->info(
            "Candidates for election {$year} imported successfully."
        );

        return self::SUCCESS;
    }
}
