<?php

namespace Database\Factories;

use App\Modules\Core\Models\Empresa;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Empresa> */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        $cuerpo = (string) fake()->unique()->numberBetween(76000000, 78999999);

        return [
            'rut' => $cuerpo.'-'.Rut::dv($cuerpo),
            'razon_social' => fake()->company().' SpA',
            'giro' => 'Comercio',
            'comuna' => 'Santiago',
            'activo' => true,
        ];
    }
}
