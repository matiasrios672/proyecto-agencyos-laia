<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $quote->number }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1b1730; margin: 0; }
        .band { background: #162660; color: #fff; padding: 22px 40px 20px; }
        .band table { width: 100%; border-collapse: collapse; }
        .band td { vertical-align: middle; }
        .logo-img { height: 66px; }
        .tag { font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: #a3aed6; margin-top: 3px; }
        .right { text-align: right; }
        .num { font-size: 18px; font-weight: bold; }
        .muted { color: #5b6489; }
        .accent-line { height: 5px; background: #f5a773; }
        .content { padding: 28px 40px 0; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .meta td { vertical-align: top; width: 50%; padding-right: 20px; }
        .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1.5px; color: #6c6784; margin-bottom: 4px; }
        .name { font-size: 14px; font-weight: bold; margin-bottom: 3px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { background: #eef0f9; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #5b6489; padding: 8px 10px; }
        table.items td { padding: 10px; border-bottom: 1px solid #e0e4f1; vertical-align: top; }
        .num-col { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin-left: 54%; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 5px 10px; }
        .totals .grand td { background: #162660; color: #fff; font-size: 13px; font-weight: bold; padding: 10px; }
        .notes { margin-top: 22px; padding: 12px 14px; background: #f6f7fc; border-left: 3px solid #f5a773; }
        .bank { margin-top: 22px; font-size: 9.5px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 14px 40px; border-top: 1px solid #e0e4f1; font-size: 8.5px; color: #5b6489; }
        .footer .sky { color: #6ab9cf; font-weight: bold; }
    </style>
</head>
<body>
    <div class="band">
        <table>
            <tr>
                <td>
                    @if($logo)<img class="logo-img" src="{{ $logo }}" alt="Laia Estudio Creativo Digital">@else<strong style="font-size:24px">laia</strong>@endif
                </td>
                <td class="right">
                    <div class="tag" style="margin:0 0 4px">Presupuesto</div>
                    <div class="num">{{ $quote->number }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="accent-line"></div>

    <div class="content">
        <table class="meta">
            <tr>
                <td>
                    <div class="label">Preparado para</div>
                    <div class="name">{{ $recipient['name'] }}</div>
                    @if($recipient['tax_id'])<div class="muted">CUIT {{ $recipient['tax_id'] }}</div>@endif
                    @if($recipient['email'])<div class="muted">{{ $recipient['email'] }}</div>@endif
                    @if($recipient['phone'])<div class="muted">{{ $recipient['phone'] }}</div>@endif
                </td>
                <td>
                    <div class="label">Fecha de emisión</div>
                    <div>{{ $quote->created_at->locale('es')->translatedFormat('j \d\e F \d\e Y') }}</div>
                    @if($quote->valid_until)
                        <div class="label" style="margin-top:10px">Válido hasta</div>
                        <div>{{ $quote->valid_until->locale('es')->translatedFormat('j \d\e F \d\e Y') }}</div>
                    @endif
                    <div class="label" style="margin-top:10px">Moneda</div>
                    <div>{{ $quote->currency->label() }}</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Servicio</th>
                    <th>Frecuencia</th>
                    <th class="num-col">Cant.</th>
                    <th class="num-col">Precio unit.</th>
                    <th class="num-col">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td>{{ $item['frequency'] }}</td>
                        <td class="num-col">{{ $item['quantity'] }}</td>
                        <td class="num-col">{{ $item['unit_price'] }}</td>
                        <td class="num-col"><strong>{{ $item['total'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td class="muted">Servicios mensuales</td><td class="num-col">{{ $money['monthly'] }}</td></tr>
            <tr><td class="muted">Pagos únicos</td><td class="num-col">{{ $money['once'] }}</td></tr>
            @if($quote->discount > 0)
                <tr><td class="muted">Descuento ({{ rtrim(rtrim(number_format((float) $quote->discount_percent, 2, ',', '.'), '0'), ',') }} %)</td><td class="num-col">− {{ $money['discount'] }}</td></tr>
            @endif
            <tr class="grand"><td>Total</td><td class="num-col">{{ $money['total'] }}</td></tr>
        </table>

        @if($quote->notes)
            <div class="notes">
                <div class="label">Condiciones y notas</div>
                {!! nl2br(e($quote->notes)) !!}
            </div>
        @endif

        @if(count($bank))
            <div class="bank">
                <div class="label">Datos para el pago</div>
                @if(isset($bank['holder']))Titular: {{ $bank['holder'] }}@endif
                @if(isset($bank['cuit'])) · CUIT {{ $bank['cuit'] }}@endif<br>
                @if(isset($bank['name']))Banco: {{ $bank['name'] }}@endif
                @if(isset($bank['cbu'])) · CBU/CVU: {{ $bank['cbu'] }}@endif
                @if(isset($bank['alias'])) · Alias: {{ $bank['alias'] }}@endif
            </div>
        @endif
    </div>

    <div class="footer">
        <span class="sky">Laia</span> Estudio Creativo Digital · Presupuesto {{ $quote->number }}
    </div>
</body>
</html>
