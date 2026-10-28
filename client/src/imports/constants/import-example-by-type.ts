import type { ImportType } from './import-types';

/**
 * El ejemplo de clientes, creditos, plan_cuotas y pagos debe coincidir con la huella que usa el backend para
 * rechazar la fila de ejemplo (`DefinicionImportacion::huellaDeEjemplo()`) — si cambia uno, cambia el otro.
 */
export const IMPORT_EXAMPLE_BY_TYPE: Record<ImportType, { columns: { key: string; label: string }[]; example: Record<string, string> }> = {
    clientes: {
        columns: [
            { key: 'cedula', label: 'Cédula' },
            { key: 'nombre', label: 'Nombre completo' },
            { key: 'telefono', label: 'Teléfono' },
        ],
        example: { cedula: '53125435', nombre: 'Adriana Maria Daza Mesa', telefono: '3001234567' },
    },
    creditos: {
        columns: [
            { key: 'codigo', label: 'Código de crédito' },
            { key: 'cedula', label: 'Cédula cliente' },
            { key: 'fecha_desembolso', label: 'Fecha de desembolso' },
            { key: 'valor', label: 'Valor desembolsado (capital)' },
            { key: 'tasa', label: 'Tasa de interés mensual (%)' },
            { key: 'plazo', label: 'Plazo (# cuotas)' },
            { key: 'periodicidad', label: 'Periodicidad (Mensual/Quincenal)' },
            { key: 'cuota', label: 'Cuota fija' },
            { key: 'gastos_administracion', label: 'Gastos administrativos (%)' },
            { key: 'es_refinanciacion', label: '¿Es refinanciación? (Sí/No)' },
        ],
        example: {
            codigo: 'CR-2023-001',
            cedula: '53125435',
            fecha_desembolso: '01/10/2023',
            valor: '2000000',
            tasa: '2,5',
            plazo: '4',
            periodicidad: 'mensual',
            cuota: '',
            gastos_administracion: '0,20',
            es_refinanciacion: 'No',
        },
    },
    plan_cuotas: {
        columns: [
            { key: 'codigo', label: 'Código de crédito' },
            { key: 'numero', label: 'Número de cuota' },
            { key: 'fecha', label: 'Fecha de vencimiento' },
            { key: 'total', label: 'Valor total cuota' },
        ],
        example: { codigo: 'CR-2023-001', numero: '1', fecha: '01/11/2023', total: '280500' },
    },
    pagos: {
        columns: [
            { key: 'codigo', label: 'Código de crédito' },
            { key: 'fecha', label: 'Fecha de pago' },
            { key: 'valor', label: 'Valor pagado' },
            { key: 'tipo', label: 'Tipo' },
        ],
        example: { codigo: 'CR-2023-001', fecha: '01/11/2023', valor: '280500', tipo: 'Cuota completa' },
    },
    saldos_iniciales: {
        columns: [
            { key: 'fecha_corte', label: 'Fecha de corte' },
            { key: 'cuenta', label: 'Cuenta PUC' },
            { key: 'debito', label: 'Saldo débito' },
            { key: 'credito', label: 'Saldo crédito' },
            { key: 'cedula', label: 'Cédula' },
        ],
        example: { fecha_corte: '01/10/2024', cuenta: '133005', debito: '45000000', credito: '', cedula: '' },
    },
    plan_cuentas: {
        columns: [
            { key: 'codigo', label: 'Código' },
            { key: 'nombre', label: 'Nombre' },
            { key: 'naturaleza', label: 'Naturaleza' },
        ],
        example: { codigo: '133005', nombre: 'Cartera de créditos', naturaleza: 'Deudora' },
    },
    terceros: {
        columns: [
            { key: 'tipo', label: 'Tipo' },
            { key: 'tipo_persona', label: 'Tipo de persona' },
            { key: 'numero_documento', label: 'Número de documento' },
        ],
        example: { tipo: 'Natural', tipo_persona: 'Persona natural', numero_documento: '53125435' },
    },
    gastos: {
        columns: [
            { key: 'fecha', label: 'Fecha del gasto' },
            { key: 'numero_documento', label: 'Número de documento' },
            { key: 'tipo_gasto', label: 'Tipo de gasto' },
            { key: 'valor_bruto', label: 'Valor bruto' },
        ],
        example: { fecha: '01/10/2024', numero_documento: 'FAC-001', tipo_gasto: 'Servicios', valor_bruto: '3500000' },
    },
    actividades_economicas: {
        columns: [
            { key: 'codigo', label: 'Código CIIU' },
            { key: 'nombre', label: 'Actividad económica' },
        ],
        example: { codigo: '4722', nombre: 'Comercio al por menor' },
    },
    valores_uvt: {
        columns: [
            { key: 'fecha_desde', label: 'Fecha desde' },
            { key: 'valor', label: 'Valor UVT' },
        ],
        example: { fecha_desde: '01/01/2024', valor: '46700' },
    },
    tarifas_reteica: {
        columns: [
            { key: 'municipio', label: 'Municipio' },
            { key: 'actividad_economica', label: 'Actividad económica' },
            { key: 'tarifa', label: 'Tarifa (%)' },
        ],
        example: { municipio: '11001', actividad_economica: '4722', tarifa: '1,09' },
    },
};
