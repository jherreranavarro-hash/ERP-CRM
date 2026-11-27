<?php

namespace App\Modules\Marketing\Models;

use App\Modules\ContactCenter\Models\Campana;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Campaña de Meta Ads sincronizada (solo lectura) y su campaña de destino en el contact center. */
class AdsCampana extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'ads_campanas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['sincronizada_at' => 'datetime'];
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }
}
