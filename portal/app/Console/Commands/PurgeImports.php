<?php

namespace App\Console\Commands;

use App\Models\StudentImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('imports:purge')]
#[Description('Delete uploaded student CSVs and unused temporary-password files older than a day')]
class PurgeImports extends Command
{
    public function handle(): int
    {
        $count = 0;
        StudentImport::where('updated_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNotNull('file_path')->orWhereNotNull('credentials_path'))
            ->each(function (StudentImport $import) use (&$count) {
                foreach (['file_path', 'credentials_path'] as $column) {
                    if ($import->{$column}) {
                        Storage::disk('local')->delete($import->{$column});
                        $import->{$column} = null;
                        $count++;
                    }
                }
                $import->save();
            });

        $this->info("Removed {$count} stored file(s).");

        return self::SUCCESS;
    }
}
