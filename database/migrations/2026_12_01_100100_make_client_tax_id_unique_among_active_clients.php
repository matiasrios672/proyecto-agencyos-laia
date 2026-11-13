<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El CUIT era único incluso entre clientes eliminados (soft delete): crear un cliente con el CUIT de uno eliminado
 * pasaba la validación (que ignora los eliminados) y fallaba en la base con un error 500.
 * Ahora es único solo entre clientes activos; al restaurar uno eliminado se valida contra los activos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_tax_id_unique');
        DB::statement('DROP INDEX IF EXISTS clients_tax_id_unique');
        DB::statement('CREATE UNIQUE INDEX clients_tax_id_active_unique ON clients (tax_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clients_tax_id_active_unique');
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_tax_id_unique UNIQUE (tax_id)');
    }
};
