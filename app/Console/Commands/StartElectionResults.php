<?php

namespace App\Console\Commands;

use App\Models\Election;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('app:start-election-results {year}')]
#[Description('Enable election results collection')]
class StartElectionResults extends Command
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
            true
        );

        $this->info("Election results collection enabled for $year.");

        return self::SUCCESS;
    }
}
