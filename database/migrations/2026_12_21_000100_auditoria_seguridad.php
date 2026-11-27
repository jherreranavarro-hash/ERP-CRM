<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de seguridad: quién editó por última vez un documento o cotización.
 * La aprobación (cuatro ojos) exige que el aprobador no sea quien lo creó ni quien
 * lo dejó como está: de lo contrario, editar un borrador ajeno y aprobarlo uno mismo
 * eludía el control.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['documentos', 'cotizaciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->foreignId('editado_por')->nullable()->constrained('users');
            });
        }
    }

    public function down(): void
    {
        foreach (['documentos', 'cotizaciones'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('editado_por'));
        }
    }
};
