<?php

namespace App\Console\Commands;

use App\Services\NpoRadioService;
use Illuminate\Console\Command;

class Fetch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        app(NpoRadioService::class)->fetchAllBroadcasts('npo-3fm', '3voor12-radio');

    }
}
