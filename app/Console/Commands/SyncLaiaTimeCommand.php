<?php

namespace App\Console\Commands;

use App\Services\LaiaTime\LaiaTimeException;
use App\Services\LaiaTime\LaiaTimeSync;
use Illuminate\Console\Command;

class SyncLaiaTimeCommand extends Command
{
    protected $signature = 'laiatime:sync {--dry-run : Muestra qué haría sin escribir en LaiaTime ni en la base}';

    protected $description = 'Informa a LaiaTime la mora de cada cliente y reactiva a quienes regularizaron';

    public function handle(LaiaTimeSync $sync): int
    {
        try {
            $r = $sync->run((bool) $this->option('dry-run'));
        } catch (LaiaTimeException $e) {
            // Sin configurar no es una falla: la integración es opcional.
            $this->{$e->isNotConfigured() ? 'warn' : 'error'}($e->getMessage());

            return $e->isNotConfigured() ? self::SUCCESS : self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[simulación] ' : '')."LaiaTime — revisados: {$r['checked']}, en mora: {$r['past_due']}, reactivados: {$r['reactivated']}, inexistentes: {$r['missing']}, con error: {$r['errors']}.");

        return $r['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
