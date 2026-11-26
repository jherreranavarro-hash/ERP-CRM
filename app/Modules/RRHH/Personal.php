<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\EmpleadoDocumento;
use App\Support\Rut;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ficha de personal y su expediente de documentos (cifrados en reposo). */
class Personal
{
    public function __construct(private Auditoria $auditoria, private Tenancy $tenancy) {}

    public function guardar(array $d, ?Empleado $e = null): Empleado
    {
        $rut = Rut::normalizar($d['rut'] ?? null);
        if (! $rut || ! Rut::valido($rut)) {
            throw ValidationException::withMessages(['rut' => 'El RUT no es válido.']);
        }
        if (Empleado::where('rut', $rut)->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
            throw ValidationException::withMessages(['rut' => 'Ya existe una ficha con este RUT.']);
        }
        if (! array_key_exists($d['afp'], Empleado::AFPS)) {
            throw ValidationException::withMessages(['afp' => 'Elige una AFP.']);
        }
        if (! empty($d['user_id']) && Empleado::where('user_id', $d['user_id'])->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Ese usuario ya está enlazado con otra ficha.']);
        }
        $datos = ['rut' => $rut, 'salud' => mb_strtolower(trim($d['salud'] ?? 'fonasa')) ?: 'fonasa'] + $d;
        if ($datos['salud'] === 'fonasa') {
            $datos['plan_isapre_uf'] = 0;
        }
        $antes = $e?->getAttributes();
        $e ??= new Empleado;
        $e->fill($datos)->save();
        $this->auditoria->registrar($antes ? 'empleado.editar' : 'empleado.crear', $e, null, ['rut' => $rut, 'nombre' => $e->nombreCompleto()]);

        return $e;
    }

    /** Guarda el archivo cifrado (AES-256 con la clave de la aplicación) fuera del disco público. */
    public function adjuntar(Empleado $e, UploadedFile $archivo, string $tipo, User $user): EmpleadoDocumento
    {
        if (! array_key_exists($tipo, EmpleadoDocumento::TIPOS)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de documento no válido.']);
        }
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $ruta = 'rrhh/'.$this->tenancy->id().'/'.$e->id.'/'.Str::uuid().'.enc';
        Storage::disk('local')->put($ruta, Crypt::encryptString(base64_encode($contenido)));
        $doc = EmpleadoDocumento::create([
            'empleado_id' => $e->id, 'tipo' => $tipo, 'nombre' => mb_substr($archivo->getClientOriginalName(), 0, 150),
            'ruta' => $ruta, 'mime' => $archivo->getMimeType() ?? 'application/octet-stream', 'bytes' => strlen($contenido),
            'sha256' => hash('sha256', $contenido), 'subido_por' => $user->id,
        ]);
        $this->auditoria->registrar('empleado.documento', $doc, null, ['empleado' => $e->nombreCompleto(), 'documento' => $doc->nombre]);

        return $doc;
    }

    /** Contenido descifrado; verifica la integridad contra la huella guardada. */
    public function contenido(EmpleadoDocumento $doc): string
    {
        $contenido = base64_decode(Crypt::decryptString(Storage::disk('local')->get($doc->ruta)));
        if (! hash_equals($doc->sha256, hash('sha256', $contenido))) {
            throw new \RuntimeException('El documento no coincide con su huella: posible alteración.');
        }
        $this->auditoria->registrar('empleado.documento_descarga', $doc, null, ['documento' => $doc->nombre]);

        return $contenido;
    }
}
