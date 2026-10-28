<?php

use App\Anticipos\Controllers\AnticipoController;
use App\Auditoria\Controllers\BitacoraAuditoriaController;
use App\Auditoria\Controllers\RecuperacionAuditoriaController;
use App\Auth\Controllers\AuthController;
use App\Calendario\Controllers\CalendarioController;
use App\Cartera\Controllers\CarteraController;
use App\Clientes\Controllers\ClienteController;
use App\Conciliacion\Controllers\CierreExtractoController;
use App\Conciliacion\Controllers\CuentaBancariaController;
use App\Conciliacion\Controllers\ExtractoBancarioController;
use App\Conciliacion\Controllers\MatchConciliacionController;
use App\Contabilidad\Constants\ContabilidadConstants;
use App\Contabilidad\Controllers\AuxiliarContableController;
use App\Contabilidad\Controllers\CarteraTerceroController;
use App\Contabilidad\Controllers\CentroCostoController;
use App\Contabilidad\Controllers\ComprobanteController;
use App\Contabilidad\Controllers\DashboardContableController;
use App\Contabilidad\Controllers\DocumentoTerceroController;
use App\Contabilidad\Controllers\PeriodoContableController;
use App\Contabilidad\Controllers\PlanCuentaController;
use App\Contabilidad\Controllers\ReglaContabilizacionController;
use App\Contabilidad\Controllers\ReporteContableController;
use App\Contabilidad\Controllers\SolicitudReaperturaPeriodoController;
use App\Contabilidad\Controllers\TipoComprobanteController;
use App\Contabilidad\Controllers\TipoDocumentoNegocioController;
use App\Correo\Controllers\ConfiguracionCorreoController;
use App\Correo\Controllers\PlantillaCorreoController;
use App\Correo\Controllers\RecordatorioCorreoController;
use App\Creditos\Controllers\CreditoController;
use App\Dashboard\Controllers\DashboardController;
use App\Dashboard\Controllers\PanelSuperadminController;
use App\Egresos\Controllers\EgresoController;
use App\Empleadores\Controllers\EmpleadorController;
use App\Empresas\Controllers\EmpresaAdminController;
use App\Empresas\Controllers\EmpresaController;
use App\Exportacion\Controllers\ExportacionController;
use App\Facturacion\Constants\FacturacionConstants;
use App\Facturacion\Controllers\CobroFacturacionController;
use App\Facturacion\Controllers\ConsecutivoFacturacionController;
use App\Facturacion\Controllers\DocumentoFacturacionController;
use App\Facturacion\Controllers\ParametrizacionConceptoFacturacionController;
use App\Gastos\Constants\GastosConstants;
use App\Gastos\Controllers\ActividadEconomicaController;
use App\Gastos\Controllers\GastoController;
use App\Gastos\Controllers\ParametrizacionTipoGastoController;
use App\Gastos\Controllers\ReporteGastosCentroCostoController;
use App\Gastos\Controllers\TarifaReteIcaMunicipioController;
use App\Gastos\Controllers\TipoGastoController;
use App\Gastos\Controllers\ValorUvtController;
use App\Geografia\Controllers\EstadoController;
use App\Geografia\Controllers\MunicipioController;
use App\Geografia\Controllers\PaisController;
use App\Importacion\Constants\ImportacionConstants;
use App\Importacion\Controllers\ImportacionController;
use App\Metas\Controllers\AlertaMetaController;
use App\Metas\Controllers\CierreMetaController;
use App\Metas\Controllers\ConfiguracionMetaController;
use App\Metas\Controllers\MetaController;
use App\Monitoreo\Controllers\MonitoreoController;
use App\Monitoreo\Controllers\TareaProgramadaController;
use App\Notificaciones\Controllers\NotificacionController;
use App\ObligacionesTributarias\Controllers\AlertaObligacionTributariaController;
use App\ObligacionesTributarias\Controllers\ObligacionTributariaController;
use App\ObligacionesTributarias\Controllers\TipoObligacionTributariaController;
use App\Pagos\Controllers\PagoController;
use App\Proveedores\Controllers\ProveedorController;
use App\Recibos\Controllers\ReciboCajaController;
use App\Respaldos\Controllers\ConfiguracionRespaldoExternoController;
use App\Respaldos\Controllers\DescargaBaseController;
use App\Respaldos\Controllers\ResumenRespaldosController;
use App\Seguridad\Controllers\AccesoController;
use App\Seguridad\Controllers\SesionActivaController;
use App\Terceros\Controllers\TerceroController;
use App\Usuarios\Controllers\PermisoController;
use App\Usuarios\Controllers\RolController;
use App\Usuarios\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'usuarioActual']);
    Route::patch('/me/clave', [AuthController::class, 'cambiarClave']);

    Route::get('/empresas', [EmpresaAdminController::class, 'index'])->middleware('superadmin');
    Route::post('/empresas', [EmpresaAdminController::class, 'store'])->middleware('superadmin');
    Route::get('/empresas/{empresa}', [EmpresaAdminController::class, 'show'])->middleware('superadmin');
    Route::patch('/empresas/{empresa}', [EmpresaAdminController::class, 'update'])->middleware('superadmin');

    Route::get('/empresa', [EmpresaController::class, 'show']);
    Route::patch('/empresa', [EmpresaController::class, 'update'])->middleware('admin');
    Route::post('/empresa/logo', [EmpresaController::class, 'uploadLogo'])->middleware('admin');

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/panel-superadmin', [PanelSuperadminController::class, 'index'])->middleware('superadmin');

    Route::get('/geografia/paises', [PaisController::class, 'index']);
    Route::get('/geografia/estados', [EstadoController::class, 'index']);
    Route::get('/geografia/municipios', [MunicipioController::class, 'index']);
    Route::get('/geografia/municipios/listado', [MunicipioController::class, 'listado']);
    Route::post('/geografia/municipios', [MunicipioController::class, 'store'])->middleware('superadmin');
    Route::patch('/geografia/municipios/{municipio}', [MunicipioController::class, 'update'])->middleware('superadmin');

    Route::get('/auditoria/bitacora', [BitacoraAuditoriaController::class, 'index'])->middleware('permiso_o_superadmin:auditoria,listar');
    Route::get('/auditoria/usuarios', [BitacoraAuditoriaController::class, 'usuarios'])->middleware('superadmin');
    Route::post('/auditoria/bitacora/exportar', [BitacoraAuditoriaController::class, 'exportar'])->middleware('exportacion:auditoria.bitacora');
    Route::get('/auditoria/respaldo', [RecuperacionAuditoriaController::class, 'index'])->middleware('superadmin');
    Route::post('/auditoria/respaldo/recuperar', [RecuperacionAuditoriaController::class, 'recuperar'])->middleware('superadmin');

    Route::get('/seguridad/accesos', [AccesoController::class, 'index'])->middleware('superadmin');
    Route::get('/seguridad/accesos/alerta', [AccesoController::class, 'alerta'])->middleware('superadmin');
    Route::get('/seguridad/sesiones', [SesionActivaController::class, 'index'])->middleware('superadmin');
    Route::delete('/seguridad/sesiones/empresa/{empresa}', [SesionActivaController::class, 'destroyEmpresa'])->middleware('superadmin');
    Route::delete('/seguridad/sesiones/{sesion}', [SesionActivaController::class, 'destroy'])->middleware('superadmin');

    Route::get('/respaldos/base', [DescargaBaseController::class, 'base'])->middleware('superadmin');
    Route::get('/respaldos/empresa', [DescargaBaseController::class, 'empresa'])->middleware('admin');
    Route::get('/respaldos/resumen', [ResumenRespaldosController::class, 'index'])->middleware('superadmin');
    Route::post('/respaldos/ejecutar', [ResumenRespaldosController::class, 'ejecutar'])->middleware('superadmin');

    Route::get('/respaldos/externo', [ConfiguracionRespaldoExternoController::class, 'show'])->middleware('superadmin');
    Route::patch('/respaldos/externo', [ConfiguracionRespaldoExternoController::class, 'update'])->middleware('superadmin');
    Route::post('/respaldos/externo/prueba', [ConfiguracionRespaldoExternoController::class, 'prueba'])->middleware('superadmin');

    Route::get('/monitoreo/estado', [MonitoreoController::class, 'estado'])->middleware('superadmin');
    Route::get('/monitoreo/respaldos', [MonitoreoController::class, 'respaldos'])->middleware('superadmin');
    Route::get('/monitoreo/trabajos-fallidos', [MonitoreoController::class, 'trabajosFallidos'])->middleware('superadmin');
    Route::post('/monitoreo/trabajos-fallidos/{uuid}/reintentar', [MonitoreoController::class, 'reintentar'])->middleware('superadmin')->whereUuid('uuid');

    Route::get('/tareas-programadas', [TareaProgramadaController::class, 'index'])->middleware('superadmin');
    Route::patch('/tareas-programadas/{clave}', [TareaProgramadaController::class, 'update'])->middleware('superadmin');
    Route::get('/tareas-programadas/{clave}/historial', [TareaProgramadaController::class, 'historial'])->middleware('superadmin');

    Route::get('/exportaciones', [ExportacionController::class, 'index']);
    Route::get('/exportaciones/{exportacion}/descargar', [ExportacionController::class, 'descargar'])->whereNumber('exportacion');

    Route::get('/notificaciones', [NotificacionController::class, 'index']);
    Route::get('/notificaciones/pagina', [NotificacionController::class, 'todas']);
    Route::post('/notificaciones/leer-todas', [NotificacionController::class, 'leerTodas']);
    Route::post('/notificaciones/{notificacion}/leer', [NotificacionController::class, 'leer'])->whereNumber('notificacion');

    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:usuarios,listar');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('permiso:usuarios,crear');
    Route::get('/usuarios/{usuario}', [UsuarioController::class, 'show'])->middleware('permiso:usuarios,editar');
    Route::patch('/usuarios/{usuario}', [UsuarioController::class, 'update'])->middleware('permiso:usuarios,editar');
    Route::patch('/usuarios/{usuario}/clave', [UsuarioController::class, 'cambiarClave'])->middleware('admin');
    Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'destroy'])->middleware('permiso:usuarios,eliminar');

    Route::get('/roles', [RolController::class, 'index'])->middleware('permiso:usuarios,listar');
    Route::get('/roles/{rol}', [RolController::class, 'show'])->middleware('admin');
    Route::post('/roles', [RolController::class, 'store'])->middleware('admin');
    Route::patch('/roles/{rol}', [RolController::class, 'update'])->middleware('admin');
    Route::delete('/roles/{rol}', [RolController::class, 'destroy'])->middleware('admin');
    Route::get('/permisos', [PermisoController::class, 'index'])->middleware('permiso:usuarios,listar');

    Route::get('/clientes', [ClienteController::class, 'index'])->middleware('permiso:clientes,listar');
    Route::post('/clientes/exportar', [ClienteController::class, 'exportar'])->middleware('exportacion:clientes.listado');
    Route::post('/clientes', [ClienteController::class, 'store'])->middleware('permiso:clientes,crear');
    // Catálogo de consulta: lo usan el alta de clientes, la reasignación de asesor y la distribución de metas.
    Route::get('/clientes/asesores-asignables', [ClienteController::class, 'asesoresAsignables'])->middleware('algun_permiso:clientes.crear,clientes.reasignar_asesor,metas.configurar');
    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->middleware('permiso:clientes,ver');
    Route::patch('/clientes/{cliente}', [ClienteController::class, 'update'])->middleware('permiso:clientes,editar');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->middleware('permiso:clientes,eliminar');
    Route::patch('/clientes/{cliente}/asesor', [ClienteController::class, 'reasignarAsesor'])->middleware('permiso:clientes,reasignar_asesor');
    Route::get('/clientes/{cliente}/asesor-historial', [ClienteController::class, 'historialAsesores'])->middleware('permiso:clientes,reasignar_asesor');
    Route::get('/clientes/{cliente}/resumen', [ClienteController::class, 'resumen'])->middleware('permiso:clientes,ver');
    Route::get('/clientes/{cliente}/documentos', [ClienteController::class, 'documentos'])->middleware('permiso:clientes,ver');
    Route::post('/clientes/{cliente}/documentos', [ClienteController::class, 'subirDocumento'])->middleware('permiso:clientes,editar');

    Route::get('/empleadores', [EmpleadorController::class, 'index'])->middleware('permiso:empleadores,listar');
    Route::post('/empleadores', [EmpleadorController::class, 'store'])->middleware('permiso:empleadores,crear');
    Route::get('/empleadores/{empleador}', [EmpleadorController::class, 'show'])->middleware('permiso:empleadores,ver');
    Route::patch('/empleadores/{empleador}', [EmpleadorController::class, 'update'])->middleware('permiso:empleadores,editar');
    Route::delete('/empleadores/{empleador}', [EmpleadorController::class, 'destroy'])->middleware('permiso:empleadores,eliminar');

    Route::get('/creditos', [CreditoController::class, 'index'])->middleware('permiso:creditos,listar');
    Route::post('/creditos', [CreditoController::class, 'store'])->middleware('permiso:creditos,crear');
    Route::post('/creditos/exportar', [CreditoController::class, 'exportar'])->middleware('exportacion:creditos.listado');
    Route::get('/creditos/{credito}', [CreditoController::class, 'show'])->middleware('permiso:creditos,listar');
    Route::patch('/creditos/{credito}', [CreditoController::class, 'update'])->middleware('permiso:creditos,editar');
    Route::patch('/creditos/{credito}/gastos-administracion', [CreditoController::class, 'actualizarGastosAdministracion'])->middleware('permiso:creditos,editar');
    Route::get('/creditos/{credito}/estado-cuenta', [CreditoController::class, 'estadoCuenta'])->middleware('permiso:creditos,listar');
    Route::post('/creditos/{credito}/estado-cuenta/exportar', [CreditoController::class, 'estadoCuentaExportar'])->middleware('exportacion:creditos.estado_cuenta');
    Route::get('/creditos/{credito}/estado-cuenta/pdf', [CreditoController::class, 'estadoCuentaPdf'])->middleware('permiso:creditos,imprimir_estado_cuenta');
    Route::post('/creditos/{credito}/aprobar', [CreditoController::class, 'aprobar'])->middleware('permiso:creditos,aprobar');
    Route::post('/creditos/{credito}/desembolsar', [CreditoController::class, 'desembolsar'])->middleware('permiso:creditos,desembolsar');
    Route::post('/creditos/{credito}/cancelar', [CreditoController::class, 'cancelar'])->middleware('permiso:creditos,cancelar');
    Route::delete('/creditos/{credito}', [CreditoController::class, 'destroy'])->middleware('permiso:creditos,eliminar');

    Route::get('/pagos', [PagoController::class, 'listar'])->middleware('permiso:pagos,listar');
    Route::post('/pagos/exportar', [PagoController::class, 'exportar'])->middleware('exportacion:pagos.listado');
    Route::get('/creditos/{credito}/pagos', [PagoController::class, 'index'])->middleware('permiso:pagos,listar');
    Route::get('/recibos', [ReciboCajaController::class, 'listar'])->middleware('permiso:recibos,listar');
    Route::get('/pagos/{pago}/recibo/pdf', [ReciboCajaController::class, 'pdf'])->middleware('permiso:pagos,imprimir_recibo')->whereNumber('pago');
    Route::post('/creditos/{credito}/pagos', [PagoController::class, 'store'])->middleware('permiso:pagos,crear');
    Route::post('/pagos/{pago}/reversar', [PagoController::class, 'reversar'])->middleware('permiso:pagos,reversar')->whereNumber('pago');
    Route::get('/creditos/{credito}/liquidacion', [PagoController::class, 'liquidacion'])->middleware('permiso:pagos,crear');
    Route::post('/creditos/{credito}/liquidacion', [PagoController::class, 'liquidar'])->middleware('permiso:pagos,crear');

    Route::get('/cartera', [CarteraController::class, 'index'])->middleware('permiso:cartera,listar');
    Route::post('/cartera/exportar', [CarteraController::class, 'exportar'])->middleware('exportacion:cartera.listado');
    Route::get('/cartera/reporte-empleador', [CarteraController::class, 'reporteEmpleador'])->middleware('permiso:cartera,listar');
    Route::get('/cartera/reporte-empleador/pdf', [CarteraController::class, 'reporteEmpleadorPdf'])->middleware('permiso:cartera,imprimir_reporte_empleador');
    Route::post('/cartera/reporte-empleador/exportar', [CarteraController::class, 'exportarReporteEmpleador'])->middleware('exportacion:cartera.reporte_empleador');

    // Correo: la configuración (credenciales) y las plantillas son solo de admin; los envíos los dispara quien tiene el permiso del módulo dueño.
    Route::prefix('correo')->middleware('admin')->group(function () {
        Route::get('/configuracion/{tipo}', [ConfiguracionCorreoController::class, 'show']);
        Route::put('/configuracion/{tipo}', [ConfiguracionCorreoController::class, 'update']);
        Route::post('/configuracion/{tipo}/prueba', [ConfiguracionCorreoController::class, 'prueba']);
        Route::get('/plantillas', [PlantillaCorreoController::class, 'index']);
        Route::post('/plantillas', [PlantillaCorreoController::class, 'store']);
        Route::put('/plantillas/{plantilla}', [PlantillaCorreoController::class, 'update']);
        Route::delete('/plantillas/{plantilla}', [PlantillaCorreoController::class, 'destroy']);
        Route::post('/plantillas/{plantilla}/predeterminada', [PlantillaCorreoController::class, 'marcarPredeterminada']);
        Route::post('/plantillas/vista-previa', [PlantillaCorreoController::class, 'vistaPrevia']);
    });
    Route::get('/clientes/{cliente}/recordatorios-correo', [RecordatorioCorreoController::class, 'historial'])->middleware('permiso:clientes,ver');
    Route::post('/clientes/{cliente}/recordatorios-correo/vista-previa', [RecordatorioCorreoController::class, 'vistaPrevia'])->middleware('permiso:cartera,enviar_recordatorio');
    Route::post('/clientes/{cliente}/recordatorios-correo', [RecordatorioCorreoController::class, 'enviar'])->middleware('permiso:cartera,enviar_recordatorio');
    Route::post('/clientes/{cliente}/recordatorios-correo/{envio}/reintentar', [RecordatorioCorreoController::class, 'reintentar'])->middleware('permiso:cartera,enviar_recordatorio');

    // Metas: la lectura admite al asesor solo con la meta consolidada activa (`acceso_metas`); el resto exige su permiso.
    Route::prefix('metas')->group(function () {
        Route::middleware('acceso_metas')->group(function () {
            Route::get('/', [MetaController::class, 'index']);
            Route::get('/alertas', [AlertaMetaController::class, 'index']);
            Route::post('/alertas/{alerta}/vista', [AlertaMetaController::class, 'marcarVista'])->whereNumber('alerta');
        });

        Route::get('/configuracion', [ConfiguracionMetaController::class, 'show'])->middleware('permiso:metas,listar');
        Route::get('/cierres', [CierreMetaController::class, 'index'])->middleware('permiso:metas,listar');

        Route::middleware('permiso:metas,configurar')->group(function () {
            Route::post('/', [MetaController::class, 'store']);
            Route::put('/configuracion', [ConfiguracionMetaController::class, 'update']);
            Route::put('/{meta}', [MetaController::class, 'update'])->whereNumber('meta');
            Route::put('/{meta}/asesores', [MetaController::class, 'distribuir'])->whereNumber('meta');
        });

        Route::delete('/{meta}', [MetaController::class, 'destroy'])->whereNumber('meta')->middleware('permiso:metas,eliminar');
        Route::post('/cierres/recalcular', [CierreMetaController::class, 'recalcular'])->middleware('permiso:metas,recalcular');
    });

    Route::get('/calendario', [CalendarioController::class, 'index'])->middleware('permiso:calendario,listar');

    // Calendario tributario: herramienta de control (no liquida ni presenta declaraciones). Solo admin y contable.
    Route::prefix('obligaciones-tributarias')->group(function () {
        Route::get('/', [ObligacionTributariaController::class, 'index'])->middleware('permiso:obligaciones_tributarias,listar');
        Route::get('/alertas', [AlertaObligacionTributariaController::class, 'index'])->middleware('permiso:obligaciones_tributarias,listar');
        Route::post('/alertas/{alerta}/vista', [AlertaObligacionTributariaController::class, 'marcarVista'])
            ->whereNumber('alerta')->middleware('permiso:obligaciones_tributarias,listar');

        Route::get('/tipos', [TipoObligacionTributariaController::class, 'index'])->middleware('permiso:obligaciones_tributarias,listar');
        Route::post('/tipos', [TipoObligacionTributariaController::class, 'store'])->middleware('permiso:obligaciones_tributarias,configurar');
        Route::put('/tipos/{tipo}', [TipoObligacionTributariaController::class, 'update'])->whereNumber('tipo')->middleware('permiso:obligaciones_tributarias,configurar');
        Route::delete('/tipos/{tipo}', [TipoObligacionTributariaController::class, 'destroy'])->whereNumber('tipo')->middleware('permiso:obligaciones_tributarias,eliminar_tipos');

        // Catálogo de consulta: lo usa el formulario de crear/editar obligación para elegir responsable.
        Route::get('/responsables-asignables', [ObligacionTributariaController::class, 'responsablesAsignables'])
            ->middleware('algun_permiso:obligaciones_tributarias.crear,obligaciones_tributarias.editar');

        Route::post('/', [ObligacionTributariaController::class, 'store'])->middleware('permiso:obligaciones_tributarias,crear');
        Route::put('/{obligacion}', [ObligacionTributariaController::class, 'update'])->whereNumber('obligacion')->middleware('permiso:obligaciones_tributarias,editar');
        Route::put('/{obligacion}/estado', [ObligacionTributariaController::class, 'cambiarEstado'])
            ->whereNumber('obligacion')->middleware('permiso:obligaciones_tributarias,cambiar_estado');
        Route::delete('/{obligacion}', [ObligacionTributariaController::class, 'destroy'])->whereNumber('obligacion')->middleware('permiso:obligaciones_tributarias,eliminar');
    });

    Route::get('/contabilidad/plan-cuentas', [PlanCuentaController::class, 'index'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_PLAN_CUENTAS));
    Route::post('/contabilidad/plan-cuentas', [PlanCuentaController::class, 'store'])->middleware('permiso:configuracion_contable,crear_plan_cuentas');
    Route::patch('/contabilidad/plan-cuentas/{cuenta}', [PlanCuentaController::class, 'update'])->middleware('permiso:configuracion_contable,editar_plan_cuentas');

    Route::get('/contabilidad/periodos', [PeriodoContableController::class, 'index'])->middleware('permiso:configuracion_contable,listar_periodos');
    Route::post('/contabilidad/periodos/{periodo}/cerrar', [PeriodoContableController::class, 'cerrar'])->middleware('permiso:configuracion_contable,cerrar_periodo');
    Route::post('/contabilidad/periodos/{periodo}/reabrir', [PeriodoContableController::class, 'reabrir'])->middleware('permiso:configuracion_contable,reabrir_periodo');

    Route::get('/contabilidad/solicitudes-reapertura', [SolicitudReaperturaPeriodoController::class, 'index'])->middleware('permiso:configuracion_contable,listar_periodos');
    Route::post('/contabilidad/periodos/{periodo}/solicitar-reapertura', [SolicitudReaperturaPeriodoController::class, 'store'])->middleware('permiso:configuracion_contable,cerrar_periodo');
    Route::post('/contabilidad/solicitudes-reapertura/{solicitud}/autorizar', [SolicitudReaperturaPeriodoController::class, 'autorizar'])->middleware('permiso:configuracion_contable,reabrir_periodo');
    Route::post('/contabilidad/solicitudes-reapertura/{solicitud}/rechazar', [SolicitudReaperturaPeriodoController::class, 'rechazar'])->middleware('permiso:configuracion_contable,reabrir_periodo');

    Route::get('/contabilidad/tipos-comprobante', [TipoComprobanteController::class, 'index'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_TIPOS_COMPROBANTE));
    Route::post('/contabilidad/tipos-comprobante', [TipoComprobanteController::class, 'store'])->middleware('permiso:configuracion_contable,crear_tipos_comprobante');
    Route::get('/contabilidad/tipos-comprobante/{tipoComprobante}', [TipoComprobanteController::class, 'show'])->middleware('algun_permiso:configuracion_contable.listar_tipos_comprobante,configuracion_contable.editar_tipos_comprobante');
    Route::patch('/contabilidad/tipos-comprobante/{tipoComprobante}', [TipoComprobanteController::class, 'update'])->middleware('permiso:configuracion_contable,editar_tipos_comprobante');
    Route::delete('/contabilidad/tipos-comprobante/{tipoComprobante}', [TipoComprobanteController::class, 'destroy'])->middleware('permiso:configuracion_contable,eliminar_tipos_comprobante');

    Route::get('/contabilidad/tipos-documento-negocio', [TipoDocumentoNegocioController::class, 'index'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_TIPOS_DOCUMENTO_NEGOCIO));
    Route::patch('/contabilidad/tipos-documento-negocio/{tipoDocumentoNegocio}', [TipoDocumentoNegocioController::class, 'update'])->middleware('permiso:configuracion_contable,editar_tipos_documento_negocio');

    Route::get('/contabilidad/centros-costo', [CentroCostoController::class, 'index'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_CENTROS_COSTO));
    Route::post('/contabilidad/centros-costo', [CentroCostoController::class, 'store'])->middleware('permiso:configuracion_contable,crear_centros_costo');
    Route::get('/contabilidad/centros-costo/{centro}', [CentroCostoController::class, 'show'])->middleware('algun_permiso:configuracion_contable.listar_centros_costo,configuracion_contable.editar_centros_costo');
    Route::get('/contabilidad/centros-costo/{centro}/movimientos', [CentroCostoController::class, 'movimientos'])->middleware('algun_permiso:configuracion_contable.listar_centros_costo,configuracion_contable.editar_centros_costo');
    Route::patch('/contabilidad/centros-costo/{centro}', [CentroCostoController::class, 'update'])->middleware('permiso:configuracion_contable,editar_centros_costo');
    Route::delete('/contabilidad/centros-costo/{centro}', [CentroCostoController::class, 'destroy'])->middleware('permiso:configuracion_contable,eliminar_centros_costo');

    Route::get('/contabilidad/reglas', [ReglaContabilizacionController::class, 'index'])->middleware('permiso:configuracion_contable,configurar');
    Route::post('/contabilidad/reglas', [ReglaContabilizacionController::class, 'store'])->middleware('permiso:configuracion_contable,configurar');

    Route::get('/contabilidad/comprobantes', [ComprobanteController::class, 'index'])->middleware('permiso:contabilidad,listar_comprobantes');
    Route::post('/contabilidad/comprobantes', [ComprobanteController::class, 'store'])->middleware('permiso:contabilidad,crear_manual');
    Route::post('/contabilidad/comprobantes/causar-concepto', [ComprobanteController::class, 'causarConcepto'])->middleware('permiso:contabilidad,crear_manual');
    Route::get('/contabilidad/comprobantes/documentos-cruce', [ComprobanteController::class, 'documentosCruce'])->middleware('permiso:contabilidad,crear_manual');
    Route::get('/contabilidad/comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->middleware('permiso:contabilidad,listar_comprobantes');
    Route::get('/contabilidad/comprobantes/{comprobante}/pdf', [ComprobanteController::class, 'pdf'])->middleware('permiso:contabilidad,imprimir_comprobante')->whereNumber('comprobante');
    Route::post('/contabilidad/comprobantes/{comprobante}/revisar', [ComprobanteController::class, 'revisar'])->middleware('permiso:contabilidad,revisar')->whereNumber('comprobante');
    Route::post('/contabilidad/comprobantes/{comprobante}/contabilizar', [ComprobanteController::class, 'contabilizar'])->middleware('permiso:contabilidad,contabilizar')->whereNumber('comprobante');
    Route::post('/contabilidad/comprobantes/{comprobante}/reversar', [ComprobanteController::class, 'reversar'])->middleware('permiso:contabilidad,anular');

    Route::get('/contabilidad/libro-diario', [ReporteContableController::class, 'libroDiario'])->middleware('permiso:informes_contables,listar_libro_diario');
    Route::get('/contabilidad/libro-mayor', [ReporteContableController::class, 'libroMayor'])->middleware('algun_permiso:informes_contables.listar_libro_mayor,informes_contables.listar_caja_bancos');
    Route::get('/contabilidad/balance-prueba', [ReporteContableController::class, 'balancePrueba'])->middleware('permiso:informes_contables,listar_balance_prueba');
    Route::get('/contabilidad/estados-financieros', [ReporteContableController::class, 'estadosFinancieros'])->middleware('permiso:informes_contables,listar_estados_financieros');
    Route::post('/contabilidad/libro-diario/exportar', [ReporteContableController::class, 'exportarLibroDiario'])->middleware(['permiso:informes_contables,listar_libro_diario', 'exportacion:contabilidad.libro_diario']);
    Route::post('/contabilidad/libro-mayor/exportar', [ReporteContableController::class, 'exportarLibroMayor'])->middleware(['algun_permiso:informes_contables.listar_libro_mayor,informes_contables.listar_caja_bancos', 'exportacion:contabilidad.libro_mayor,contabilidad.caja_bancos']);
    Route::post('/contabilidad/balance-prueba/exportar', [ReporteContableController::class, 'exportarBalancePrueba'])->middleware(['permiso:informes_contables,listar_balance_prueba', 'exportacion:contabilidad.balance_prueba']);
    Route::post('/contabilidad/estados-financieros/exportar', [ReporteContableController::class, 'exportarEstadosFinancieros'])->middleware(['permiso:informes_contables,listar_estados_financieros', 'exportacion:contabilidad.estados_financieros']);

    Route::get('/contabilidad/libro-diario/pdf', [ReporteContableController::class, 'pdfLibroDiario'])->middleware(['permiso:informes_contables,listar_libro_diario', 'permiso:informes_contables,imprimir']);
    Route::get('/contabilidad/libro-mayor/pdf', [ReporteContableController::class, 'pdfLibroMayor'])->middleware(['algun_permiso:informes_contables.listar_libro_mayor,informes_contables.listar_caja_bancos', 'permiso:informes_contables,imprimir']);
    Route::get('/contabilidad/balance-prueba/pdf', [ReporteContableController::class, 'pdfBalancePrueba'])->middleware(['permiso:informes_contables,listar_balance_prueba', 'permiso:informes_contables,imprimir']);
    Route::get('/contabilidad/estados-financieros/pdf', [ReporteContableController::class, 'pdfEstadosFinancieros'])->middleware(['permiso:informes_contables,listar_estados_financieros', 'permiso:informes_contables,imprimir']);
    Route::get('/contabilidad/conciliacion-apertura', [ReporteContableController::class, 'conciliacionApertura'])->middleware('permiso:informes_contables,listar_conciliacion_apertura');
    Route::get('/contabilidad/controles-cierre', [ReporteContableController::class, 'controlesCierre'])->middleware('permiso:informes_contables,listar_controles_cierre');

    Route::get('/contabilidad/dashboard/situacion', [DashboardContableController::class, 'situacion'])->middleware('permiso:informes_contables,ver_dashboard_contable');
    Route::get('/contabilidad/dashboard/resultados', [DashboardContableController::class, 'resultados'])->middleware('permiso:informes_contables,ver_dashboard_contable');
    Route::get('/contabilidad/dashboard/cierre', [DashboardContableController::class, 'cierre'])->middleware('permiso:informes_contables,ver_dashboard_contable');

    Route::get('/contabilidad/auxiliares', [AuxiliarContableController::class, 'index'])->middleware('permiso:informes_contables,listar_auxiliares');
    Route::post('/contabilidad/auxiliares/exportar', [AuxiliarContableController::class, 'exportar'])->middleware(['permiso:informes_contables,listar_auxiliares', 'exportacion:contabilidad.auxiliar']);
    Route::get('/contabilidad/auxiliares/pdf', [AuxiliarContableController::class, 'pdf'])->middleware(['permiso:informes_contables,listar_auxiliares', 'permiso:informes_contables,imprimir']);

    // Importaciones: la ruta pide algún permiso de hoja; el servicio exige el de la hoja de cada carga o guía.
    Route::prefix('importaciones')->middleware('algun_permiso:'.implode(',', ImportacionConstants::PERMISOS_IMPORTAR))->group(function () {
        Route::get('/', [ImportacionController::class, 'index']);
        Route::post('/', [ImportacionController::class, 'store']);
        Route::get('/guias/{tipo}', [ImportacionController::class, 'guia'])->where('tipo', '[a-z_]+');
        Route::get('/{importacion}', [ImportacionController::class, 'show'])->whereNumber('importacion');
        Route::get('/{importacion}/errores', [ImportacionController::class, 'errores'])->whereNumber('importacion');
        Route::get('/{importacion}/reporte', [ImportacionController::class, 'reporte'])->whereNumber('importacion');
        Route::post('/{importacion}/decisiones', [ImportacionController::class, 'decidir'])->whereNumber('importacion');
        Route::post('/{importacion}/confirmar', [ImportacionController::class, 'confirmar'])->whereNumber('importacion');
        Route::post('/{importacion}/cancelar', [ImportacionController::class, 'cancelar'])->whereNumber('importacion');
    });

    Route::get('/terceros', [TerceroController::class, 'index']);
    Route::post('/terceros/personas-naturales', [TerceroController::class, 'storeNatural'])->middleware('permiso:terceros,crear');
    Route::post('/terceros/personas-juridicas', [TerceroController::class, 'storeJuridica'])->middleware('permiso:terceros,crear');
    Route::get('/terceros/{tercero}', [TerceroController::class, 'show'])->middleware('permiso:terceros,listar');
    Route::patch('/terceros/personas-naturales/{tercero}', [TerceroController::class, 'updateNatural'])->middleware('permiso:terceros,editar');
    Route::patch('/terceros/personas-juridicas/{tercero}', [TerceroController::class, 'updateJuridica'])->middleware('permiso:terceros,editar');
    Route::get('/terceros/{tercero}/documentos-pendientes', [DocumentoTerceroController::class, 'pendientes'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_CARTERA_TERCEROS));

    Route::get('/cartera-terceros', [CarteraTerceroController::class, 'index'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_CARTERA_TERCEROS));
    Route::get('/cartera-terceros/edades', [CarteraTerceroController::class, 'edades'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_CARTERA_TERCEROS));
    Route::get('/contabilidad/documentos-tercero/{documentoTercero}/trazabilidad', [DocumentoTerceroController::class, 'trazabilidad'])->middleware('algun_permiso:'.implode(',', ContabilidadConstants::CONSULTA_CARTERA_TERCEROS));

    Route::get('/proveedores', [ProveedorController::class, 'index'])->middleware('permiso:terceros,listar');
    Route::post('/proveedores/personas-naturales', [ProveedorController::class, 'storeNatural'])->middleware('permiso:terceros,crear');
    Route::post('/proveedores/personas-juridicas', [ProveedorController::class, 'storeJuridica'])->middleware('permiso:terceros,crear');
    Route::get('/proveedores/{proveedor}', [ProveedorController::class, 'show'])->middleware('permiso:terceros,listar');
    Route::patch('/proveedores/personas-naturales/{proveedor}', [ProveedorController::class, 'updateNatural'])->middleware('permiso:terceros,editar');
    Route::patch('/proveedores/personas-juridicas/{proveedor}', [ProveedorController::class, 'updateJuridica'])->middleware('permiso:terceros,editar');

    Route::get('/gastos/tipos-gasto', [TipoGastoController::class, 'index'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_TIPOS_GASTO));
    Route::post('/gastos/tipos-gasto', [TipoGastoController::class, 'store'])->middleware('permiso:gastos,crear_tipos_gasto');
    Route::get('/gastos/tipos-gasto/{tipoGasto}', [TipoGastoController::class, 'show'])->middleware('algun_permiso:gastos.listar_tipos_gasto,gastos.editar_tipos_gasto');
    Route::patch('/gastos/tipos-gasto/{tipoGasto}', [TipoGastoController::class, 'update'])->middleware('permiso:gastos,editar_tipos_gasto');
    Route::delete('/gastos/tipos-gasto/{tipoGasto}', [TipoGastoController::class, 'destroy'])->middleware('permiso:gastos,eliminar_tipos_gasto');

    Route::get('/gastos/actividades-economicas', [ActividadEconomicaController::class, 'index'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_ACTIVIDADES_ECONOMICAS));
    Route::post('/gastos/actividades-economicas', [ActividadEconomicaController::class, 'store'])->middleware('permiso:tributario,crear_actividades_economicas');
    Route::patch('/gastos/actividades-economicas/{actividadEconomica}', [ActividadEconomicaController::class, 'update'])->middleware('permiso:tributario,editar_actividades_economicas');

    Route::get('/gastos/valores-uvt', [ValorUvtController::class, 'index'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_VALORES_UVT));
    Route::post('/gastos/valores-uvt', [ValorUvtController::class, 'store'])->middleware('permiso:tributario,crear_valores_uvt');
    Route::patch('/gastos/valores-uvt/{valorUvt}', [ValorUvtController::class, 'update'])->middleware('permiso:tributario,editar_valores_uvt');
    Route::delete('/gastos/valores-uvt/{valorUvt}', [ValorUvtController::class, 'destroy'])->middleware('permiso:tributario,eliminar_valores_uvt');

    Route::get('/gastos/parametrizaciones', [ParametrizacionTipoGastoController::class, 'index'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_PARAMETRIZACIONES));
    Route::post('/gastos/parametrizaciones', [ParametrizacionTipoGastoController::class, 'store'])->middleware('permiso:gastos,crear_parametrizaciones');
    Route::get('/gastos/parametrizaciones/{parametrizacion}', [ParametrizacionTipoGastoController::class, 'show'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_PARAMETRIZACIONES));
    Route::patch('/gastos/parametrizaciones/{parametrizacion}', [ParametrizacionTipoGastoController::class, 'update'])->middleware('permiso:gastos,crear_parametrizaciones');

    Route::get('/gastos/tarifas-reteica', [TarifaReteIcaMunicipioController::class, 'index'])->middleware('algun_permiso:'.implode(',', GastosConstants::CONSULTA_TARIFAS_RETEICA));
    Route::post('/gastos/tarifas-reteica', [TarifaReteIcaMunicipioController::class, 'store'])->middleware('permiso:tributario,crear_tarifas_reteica');

    Route::get('/gastos', [GastoController::class, 'index'])->middleware('permiso:gastos,listar_gastos');
    Route::post('/gastos/exportar', [GastoController::class, 'exportar'])->middleware(['permiso:gastos,listar_gastos', 'exportacion:gastos.listado']);
    Route::get('/gastos/reporte-centro-costo', [ReporteGastosCentroCostoController::class, 'index'])->middleware('permiso:gastos,listar_gastos_centro_costo');
    Route::post('/gastos/reporte-centro-costo/exportar', [ReporteGastosCentroCostoController::class, 'exportar'])->middleware(['permiso:gastos,listar_gastos_centro_costo', 'exportacion:gastos.centro_costo']);

    Route::get('/facturacion/documentos', [DocumentoFacturacionController::class, 'index'])->middleware('algun_permiso:'.implode(',', FacturacionConstants::CONSULTA_DOCUMENTOS));
    // Antes de la ruta con {documento}: si no, el binding intenta resolver "conceptos-sugeridos" como un id.
    Route::get('/facturacion/documentos/conceptos-sugeridos', [DocumentoFacturacionController::class, 'conceptosSugeridos'])->middleware('permiso:facturacion,crear');
    Route::post('/facturacion/documentos/intereses-pagados', [DocumentoFacturacionController::class, 'facturarInteresesPagados'])->middleware('permiso:facturacion,crear');
    Route::post('/facturacion/documentos', [DocumentoFacturacionController::class, 'store'])->middleware('permiso:facturacion,crear');
    Route::get('/facturacion/documentos/{documento}', [DocumentoFacturacionController::class, 'show'])->middleware('algun_permiso:'.implode(',', FacturacionConstants::CONSULTA_DOCUMENTOS));
    Route::patch('/facturacion/documentos/{documento}', [DocumentoFacturacionController::class, 'update'])->middleware('permiso:facturacion,crear');
    Route::delete('/facturacion/documentos/{documento}', [DocumentoFacturacionController::class, 'destroy'])->middleware('permiso:facturacion,crear');
    Route::post('/facturacion/documentos/{documento}/emitir', [DocumentoFacturacionController::class, 'emitir'])->middleware('permiso:facturacion,emitir');
    // El permiso depende del tipo de nota (en el body, no en la ruta): el Controller exige emitir_nota_credito/emitir_nota_debito.
    Route::post('/facturacion/documentos/{documento}/notas', [DocumentoFacturacionController::class, 'nota']);
    Route::post('/facturacion/documentos/{documento}/aprobar', [DocumentoFacturacionController::class, 'aprobar'])->middleware('permiso:facturacion,aprobar_nota_credito');
    Route::post('/facturacion/documentos/{documento}/anular', [DocumentoFacturacionController::class, 'anular'])->middleware('permiso:facturacion,anular');
    Route::post('/facturacion/documentos/{documento}/cobros', [CobroFacturacionController::class, 'store'])->middleware('permiso:facturacion,registrar_cobro');
    Route::post('/facturacion/cobros', [CobroFacturacionController::class, 'storeMultiple'])->middleware('permiso:facturacion,registrar_cobro');
    Route::post('/facturacion/cobros/{cobro}/reversar', [CobroFacturacionController::class, 'reversar'])->middleware('permiso:facturacion,reversar_cobro');
    Route::get('/facturacion/cobros/{cobro}/recibo/pdf', [CobroFacturacionController::class, 'pdf'])->middleware('permiso:facturacion,imprimir');
    Route::get('/facturacion/documentos/{documento}/pdf', [DocumentoFacturacionController::class, 'pdf'])->middleware('permiso:facturacion,imprimir');
    Route::post('/facturacion/documentos/{documento}/reenviar', [DocumentoFacturacionController::class, 'reenviar'])->middleware('permiso:facturacion,enviar');
    Route::get('/facturacion/documentos/{documento}/envios', [DocumentoFacturacionController::class, 'historialEnvios'])->middleware('algun_permiso:'.implode(',', FacturacionConstants::CONSULTA_DOCUMENTOS));
    Route::post('/facturacion/documentos/{documento}/envios/{envio}/reintentar', [DocumentoFacturacionController::class, 'reintentarEnvio'])->middleware('permiso:facturacion,enviar');
    Route::get('/facturacion/consecutivos', [ConsecutivoFacturacionController::class, 'index'])->middleware('permiso:facturacion,configurar');
    Route::patch('/facturacion/consecutivos/{tipo}', [ConsecutivoFacturacionController::class, 'update'])->middleware('permiso:facturacion,configurar');
    Route::get('/facturacion/parametrizacion-conceptos', [ParametrizacionConceptoFacturacionController::class, 'index'])->middleware('permiso:facturacion,configurar');
    Route::put('/facturacion/parametrizacion-conceptos/{tipoConcepto}', [ParametrizacionConceptoFacturacionController::class, 'update'])->middleware('permiso:facturacion,configurar');
    Route::post('/gastos', [GastoController::class, 'store'])->middleware('permiso:gastos,crear');
    Route::get('/gastos/{gasto}', [GastoController::class, 'show'])->middleware('permiso:gastos,listar_gastos');
    Route::delete('/gastos/{gasto}', [GastoController::class, 'destroy'])->middleware('permiso:gastos,anular');

    Route::get('/egresos', [EgresoController::class, 'index'])->middleware('permiso:egresos,listar');
    Route::post('/egresos', [EgresoController::class, 'store'])->middleware('permiso:egresos,crear');
    Route::post('/egresos/{egreso}/reversar', [EgresoController::class, 'reversar'])->middleware('permiso:egresos,reversar')->whereNumber('egreso');
    Route::post('/anticipos/proveedores', [AnticipoController::class, 'storeProveedor'])->middleware('permiso:anticipos,crear');
    Route::post('/anticipos/clientes', [AnticipoController::class, 'storeCliente'])->middleware('permiso:anticipos,crear');
    Route::post('/anticipos/cruzar', [AnticipoController::class, 'cruzar'])->middleware('permiso:anticipos,crear');

    Route::get('/conciliacion/cuentas-bancarias', [CuentaBancariaController::class, 'index'])->middleware('permiso:conciliacion,listar');
    Route::post('/conciliacion/cuentas-bancarias', [CuentaBancariaController::class, 'store'])->middleware('permiso:conciliacion,configurar');
    Route::patch('/conciliacion/cuentas-bancarias/{cuenta_bancaria}', [CuentaBancariaController::class, 'update'])->middleware('permiso:conciliacion,configurar');
    Route::get('/conciliacion/cuentas-bancarias/{cuenta_bancaria}/extractos', [ExtractoBancarioController::class, 'index'])->middleware('permiso:conciliacion,listar');
    Route::post('/conciliacion/cuentas-bancarias/{cuenta_bancaria}/extractos', [ExtractoBancarioController::class, 'store'])->middleware('permiso:conciliacion,cargar_extracto');
    Route::get('/conciliacion/extractos/{extracto}/candidatos', [MatchConciliacionController::class, 'candidatos'])->middleware('permiso:conciliacion,listar');
    Route::post('/conciliacion/movimientos/{movimiento}/conciliar', [MatchConciliacionController::class, 'conciliar'])->middleware('permiso:conciliacion,conciliar');
    Route::post('/conciliacion/movimientos/{movimiento}/enviar-a-puente', [MatchConciliacionController::class, 'enviarAPuente'])->middleware('permiso:conciliacion,conciliar');
    Route::post('/conciliacion/marcas/{marca}/desmarcar', [MatchConciliacionController::class, 'desmarcar'])->middleware('permiso:conciliacion,conciliar');
    Route::post('/conciliacion/extractos/{extracto}/cerrar', [CierreExtractoController::class, 'cerrar'])->middleware('permiso:conciliacion,cerrar');
    Route::post('/conciliacion/extractos/{extracto}/reabrir', [CierreExtractoController::class, 'reabrir'])->middleware('permiso:conciliacion,reabrir');
});
