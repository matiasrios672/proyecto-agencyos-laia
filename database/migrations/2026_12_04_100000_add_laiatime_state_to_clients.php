<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estado de la instancia de LaiaTime de cada cliente y rastro de las suspensiones automáticas por mora. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('laiatime_status', 20)->nullable()->after('laiatime_instance_id');       // trial | active | past_due | canceled | unknown | missing (suscripción en LaiaTime)
            $table->timestamp('laiatime_synced_at')->nullable()->after('laiatime_status');
            $table->timestamp('laiatime_suspended_at')->nullable()->after('laiatime_synced_at');
            // true solo si el sistema informó la mora (PAST_DUE): es lo único que el sistema puede reactivar solo.
            $table->boolean('laiatime_suspended_by_system')->default(false)->after('laiatime_suspended_at');
            // Tras una reactivación manual, el cliente tiene un nuevo plazo de gracia: no se vuelve a suspender hasta esa fecha.
            $table->date('laiatime_override_until')->nullable()->after('laiatime_suspended_by_system');
            $table->string('laiatime_last_error', 255)->nullable()->after('laiatime_override_until');
            $table->index('laiatime_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['laiatime_instance_id']);
            $table->dropColumn([
                'laiatime_status', 'laiatime_synced_at', 'laiatime_suspended_at',
                'laiatime_suspended_by_system', 'laiatime_override_until', 'laiatime_last_error',
            ]);
        });
    }
};
