<?php

namespace App\Modules\Mensajeria\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/** Plantilla de WhatsApp aprobada por Meta: la única forma de escribir fuera de la ventana de 24 h. */
class Plantilla extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'wa_plantillas';

    protected $guarded = ['id', 'empresa_id'];

    protected $attributes = ['idioma' => 'es', 'categoria' => 'utility', 'estado' => 'aprobada'];

    public function variables(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->cuerpo, $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    /** @param  list<string>  $valores */
    public function completar(array $valores): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $valores[(int) $m[1] - 1] ?? $m[0], $this->cuerpo);
    }
}
