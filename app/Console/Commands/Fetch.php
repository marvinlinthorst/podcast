<?php

namespace App\Console\Commands;

use App\Services\NpoRadioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Fetch extends Command
{
    protected $signature = 'app:fetch';

    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        collect(Storage::allFiles('npo-radio'))
            ->each(function ($file) {
                if (!Str::of($file)->endsWith('.json')) {
                    return;
                }

                $programme = pathinfo($file, PATHINFO_FILENAME);

                $channel = basename(dirname($file));

                app(NpoRadioService::class)->fetchAllBroadcasts($channel, $programme);
        });
    }
}
