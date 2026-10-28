<?php

namespace App\Importacion\Definiciones;

use App\Importacion\Enums\TipoImportacion;

/**
 * Fuente de verdad de columnas por hoja, según `resources/plantilla_carga_creditos_dda.xlsx` (hoja Instrucciones).
 * El encabezado se busca por nombre exacto; `clave` es el nombre interno con el que viaja cada valor.
 */
final class DefinicionImportacion
{
    /** @return array{hoja: string, orden: int, columnas: list<array{clave: string, encabezado: string, obligatoria: bool, tipo: string}>, nota: string, permiso: array{0: string, 1: string}} */
    public static function para(TipoImportacion $tipo): array
    {
        return match ($tipo) {
            TipoImportacion::Clientes => [
                'hoja' => 'Clientes',
                'orden' => 1,
                'nota' => 'Cédula y nombre completo son obligatorios. El nombre se separa en nombres y apellidos (los dos últimos términos son apellidos).',
                'permiso' => ['importaciones', 'importar_clientes'],
                'columnas' => [
                    self::c('cedula', 'Cédula', true, 'texto'),
                    self::c('nombre', 'Nombre completo', true, 'texto'),
                    self::c('telefono', 'Teléfono', false, 'texto'),
                    self::c('correo', 'Correo electrónico', false, 'texto'),
                    self::c('empresa', 'Empresa (nómina)', false, 'texto'),
                    self::c('direccion', 'Dirección', false, 'texto'),
                    self::c('barrio', 'Barrio', false, 'texto'),
                    self::c('ciudad', 'Ciudad', false, 'texto'),
                    self::c('fecha_vinculo', 'Fecha de vínculo (si se conoce)', false, 'fecha'),
                    self::c('observaciones', 'Observaciones', false, 'texto'),
                ],
            ],
            TipoImportacion::Creditos => [
                'hoja' => 'Creditos',
                'orden' => 2,
                'nota' => 'Requiere que los clientes ya existan en el sistema. Las refinanciaciones no se importan en esta carga.',
                'permiso' => ['importaciones', 'importar_creditos'],
                'columnas' => [
                    self::c('codigo', 'Código de crédito', true, 'texto'),
                    self::c('cedula', 'Cédula cliente', true, 'texto'),
                    self::c('fecha_desembolso', 'Fecha de desembolso', true, 'fecha'),
                    self::c('valor', 'Valor desembolsado (capital)', true, 'monto'),
                    self::c('tasa', 'Tasa de interés mensual (%)', true, 'decimal'),
                    self::c('plazo', 'Plazo (# cuotas)', true, 'entero'),
                    self::c('periodicidad', 'Periodicidad (Mensual/Quincenal)', true, 'texto'),
                    self::c('cuota', 'Cuota fija (si ya la calcularon)', false, 'monto'),
                    self::c('gastos_administracion', 'Gastos administrativos (%)', false, 'decimal'),
                    self::c('es_refinanciacion', '¿Es refinanciación? (Sí/No)', false, 'si_no'),
                    self::c('codigo_anterior', 'Código del crédito anterior (si refinancia)', false, 'texto'),
                    self::c('saldo_refinanciado', 'Saldo refinanciado', false, 'monto'),
                    self::c('estado', 'Estado actual', false, 'texto'),
                    self::c('observaciones', 'Observaciones', false, 'texto'),
                ],
            ],
            TipoImportacion::PlanCuotas => [
                'hoja' => 'Plan_Cuotas',
                'orden' => 3,
                'nota' => 'Requiere que los créditos ya estén importados y sin pagos. Reemplaza las cuotas que calculó el sistema por las del archivo.',
                'permiso' => ['importaciones', 'importar_plan_cuotas'],
                'columnas' => [
                    self::c('codigo', 'Código de crédito', true, 'texto'),
                    self::c('numero', 'Número de cuota', true, 'entero'),
                    self::c('fecha', 'Fecha de vencimiento', true, 'fecha'),
                    self::c('capital', 'Valor capital', false, 'monto'),
                    self::c('interes', 'Valor interés', false, 'monto'),
                    self::c('total', 'Valor total cuota', true, 'monto'),
                    self::c('estado', 'Estado (Pendiente/Pagada/Vencida)', false, 'texto'),
                    self::c('observaciones', 'Observaciones', false, 'texto'),
                ],
            ],
            TipoImportacion::Pagos => [
                'hoja' => 'Pagos',
                'orden' => 4,
                'nota' => 'Requiere que los créditos ya estén importados con su plan. Se aplican en orden cronológico: primero interés, luego capital.',
                'permiso' => ['importaciones', 'importar_pagos'],
                'columnas' => [
                    self::c('codigo', 'Código de crédito', true, 'texto'),
                    self::c('fecha', 'Fecha de pago', true, 'fecha'),
                    self::c('valor', 'Valor pagado', true, 'monto'),
                    self::c('tipo', 'Tipo (Cuota completa/Abono parcial)', true, 'texto'),
                    self::c('numero', 'Número de cuota a la que aplica (si se sabe)', false, 'entero'),
                    self::c('cuenta', 'Cuenta/caja receptora', false, 'texto'),
                    self::c('registrado_por', 'Registrado por', false, 'texto'),
                    self::c('observaciones', 'Observaciones', false, 'texto'),
                ],
            ],
            TipoImportacion::SaldosIniciales => [
                'hoja' => 'Saldos_Contables_Iniciales',
                'orden' => 5,
                'nota' => 'Hoja opcional. La cuenta PUC debe existir en el plan de cuentas. Si generas el asiento de apertura, el archivo completo debe cuadrar (suma de débitos igual a suma de créditos). La cédula es opcional y solo aplica a cuentas de cartera: si la das, el cliente debe existir.',
                'permiso' => ['importaciones', 'importar_saldos_iniciales'],
                'columnas' => [
                    self::c('fecha_corte', 'Fecha de corte', true, 'fecha'),
                    self::c('cuenta', 'Cuenta PUC', true, 'texto'),
                    self::c('nombre', 'Nombre de la cuenta', false, 'texto'),
                    self::c('debito', 'Saldo débito', false, 'monto'),
                    self::c('credito', 'Saldo crédito', false, 'monto'),
                    self::c('cedula', 'Cédula', false, 'texto'),
                ],
            ],
            TipoImportacion::Gastos => [
                'hoja' => 'Gastos',
                'orden' => 8,
                'nota' => 'Requiere tipos de gasto y centros de costo ya creados, y parametrización vigente para la fecha. Cada fila se causa como un gasto nuevo, con sus retenciones y asiento.',
                'permiso' => ['importaciones', 'importar_gastos'],
                'columnas' => [
                    self::c('fecha', 'Fecha del gasto', true, 'fecha'),
                    self::c('numero_documento', 'Número de documento', true, 'texto'),
                    self::c('tipo_gasto', 'Tipo de gasto (código o nombre)', true, 'texto'),
                    self::c('tercero', 'Documento del tercero (si aplica)', false, 'texto'),
                    self::c('concepto', 'Concepto', true, 'texto'),
                    self::c('valor_bruto', 'Valor bruto', true, 'monto'),
                    self::c('centro_costo', 'Centro de costo (código)', true, 'texto'),
                    self::c('municipio', 'Código DANE del municipio (si aplica ReteICA)', false, 'texto'),
                ],
            ],
            TipoImportacion::PlanCuentas => [
                'hoja' => 'Plan de cuentas (formato anterior)',
                'orden' => 6,
                'nota' => 'Formato de migración anterior, se mantiene para cargas existentes.',
                'permiso' => ['importaciones', 'importar_plan_cuentas'],
                'columnas' => [
                    self::c('codigo', 'codigo', true, 'texto'),
                    self::c('nombre', 'nombre', true, 'texto'),
                    self::c('naturaleza', 'naturaleza', true, 'texto'),
                    self::c('tipo', 'tipo', true, 'texto'),
                    self::c('nivel', 'nivel', false, 'entero'),
                ],
            ],
            TipoImportacion::Terceros => [
                'hoja' => 'Terceros (formato anterior)',
                'orden' => 7,
                'nota' => 'Formato de migración anterior, se mantiene para cargas existentes.',
                'permiso' => ['importaciones', 'importar_terceros'],
                'columnas' => [
                    self::c('tipo', 'tipo', true, 'texto'),
                    self::c('tipo_persona', 'tipo_persona', true, 'texto'),
                    self::c('tipo_documento', 'tipo_documento', false, 'texto'),
                    self::c('numero_documento', 'numero_documento', true, 'texto'),
                    self::c('nombres', 'nombres', false, 'texto'),
                    self::c('apellidos', 'apellidos', false, 'texto'),
                    self::c('razon_social', 'razon_social', false, 'texto'),
                    self::c('digito_verificacion', 'digito_verificacion', false, 'texto'),
                    self::c('representante_legal', 'representante_legal', false, 'texto'),
                    self::c('direccion', 'direccion', false, 'texto'),
                    self::c('ciudad', 'ciudad', false, 'texto'),
                    self::c('celular', 'celular', false, 'texto'),
                    self::c('correo', 'correo', false, 'texto'),
                ],
            ],
            TipoImportacion::ActividadesEconomicas => [
                'hoja' => 'Actividades_Economicas',
                'orden' => 9,
                'nota' => 'Si el código CIIU ya existe para la empresa, se actualiza el nombre; si no, se crea una actividad nueva.',
                'permiso' => ['importaciones', 'importar_actividades_economicas'],
                'columnas' => [
                    self::c('codigo', 'Código CIIU', true, 'texto'),
                    self::c('nombre', 'Actividad económica', true, 'texto'),
                ],
            ],
            TipoImportacion::ValoresUvt => [
                'hoja' => 'Valores_UVT',
                'orden' => 10,
                'nota' => 'Abre una nueva vigencia desde la fecha indicada; si había una vigencia abierta, se cierra el día anterior.',
                'permiso' => ['importaciones', 'importar_valores_uvt'],
                'columnas' => [
                    self::c('fecha_desde', 'Fecha desde', true, 'fecha'),
                    self::c('valor', 'Valor UVT', true, 'monto'),
                ],
            ],
            TipoImportacion::TarifasReteica => [
                'hoja' => 'Tarifas_ReteICA',
                'orden' => 11,
                'nota' => 'Requiere que el municipio (código DANE) y la actividad económica (código CIIU) ya existan. Abre una nueva vigencia desde la fecha indicada; si había una vigencia abierta para ese municipio y esa actividad, se cierra el día anterior.',
                'permiso' => ['importaciones', 'importar_tarifas_reteica'],
                'columnas' => [
                    self::c('municipio', 'Código DANE del municipio', true, 'texto'),
                    self::c('actividad_economica', 'Código CIIU de la actividad', true, 'texto'),
                    self::c('fecha_desde', 'Fecha desde', true, 'fecha'),
                    self::c('tarifa', 'Tarifa (por mil)', true, 'decimal'),
                ],
            ],
        };
    }

