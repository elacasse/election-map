<?php

namespace App\Console\Commands;

use App\Models\Election;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('app:stop-election-results {year}')]
#[Description('Disable election results collection')]
class StopElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $year = (int) $this->argument('year');

        $election = Election::query()
                            ->where('year', $year)
                            ->first();

        if ($election === null) {
            $this->error("Election $year not found.");

            return self::FAILURE;
        }

        Cache::forever(
            "election:{$election->id}:results-enabled",
            false
        );

        $this->info("Election results collection disabled for $year.");

        return self::SUCCESS;
    }
}
