<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:fetch-election-results')]
#[Description('Command description')]
class FetchElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
    }
}
