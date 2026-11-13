<?php

namespace App\Console\Commands;

use App\Actions\Finance\GenerateInvoices;
use Illuminate\Console\Command;

class GenerateInvoicesCommand extends Command
{
    protected $signature = 'finance:generate-invoices';

    protected $description = 'Genera los cobros que corresponden y actualiza el estado de cuenta de cada cliente';

    public function handle(GenerateInvoices $generate): int
    {
        $created = $generate->forAll();
        $this->info("Cobros creados: {$created}. Estados de cuenta actualizados.");

        return self::SUCCESS;
    }
}
