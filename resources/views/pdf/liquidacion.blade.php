@php
    $p = fn ($n) => '$'.number_format($n, 0, ',', '.');
    $mes = \Carbon\CarbonImmutable::parse($l->nomina->periodo.'-01')->locale('es')->isoFormat('MMMM [de] YYYY');
    $ind = $l->nomina->indicadores;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1E1E1E; }
    h1 { font-size: 15px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: 1px; }
    .naranja { color: #B4500B; }
    .barra { border-top: 3px solid #F7791E; margin: 8px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 6px; vertical-align: top; }
    th { text-align: left; background: #F6F4F1; font-size: 9px; text-transform: uppercase; color: #6B6560; }
    .r { text-align: right; }
    .linea td { border-bottom: 1px solid #E4DFD9; }
    .total td { font-weight: bold; border-top: 1px solid #1E1E1E; }
    .liquido { background: #1E1E1E; color: #F6F4F1; font-size: 13px; font-weight: bold; padding: 8px; }
    .gris { color: #6B6560; }
    .firma { margin-top: 50px; width: 45%; border-top: 1px solid #1E1E1E; text-align: center; padding-top: 4px; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>Liquidación de sueldo</h1><div class="naranja">{{ ucfirst($mes) }}</div></td>
        <td class="r"><strong>{{ $empresa?->razon_social }}</strong><br>RUT {{ \App\Support\Rut::formatear($empresa?->rut) }}<br>{{ $empresa?->direccion }} {{ $empresa?->comuna }}</td>
    </tr>
</table>
<div class="barra"></div>
<table>
    <tr class="linea"><td class="gris">Nombre</td><td>{{ $l->empleado->nombreCompleto() }}</td><td class="gris">RUT</td><td>{{ \App\Support\Rut::formatear($l->empleado->rut) }}</td></tr>
    <tr class="linea"><td class="gris">Cargo</td><td>{{ $l->contrato->cargo }}</td><td class="gris">Contrato</td><td>{{ \App\Modules\RRHH\Models\Contrato::TIPOS[$l->contrato->tipo] }}, {{ $l->contrato->jornada_horas }} h semanales</td></tr>
    <tr class="linea"><td class="gris">Días trabajados</td><td>{{ $l->dias_trabajados }}</td><td class="gris">AFP / Salud</td><td>{{ \App\Modules\RRHH\Models\Empleado::AFPS[$l->empleado->afp] ?? $l->empleado->afp }} / {{ $l->empleado->salud === 'fonasa' ? 'Fonasa' : ucfirst($l->empleado->salud) }}</td></tr>
</table>
<br>
<table>
    <tr>
        <td style="width:50%; padding:0 8px 0 0">
            <table>
                <tr><th>Haberes</th><th class="r">Monto</th></tr>
                @foreach ($l->haberes as $h)
                    <tr class="linea"><td>{{ $h['concepto'] }}@unless ($h['imponible']) <span class="gris">(no imponible)</span>@endunless</td><td class="r">{{ $p($h['monto']) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total imponible</td><td class="r">{{ $p($l->imponible) }}</td></tr>
                <tr class="total"><td>Total haberes</td><td class="r">{{ $p($l->imponible + $l->no_imponible) }}</td></tr>
            </table>
        </td>
        <td style="width:50%; padding:0 0 0 8px">
            <table>
                <tr><th>Descuentos</th><th class="r">Monto</th></tr>
                @foreach ($l->descuentos as $d)
                    <tr class="linea"><td>{{ $d['concepto'] }}</td><td class="r">{{ $p($d['monto']) }}</td></tr>
                @endforeach
                <tr class="linea"><td class="gris">Renta tributable</td><td class="r gris">{{ $p($l->tributable) }}</td></tr>
                <tr class="total"><td>Total descuentos</td><td class="r">{{ $p($l->total_descuentos) }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<br>
<div class="liquido">Líquido a pagar: {{ $p($l->liquido) }} <span style="font-weight:normal; font-size:10px">({{ $palabras }})</span></div>
<p class="gris">Aportes del empleador (no se descuentan): @foreach ($l->aportes as $a){{ $a['concepto'] }} {{ $p($a['monto']) }}@if (! $loop->last) · @endif @endforeach</p>
<p class="gris">Indicadores usados: UF {{ number_format((float) $ind['uf'], 2, ',', '.') }} · UTM {{ $p($ind['utm']) }} · Ingreso mínimo {{ $p($ind['imm']) }} · Tope imponible {{ $ind['tope_afp_uf'] }} UF.</p>
<table><tr><td><div class="firma">Empleador</div></td><td class="r"><div class="firma" style="margin-left:auto">Recibí conforme: {{ $l->empleado->nombreCompleto() }}</div></td></tr></table>
</body>
</html>
