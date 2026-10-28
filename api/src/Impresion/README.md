# Módulo Impresion

Genera en PDF los **documentos** del sistema (factura, notas, recibo de caja, comprobante, estado de cuenta) con una sola plantilla base. No es para reportes: esos se exportan a Excel/CSV con `Exportacion`.

## Qué es igual en todos los documentos

La vista `resources/views/impresion/documento.blade.php` dibuja siempre lo mismo: carta vertical, sin marco, encabezado con el logo y los datos de la empresa (módulo Empresas, con su municipio y país), la caja con el nombre y el consecutivo en la misma posición, el bloque "Señores" con los datos del destinatario, la tabla de fechas, "Total ítems", los totales, los textos y el pie. Un cambio de diseño se hace en ese archivo y llega a todos.

## Qué decide cada documento

Solo implementa `Contracts/DocumentoPdf` y entrega datos, nunca HTML:

- `titulo()`, `consecutivo()` (nulo si no tiene), `nombreArchivo()` y `orientacion()` (`OrientacionDocumento`: **vertical para todos, salvo el reporte por empleador, que es horizontal**).
- `destinatario()` (`DestinatarioDocumento`; `etiquetaDocumento` es "NIT" salvo que el documento use otra, p. ej. "CC") y `fechas()` (`FechaDocumento`).
- `tituloTabla()` (nulo si no lleva), `columnas()` (`ColumnaDocumento`: clave, encabezado, tipo y ancho en %) y `filas()`. **Los encabezados de la tabla son libres**: ítem, cantidad, descripción y valor cambian según el documento.
- `tablasAdicionales()`: tablas bajo la principal (`TablaDocumento`: título —vacío si no lleva—, columnas, filas, fila de total opcional, mensaje si no hay filas y `conEncabezado`, falso para una fila de totales que continúa a la principal con las mismas columnas). Ejemplos: el estado de cuenta trae el plan de cuotas como tabla principal y el detalle de pagos como adicional; el reporte por empleador pone los totales por mes como tabla adicional sin encabezado.
- `totales()` (`TotalDocumento`, con `Dinero`), `secciones()` (valor en letras, forma de pago, observaciones…) y `pie()`.

`NumeroALetras::pesos(Dinero)` escribe el valor en letras ("Un millón doscientos veintidós mil trescientos ochenta pesos m/cte").

## Cómo generar un documento

```php
$pdf = app(GeneradorPdf::class)->generar($documento, $empresa); // bytes
return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$documento->nombreArchivo().'.pdf"']);
```

Un documento real se arma en el Service de su módulo (ver `Creditos/Documentos/EstadoCuentaPdf` y `EstadoCuentaService`), que entrega el `PdfGenerado` (contenido y nombre) y deja el evento en la bitácora. Quien llama entrega la `Empresa` ya resuelta con el scope del usuario; el generador no consulta datos de negocio. Los PDF se generan al momento (sin cola): un documento tiene pocas líneas.

## Reglas

- **Cifras y fechas** las formatea `FormateadorDocumento` como la plantilla de DDA: coma de miles y punto decimal. Los montos viajan como `Dinero`. Una fecha **con hora** es un instante y se lleva a hora de Bogotá; una fecha **sin hora** (vencimiento, desembolso) es de calendario y se imprime tal cual, sin correrla de día.
- **Logo**: el PDF solo dibuja PNG, JPG o GIF (`LogoEmpresa::TIPOS_ADMITIDOS`); dompdf pinta el SVG con fondo negro. Si la empresa sube un SVG (se ve mejor en la web, por eso `logo_path` lo conserva), `EmpresaService::actualizarLogoPropia` lo rasteriza con `rsvg-convert` (paquete `rsvg-convert` del `Dockerfile`) y guarda el PNG resultante en `logo_pdf_path`; `LogoEmpresa::comoDataUri` prefiere esa columna y solo cae a `logo_path` cuando no existe. Si `rsvg-convert` falla, `logo_pdf_path` queda `null` y el PDF sale sin logo, sin romperse.
- **Horizontal** (`OrientacionDocumento::Horizontal`): la fuente de la tabla baja a 7 pt y las celdas aceptan saltos de línea (`
`), lo que permite valor y estado en dos líneas para caber muchos meses. Los anchos de columna (`ancho`, en %) se aplican a las celdas de la primera fila.
- Varias páginas: la cabecera de la tabla se repite, los totales van una sola vez al final y cada página lleva "Página x de y".
- Probar un documento nuevo: `tests/Support/DocumentoPdfDePrueba` y `tests/Feature/Impresion/GeneradorPdfTest` muestran cómo generar y leer el texto del PDF.
