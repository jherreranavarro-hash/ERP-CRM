<?php

namespace Tests\Unit;

use App\Modules\Facturacion\Calculo\Calculadora;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculadoraTest extends TestCase
{
    private Calculadora $c;

    protected function setUp(): void
    {
        $this->c = new Calculadora;
    }

    public function test_redondeo_mitad_hacia_arriba_y_no_bancario(): void
    {
        $this->assertSame(3, Calculadora::red('2.5'));   // bancario daría 2
        $this->assertSame(4, Calculadora::red('3.5'));
        $this->assertSame(2, Calculadora::red('2.4999'));
        $this->assertSame(1001, Calculadora::red('1000.5'));
    }

    public function test_linea_con_decimales_no_se_arrastra_al_total(): void
    {
        // 3 × 333,333333 = 999,999999 → 1.000 pesos
        $this->assertSame(['bruto' => 1000, 'descuento' => 0, 'monto_item' => 1000], $this->c->linea('3', '333.333333'));
    }

    public function test_descuento_de_linea_porcentual_y_por_monto(): void
    {
        $this->assertSame(['bruto' => 10000, 'descuento' => 1250, 'monto_item' => 8750], $this->c->linea(2, 5000, '12.5'));
        $this->assertSame(['bruto' => 10000, 'descuento' => 300, 'monto_item' => 9700], $this->c->linea(2, 5000, 0, 300));
    }

    public function test_descuento_mayor_que_la_linea_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->linea(1, 1000, 0, 1001);
    }

    #[DataProvider('lineasInvalidas')]
    public function test_lineas_invalidas(string $cant, string $precio, string $pct): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->linea($cant, $precio, $pct);
    }

    public static function lineasInvalidas(): array
    {
        return ['cantidad cero' => ['0', '100', '0'], 'precio negativo' => ['1', '-1', '0'], 'descuento > 100' => ['1', '100', '100.01']];
    }

    public function test_factura_33(): void
    {
        $r = $this->c->documento(33, [['monto_item' => 10000, 'exento' => false], ['monto_item' => 2335, 'exento' => false]], 0, 19);

        $this->assertSame(12335, $r['monto_neto']);
        $this->assertSame(2344, $r['monto_iva']);    // 2343,65 → 2344
        $this->assertSame(14679, $r['monto_total']);
    }

    public function test_descuento_global_solo_sobre_afecto(): void
    {
        $r = $this->c->documento(33, [['monto_item' => 10000, 'exento' => false], ['monto_item' => 5000, 'exento' => true]], 10, 19);

        $this->assertSame(1000, $r['descuento_global_monto']);
        $this->assertSame(9000, $r['monto_neto']);
        $this->assertSame(5000, $r['monto_exento']);
        $this->assertSame(1710, $r['monto_iva']);
        $this->assertSame(15710, $r['monto_total']);
    }

    public function test_boleta_39_cuadra_al_peso(): void
    {
        $r = $this->c->documento(39, [['monto_item' => 11990, 'exento' => false]], 0, 19);

        $this->assertSame(10076, $r['monto_neto']);   // 11990 / 1,19 = 10075,63
        $this->assertSame(1914, $r['monto_iva']);
        $this->assertSame(11990, $r['monto_total'], 'El total de boleta es exactamente lo cobrado');
    }

    public function test_exentos_sin_iva_y_sin_lineas_afectas(): void
    {
        $r = $this->c->documento(34, [['monto_item' => 7777, 'exento' => true]], 0, 19);
        $this->assertSame([0, 7777, 0, 7777], [$r['monto_neto'], $r['monto_exento'], $r['monto_iva'], $r['monto_total']]);

        $this->expectException(InvalidArgumentException::class);
        $this->c->documento(41, [['monto_item' => 100, 'exento' => false]], 0, 19);
    }

    public function test_precio_de_boleta_derivado_del_neto(): void
    {
        $this->assertSame(11900, $this->c->precioConIva('10000', 19));
        $this->assertSame(1190, $this->c->precioConIva('999.9', 19));   // 1189,881 → 1190
    }

    public function test_total_siempre_es_neto_mas_iva_mas_exento(): void
    {
        mt_srand(7);
        foreach ([33, 39, 56, 61] as $tipo) {
            for ($i = 0; $i < 200; $i++) {
                $lineas = [];
                for ($j = 0, $n = mt_rand(1, 8); $j < $n; $j++) {
                    $l = $this->c->linea((string) (mt_rand(1, 50000) / 1000), (string) (mt_rand(0, 9999999) / 100), (string) (mt_rand(0, 3000) / 100));
                    $lineas[] = ['monto_item' => $l['monto_item'], 'exento' => mt_rand(0, 4) === 0];
                }
                $r = $this->c->documento($tipo, $lineas, (string) (mt_rand(0, 1500) / 100), 19);
                $this->assertSame($r['monto_total'], $r['monto_neto'] + $r['monto_iva'] + $r['monto_exento']);
                $this->assertSame($r['afecto'] - $r['descuento_global_monto'], $tipo === 39 ? $r['monto_neto'] + $r['monto_iva'] : $r['monto_neto']);
            }
        }
    }
}