    /**
     * Huella de la fila de ejemplo que trae la plantilla: si una fila la cumple completa, el usuario no la borró.
     * Debe coincidir con el ejemplo que se ve en pantalla en `client/src/imports/constants/import-example-by-type.ts`
     * — si cambia uno, cambia el otro, o la fila de ejemplo deja de detectarse.
     *
     * @return array<string, string>
     */
    public static function huellaDeEjemplo(TipoImportacion $tipo): array
    {
        return match ($tipo) {
            TipoImportacion::Clientes => ['cedula' => '53125435', 'nombre' => 'Adriana Maria Daza Mesa'],
            TipoImportacion::Creditos => ['codigo' => 'CR-2023-001', 'cedula' => '53125435'],
            TipoImportacion::PlanCuotas => ['codigo' => 'CR-2023-001', 'numero' => '1'],
            TipoImportacion::Pagos => ['codigo' => 'CR-2023-001', 'valor' => '280500'],
            TipoImportacion::SaldosIniciales => ['cuenta' => '133005', 'debito' => '45000000'],
            TipoImportacion::PlanCuentas, TipoImportacion::Terceros, TipoImportacion::Gastos,
            TipoImportacion::ActividadesEconomicas, TipoImportacion::ValoresUvt, TipoImportacion::TarifasReteica => [],
        };
    }

    /** @param array<string, string> $valores */
    public static function esFilaDeEjemplo(TipoImportacion $tipo, array $valores): bool
    {
        $huella = self::huellaDeEjemplo($tipo);

        if ($huella === []) {
            return false;
        }

        foreach ($huella as $clave => $valor) {
            if (($valores[$clave] ?? null) !== $valor) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function encabezadosObligatorios(TipoImportacion $tipo): array
    {
        return array_values(array_map(
            fn (array $columna) => $columna['encabezado'],
            array_filter(self::para($tipo)['columnas'], fn (array $columna) => $columna['obligatoria']),
        ));
    }

    /** @return array{clave: string, encabezado: string, obligatoria: bool, tipo: string} */
    private static function c(string $clave, string $encabezado, bool $obligatoria, string $tipo): array
    {
        return ['clave' => $clave, 'encabezado' => $encabezado, 'obligatoria' => $obligatoria, 'tipo' => $tipo];
    }
}
