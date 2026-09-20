<?php

namespace App\Console\Commands;

use App\Models\Election;
use App\Services\ElectionResultsImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('app:poll-election-results {year}')]
#[Description('Command description')]
class PollElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ElectionResultsImporter $importer): int
    {
        $year = (int) $this->argument('year');

        $election = Election::query()
                            ->where('year', $year)
                            ->first();

        if ($election === null) {
            $this->error("Election $year not found.");

            return self::FAILURE;
        }

        $now = now()->format('Y-m-d H:i:s');

        $enabled = Cache::get(
            "election:{$election->id}:results-enabled",
            false
        );

        if (!$enabled) {
            if (config('app.env') === 'local') {
                $this->info(
                    "[$now] Election results collection is disabled for $year."
                );
            }

            return self::SUCCESS;
        }

        try {
            $snapshot = $importer->import($election);
        } catch (Throwable $exception) {
            report($exception);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($snapshot === null) {
            $this->info("[$now] No changes detected for election $year.");
        } else {
            $this->info(
                "[$now] Imported snapshot {$snapshot->getKey()} for election $year."
            );
        }

        return self::SUCCESS;
    }
}
