<?php

namespace Database\Seeders;

use App\Usuarios\Models\Permiso;
use Illuminate\Database\Seeder;

/**
 * Catálogo global de permisos vigentes (módulo/acción): es el único compartido entre empresas. La asignación a roles
 * concretos ocurre por empresa, al crearla (ver EmpresaService y EmpresaConstants::PERMISOS_POR_ROL), no aquí.
 *
 * Sembrar reconcilia la tabla con el catálogo: crea los vigentes que falten, restaura los que estuvieran retirados y
 * retira con borrado lógico los que ya no están en él (las asignaciones se conservan, como en
 * `retirar_permisos_genericos_sin_uso`). Tras sembrar, el panel de permisos muestra exactamente este catálogo.
 */
class PermisoSeeder extends Seeder
{
    public const CATALOGO = [
        'usuarios' => ['listar', 'crear', 'editar', 'eliminar'],
        'clientes' => ['listar', 'ver', 'crear', 'editar', 'eliminar', 'exportar_listado', 'reasignar_asesor'],
        'empleadores' => ['listar', 'ver', 'crear', 'editar', 'eliminar'],
        'creditos' => [
            'listar', 'crear', 'editar', 'aprobar', 'desembolsar', 'cancelar', 'eliminar', 'exportar_listado', 'exportar_estado_cuenta',
            'imprimir_estado_cuenta',
        ],
        'pagos' => ['listar', 'crear', 'imprimir_recibo', 'exportar_listado', 'reversar'],
        'recibos' => ['listar'],
        'cartera' => ['listar', 'exportar_listado', 'exportar_reporte_empleador', 'imprimir_reporte_empleador', 'enviar_recordatorio'],
        'calendario' => ['listar'],
        'terceros' => ['listar', 'crear', 'editar'],
        'contabilidad' => [
            'listar_comprobantes', 'listar_cuentas_por_pagar', 'listar_cuentas_por_cobrar',
            'crear_manual', 'revisar', 'contabilizar', 'anular', 'imprimir_comprobante',
        ],
        'configuracion_contable' => [
            'listar_plan_cuentas', 'crear_plan_cuentas', 'editar_plan_cuentas',
            'listar_tipos_comprobante', 'crear_tipos_comprobante', 'editar_tipos_comprobante', 'eliminar_tipos_comprobante',
            'listar_tipos_documento_negocio', 'editar_tipos_documento_negocio',
            'listar_centros_costo', 'crear_centros_costo', 'editar_centros_costo', 'eliminar_centros_costo',
            'configurar', 'listar_periodos', 'cerrar_periodo', 'reabrir_periodo',
        ],
        'informes_contables' => [
            'listar_libro_diario', 'listar_libro_mayor', 'listar_caja_bancos', 'listar_balance_prueba',
            'listar_estados_financieros', 'listar_auxiliares', 'listar_conciliacion_apertura', 'listar_controles_cierre',
            'ver_dashboard_contable',
            'exportar_libro_diario', 'exportar_libro_mayor', 'exportar_caja_bancos', 'exportar_balance_prueba',
            'exportar_estados_financieros', 'exportar_auxiliares', 'imprimir',
        ],
        'tributario' => [
            'listar_tarifas_reteica', 'crear_tarifas_reteica',
            'listar_actividades_economicas', 'crear_actividades_economicas', 'editar_actividades_economicas',
            'listar_valores_uvt', 'crear_valores_uvt', 'editar_valores_uvt', 'eliminar_valores_uvt',
        ],
        'gastos' => [
            'listar_gastos', 'crear', 'anular', 'exportar_gastos', 'listar_gastos_centro_costo', 'exportar_gastos_centro_costo',
            'listar_tipos_gasto', 'crear_tipos_gasto', 'editar_tipos_gasto', 'eliminar_tipos_gasto',
            'listar_parametrizaciones', 'crear_parametrizaciones',
        ],
        'egresos' => ['listar', 'crear', 'reversar'],
        'anticipos' => ['listar', 'crear'],
        'conciliacion' => ['listar', 'configurar', 'cargar_extracto', 'conciliar', 'cerrar', 'reabrir'],
        'auditoria' => ['listar', 'exportar_bitacora'],
        'facturacion' => [
            'listar_facturas', 'listar_notas_credito', 'listar_notas_debito', 'crear', 'emitir', 'anular', 'emitir_nota_credito',
            'aprobar_nota_credito', 'emitir_nota_debito', 'imprimir', 'enviar', 'configurar', 'modificar_valores_sugeridos',
            'registrar_cobro', 'reversar_cobro',
        ],
        'importaciones' => [
            'importar_clientes', 'importar_creditos', 'importar_plan_cuotas', 'importar_pagos', 'importar_saldos_iniciales',
            'importar_plan_cuentas', 'importar_terceros', 'importar_gastos', 'importar_actividades_economicas',
            'importar_valores_uvt', 'importar_tarifas_reteica',
        ],
        'metas' => ['listar', 'configurar', 'recalcular', 'eliminar'],
        'obligaciones_tributarias' => ['listar', 'configurar', 'crear', 'editar', 'cambiar_estado', 'eliminar', 'eliminar_tipos'],
    ];

    public function run(): void
    {
        $vigentes = [];

        foreach (self::CATALOGO as $modulo => $acciones) {
            foreach ($acciones as $accion) {
                $permiso = Permiso::withTrashed()->firstOrCreate(['modulo' => $modulo, 'accion' => $accion]);

                if ($permiso->trashed()) {
                    $permiso->restore();
                }

                $vigentes[] = $permiso->id;
            }
        }

        Permiso::query()->whereKeyNot($vigentes)->delete();
    }
}
