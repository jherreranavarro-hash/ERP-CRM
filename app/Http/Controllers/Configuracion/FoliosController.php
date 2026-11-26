<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Configuración → Folios (CAF). Sin rangos solapados: lo exige la base. */
class FoliosController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Folios/Index', [
            'rangos' => FolioRango::orderBy('tipo_dte')->orderBy('desde')->get()->map(fn (FolioRango $r) => $r->only(['id', 'tipo_dte', 'desde', 'hasta', 'siguiente', 'caf_referencia', 'activo'])
                + ['vence' => $r->vence?->toDateString(), 'restantes' => $r->restantes(), 'tipo' => Documento::TIPOS[$r->tipo_dte]]),
            'tipos' => Documento::TIPOS,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'tipo_dte' => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'desde' => ['required', 'integer', 'min:1'],
            'hasta' => ['required', 'integer', 'gte:desde'],
            'caf_referencia' => ['nullable', 'string', 'max:120'],
            'vence' => ['nullable', 'date', 'after:today'],
        ]);

        try {
            FolioRango::create($d + ['siguiente' => $d['desde']]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'se solapa')) {
                throw ValidationException::withMessages(['desde' => 'El rango se solapa con otro del mismo tipo.']);
            }
            throw $e;
        }

        return back()->with('exito', 'Rango de folios cargado.');
    }

    public function desactivar(FolioRango $rango)
    {
        $rango->update(['activo' => false]);

        return back()->with('exito', 'Rango desactivado.');
    }
}
