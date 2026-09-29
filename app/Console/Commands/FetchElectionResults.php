<?php

namespace App\Console\Commands;

use App\Models\Election;
use App\Services\ElectionResultsImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:fetch-election-results {year} {file}')]
#[Description('Import election results manually from a JSON file')]
class FetchElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ElectionResultsImporter $importer): int
    {
        $year = (int) $this->argument('year');
        $file = $this->argument('file');

        $election = Election::query()
            ->where('year', $year)
            ->first();

        if ($election === null) {
            $this->error("Election {$year} does not exist.");

            return self::FAILURE;
        }

        try {
            $snapshot = $importer->importFile(
                $election,
                $file
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($snapshot === null) {
            $this->info("No changes detected for election {$year}.");
        } else {
            $this->info(
                "Imported snapshot {$snapshot->getKey()} for election {$year}."
            );
        }

        return self::SUCCESS;
    }
}
