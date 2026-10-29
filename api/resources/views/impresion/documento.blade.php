@php
    $destinatario = $documento->destinatario();
    $fechas = $documento->fechas();
    $totales = $documento->totales();
    $secciones = $documento->secciones();
    $pie = $documento->pie();
    $marcaAgua = $documento->marcaAgua();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $documento->titulo() }}{{ $documento->consecutivo() ? ' '.$documento->consecutivo() : '' }}</title>
    <style>
        @page { margin: 34pt 34pt 44pt 34pt; }
        body { font-family: Helvetica, sans-serif; font-size: 8pt; color: #111827; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: middle; }

        .cabecera td { padding: 0; }
        .logo { max-width: 120pt; max-height: 58pt; }
        .empresa { text-align: center; font-size: 8pt; line-height: 1.35; }
        .empresa .nombre { font-weight: bold; font-size: 8.5pt; text-transform: uppercase; }
        .caja-documento { border: 1px solid #9ca3af; text-align: center; padding: 12pt 6pt; }
        .caja-documento .titulo { font-size: 11pt; }
        .caja-documento .consecutivo { font-weight: bold; font-size: 9.5pt; margin-top: 3pt; }

        .datos td { padding: 0; vertical-align: top; }
        .campos { width: 100%; border: 1px solid #d1d5db; }
        .campos td { padding: 4pt 5pt; border-bottom: 1px solid #d1d5db; }
        .campos tr:last-child td { border-bottom: 0; }
        .campos .etiqueta { font-weight: bold; width: 17%; }
        .fechas { width: 100%; border: 1px solid #d1d5db; }
        .fechas th { padding: 4pt 5pt; border-bottom: 1px solid #d1d5db; font-weight: bold; text-align: center; }
        .fechas td { padding: 4pt 5pt; border-bottom: 1px solid #d1d5db; }
        .fechas tr:last-child td { border-bottom: 0; }
        .fechas .etiqueta { font-weight: bold; width: 42%; border-right: 1px solid #d1d5db; }
        .fechas .valor { text-align: center; }

        .lineas { margin-top: 18pt; table-layout: fixed; }
        .lineas th { border: 1px solid #d1d5db; padding: 5pt 6pt; font-weight: bold; text-align: center; }
        .lineas td { border-left: 1px solid #d1d5db; border-right: 1px solid #d1d5db; padding: 4pt 6pt; }
        .lineas tbody tr:last-child td { border-bottom: 1px solid #d1d5db; }
        .lineas thead { display: table-header-group; }
        .lineas tr { page-break-inside: avoid; }
        .lineas .derecha { text-align: right; }
        .lineas .total td { font-weight: bold; border-top: 1px solid #6b7280; border-bottom: 1px solid #d1d5db; }
        .horizontal .lineas th, .horizontal .lineas td { font-size: 7pt; padding: 3pt 4pt; }
        .titulo-tabla { font-size: 9.5pt; font-weight: bold; margin-top: 18pt; }

        .resumen { margin-top: 12pt; }
        .resumen td { vertical-align: top; padding: 0; }
        .total-items { font-size: 10pt; font-weight: bold; margin-bottom: 8pt; }
        .total-items span { font-weight: normal; }
        .seccion { margin-bottom: 8pt; }
        .seccion .titulo { font-size: 10pt; font-weight: bold; }
        .seccion .texto { margin-top: 2pt; padding-left: 8pt; }
        .totales { width: 100%; border: 1px solid #d1d5db; }
        .totales td { padding: 5pt 6pt; border-bottom: 1px solid #d1d5db; }
        .totales tr:last-child td { border-bottom: 0; }
        .totales .etiqueta { font-weight: bold; width: 50%; border-right: 1px solid #d1d5db; }
        .totales .valor { text-align: right; }

        .pie { margin-top: 24pt; text-align: center; font-size: 6.5pt; color: #4b5563; line-height: 1.4; }

        .marca-agua {
            position: fixed;
            top: 260pt;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 64pt;
            font-weight: bold;
            color: #dc2626;
            opacity: 0.14;
            transform: rotate(-30deg);
        }
    </style>
</head>
<body class="{{ $horizontal ? 'horizontal' : '' }}">
    @if ($marcaAgua)
        <div class="marca-agua">{{ $marcaAgua }}</div>
    @endif

    <table class="cabecera">
        <tr>
            <td style="width: 30%;">
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ $empresa->nombre_empresa }}">
                @endif
            </td>
            <td style="width: 40%;" class="empresa">
                @if ($campos->nombre)<div class="nombre">{{ $empresa->nombre_empresa }}</div>@endif
                @if ($campos->nit && $empresa->nitConDigito())<div>NIT {{ $empresa->nitConDigito() }}</div>@endif
                @if ($campos->direccion && $empresa->direccion)<div>{{ $empresa->direccion }}</div>@endif
                @if ($campos->telefono && $empresa->telefono)<div>Tel: {{ $empresa->telefono }}</div>@endif
                @if ($campos->ubicacion && $ubicacion)<div>{{ $ubicacion }}</div>@endif
                @if ($campos->correo && $empresa->correo)<div>{{ $empresa->correo }}</div>@endif
            </td>
            <td style="width: 30%; padding-left: 14pt;">
                <div class="caja-documento">
                    <div class="titulo">{{ $documento->titulo() }}</div>
                    @if ($documento->consecutivo())
                        <div class="consecutivo">No. {{ $documento->consecutivo() }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="datos" style="margin-top: 22pt;">
        <tr>
            <td style="width: 62%;">
                <table class="campos">
                    <tr>
                        <td class="etiqueta">Señores</td>
                        <td colspan="3">{{ $destinatario->nombre }}</td>
                    </tr>
                    @if ($destinatario->documento || $destinatario->telefono)
                        <tr>
                            <td class="etiqueta">{{ $destinatario->etiquetaDocumento }}</td>
                            <td style="width: 30%;">{{ $destinatario->documento }}</td>
                            <td class="etiqueta">Teléfono</td>
                            <td>{{ $destinatario->telefono }}</td>
                        </tr>
                    @endif
                    @if ($destinatario->direccion || $destinatario->ciudad)
                        <tr>
                            <td class="etiqueta">Dirección</td>
                            <td>{{ $destinatario->direccion }}</td>
                            <td class="etiqueta">Ciudad</td>
                            <td>{{ $destinatario->ciudad }}</td>
                        </tr>
                    @endif
                </table>
            </td>
            <td style="width: 38%; padding-left: 14pt;">
                <table class="fechas">
                    <tr><th colspan="2">Fechas del documento</th></tr>
                    @foreach ($fechas as $fecha)
                        <tr>
                            <td class="etiqueta">{{ $fecha->etiqueta }}</td>
                            <td class="valor">{{ $formateador->fecha($fecha->valor, $fecha->conHora) }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    @if ($documento->tituloTabla())
        <div class="titulo-tabla">{{ $documento->tituloTabla() }}</div>
    @endif

    <table class="lineas" @if ($documento->tituloTabla()) style="margin-top: 6pt;" @endif>
        <thead>
            <tr>
                @foreach ($columnas as $columna)
                    <th @if ($columna->ancho) style="width: {{ $columna->ancho }}%;" @endif>{{ $columna->encabezado }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    @foreach ($columnas as $columna)
                        <td class="{{ $columna->tipo->alineacion() === 'right' ? 'derecha' : '' }}">{!! nl2br(e($formateador->celda($fila[$columna->clave] ?? null, $columna))) !!}</td>
                    @endforeach
                </tr>
            @endforeach
            @php($altoRelleno = ($tablasAdicionales !== [] || $horizontal) ? 0 : max(0, 290 - count($filas) * 18 - max(0, count($secciones) - 3) * 40))
            @if ($altoRelleno > 0)
                <tr>
                    <td colspan="{{ count($columnas) }}" style="height: {{ $altoRelleno }}pt;"></td>
                </tr>
            @endif
        </tbody>
    </table>

    @foreach ($tablasAdicionales as $tabla)
        @if ($tabla->titulo !== '')
            <div class="titulo-tabla" style="margin-top: 16pt;">{{ $tabla->titulo }}</div>
        @endif
        <table class="lineas" style="margin-top: {{ $tabla->titulo !== '' ? '6pt' : '0' }};">
            @if ($tabla->conEncabezado)
                <thead>
                    <tr>
                        @foreach ($tabla->columnas as $columna)
                            <th @if ($columna->ancho) style="width: {{ $columna->ancho }}%;" @endif>{{ $columna->encabezado }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody>
                @if ($tabla->filas === [] && $tabla->mensajeVacio !== null)
                    <tr>
                        <td colspan="{{ count($tabla->columnas) }}" style="text-align: center; padding: 10pt 6pt;">{{ $tabla->mensajeVacio }}</td>
                    </tr>
                @endif
                @foreach ($tabla->filas as $fila)
                    <tr>
                        @foreach ($tabla->columnas as $columna)
                            <td class="{{ $columna->tipo->alineacion() === 'right' ? 'derecha' : '' }}">{!! nl2br(e($formateador->celda($fila[$columna->clave] ?? null, $columna))) !!}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($tabla->filaTotal)
                    <tr class="total">
                        @foreach ($tabla->columnas as $columna)
                            <td class="{{ $columna->tipo->alineacion() === 'right' ? 'derecha' : '' }}" @if (! $tabla->conEncabezado && $columna->ancho) style="width: {{ $columna->ancho }}%;" @endif>{!! nl2br(e($formateador->celda($tabla->filaTotal[$columna->clave] ?? null, $columna))) !!}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach

    <table class="resumen">
        <tr>
            <td style="width: 58%;">
                <div class="total-items">Total ítems: <span>{{ count($filas) }}</span></div>
                @foreach ($secciones as $seccion)
                    <div class="seccion">
                        <div class="titulo">{{ $seccion->titulo }}:</div>
                        <div class="texto">{!! nl2br(e($seccion->texto)) !!}</div>
                    </div>
                @endforeach
            </td>
            <td style="width: 42%; padding-left: 14pt;">
                @if ($totales !== [])
                    <table class="totales">
                        @foreach ($totales as $total)
                            <tr>
                                <td class="etiqueta">{{ $total->etiqueta }}</td>
                                <td class="valor">{{ $formateador->dinero($total->valor) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td>
        </tr>
    </table>

    @if ($pie)
        <div class="pie">{!! nl2br(e($pie)) !!}</div>
    @endif
</body>
</html>
