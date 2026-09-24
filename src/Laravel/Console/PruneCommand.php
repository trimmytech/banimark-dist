<?php

namespace Banimark\Laravel\Console;

use Banimark\Storage\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The retention policy, on demand. The panel runs it once a day by itself
 * (when a staff member is in it); this is for a scheduler, or for a one-off.
 */
class PruneCommand extends Command
{
    protected $signature = 'banimark:prune {--days= : override the Data & protection setting for this run}';
    protected $description = 'Delete chat history older than the retention setting';

    public function handle(): int
    {
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        $days = $this->option('days') !== null ? max(0, (int) $this->option('days')) : Retention::days($settings);
        if ($days <= 0) {
            $this->info('Retention is "keep forever" - nothing to do. Set days on the Data & protection page or pass --days.');
            return self::SUCCESS;
        }
        $n = (new Retention(DB::connection()->getPdo(), app(\Banimark\Files\FileStore::class)))->prune($days);
        $this->info("Deleted {$n} conversation(s) quiet for more than {$days} days.");
        return self::SUCCESS;
    }
}
