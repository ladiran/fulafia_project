<?php

namespace App\Console\Commands;

use App\Services\DefermentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deferments:flag-lapsed')]
#[Description('Flag deferments whose session has ended without the student returning, and tell the HoD')]
class FlagLapsedDeferments extends Command
{
    public function handle(DefermentService $deferments): int
    {
        $this->info('Deferments flagged as lapsed: '.$deferments->flagLapsed());

        return self::SUCCESS;
    }
}
