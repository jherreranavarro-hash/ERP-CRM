<?php

namespace App\Http\Controllers;

use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Empresa;
use Illuminate\Http\Request;

class EmpresaActivaController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['empresa_id' => ['required', 'integer']]);
        $user = $request->user();

        $permitida = $user->es_superadmin
            ? Empresa::where('activo', true)->whereKey($data['empresa_id'])->exists()
            : $user->empresas()->where('empresas.id', $data['empresa_id'])->exists();

        abort_unless($permitida, 403, 'No perteneces a esa empresa.');

        $request->session()->put('empresa_id', (int) $data['empresa_id']);
        app(Auditoria::class)->registrar('empresa.cambiar_activa', null, null, ['empresa_id' => (int) $data['empresa_id']]);

        return redirect()->route('inicio');
    }
}
