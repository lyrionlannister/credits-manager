import type { FC, ReactNode } from 'react';
import { useState } from 'react';
import { Link } from 'react-router';
import {
    AlertTriangle,
    BookOpenCheck,
    CalendarClock,
    CheckCircle2,
    FileClock,
    Landmark,
    PiggyBank,
    Scale,
    ShieldAlert,
    Wallet,
} from 'lucide-react';

import { useResource } from '../../shared/hooks/use-resource';
import { usePermission } from '../../auth/hooks/use-permission';
import { centsFromMoneyText } from '../../shared/utils/cents-from-money-text';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatDateTimeEs } from '../../shared/utils/format-datetime-es';
import { moneyTextFromCents } from '../../shared/utils/money-text-from-cents';
import { formatMoney } from '../../shared/utils/format-money';
import { StatCard } from '../../layout/components/stat-card';
import type { StatCardCambio } from '../../layout/types/stat-card-cambio';
import { MONTHS } from '../constants/months';
import type { ApiTaxObligation, ApiTaxObligationColor } from '../../tax-obligations/types/api-tax-obligation';
import type {
    ApiDashboardAccountRow,
    ApiDashboardCierre,
    ApiDashboardClosingBalanceSummary,
    ApiDashboardCostCenterExpenseRow,
    ApiDashboardResultados,
    ApiDashboardSituacion,
} from '../types/api-dashboard-contable';
import { Alert } from '@ui/alert/components/alert';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { DonutChart } from '@ui/charts/components/donut-chart';
import { LineChart } from '@ui/charts/components/line-chart';
import { RadialGaugeChart } from '@ui/charts/components/radial-gauge-chart';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Label } from '@ui/forms/components/label';
import { DataTable } from '@ui/table/components/data-table';

const FORMATO_MES = new Intl.DateTimeFormat('es-CO', { month: 'short', year: '2-digit' });

/** Paleta categórica para los donuts de composición — ciclada por índice, no por significado semántico. */
const CATEGORY_COLORS = ['#465FFF', '#12B76A', '#F79009', '#F04438', '#7A5AF8', '#0BA5EC', '#EE46BC'];

const OBLIGATION_COLOR_CLASSES: Record<ApiTaxObligationColor, string> = {
    success: 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
    warning: 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
    danger: 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400',
    primary: 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
};

function etiquetaDeMes(mes: string): string {
    const [anio, numeroMes] = mes.split('-').map(Number);

    return FORMATO_MES.format(new Date(anio, numeroMes - 1, 1));
}

function primerDiaDelAnio(): string {
    return `${new Date().getFullYear()}-01-01`;
}

/** Variación porcentual entre dos montos decimales en string; `null` sin base de comparación (anterior = 0). */
function construirCambio(actual: string, anterior: string, referencia: string, favorableSiBaja = false): StatCardCambio {
    const valorAnterior = Number(anterior);
    const porcentaje = valorAnterior === 0 ? null : ((Number(actual) - valorAnterior) / Math.abs(valorAnterior)) * 100;

    return {
        porcentaje,
        favorable: porcentaje === null || (favorableSiBaja ? porcentaje <= 0 : porcentaje >= 0),
        referencia,
    };
}

/**
 * `centerContent` centra verticalmente el contenido dentro del alto disponible — para tarjetas que comparten fila
 * (grid stira todas a la altura de la más alta) y cuyo contenido es más corto que sus vecinas, así no queda
 * pegado arriba con un vacío debajo.
 */
const Card: FC<{ title: string; to?: string; children: ReactNode; centerContent?: boolean }> = ({ title, to, children, centerContent = false }) => (
    <div className="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 sm:p-6">
        <div className="mb-4 flex items-center justify-between gap-3">
            <h3 className="text-base font-semibold text-gray-800 dark:text-white/90">{title}</h3>
            {to && (
                <Link to={to} className="text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                    Ver detalle
                </Link>
            )}
        </div>
        <div className={centerContent ? 'flex flex-1 flex-col justify-center' : ''}>{children}</div>
    </div>
);

/** Fila ya procesada para el donut: homogeneiza `ApiDashboardAccountRow` y `ApiDashboardCostCenterExpenseRow`. */
type DonutBreakdownRow = { key: string | number; nombre: string; valor: string };

const MAX_FILAS_DONUT = 5;

/**
 * Descarta las filas en cero y, si quedan más de `MAX_FILAS_DONUT`, conserva las de mayor magnitud y agrupa
 * el resto en una fila "Otros" — la suma de "Otros" se hace en centavos enteros, nunca con floats.
 */
function construirFilasDonut(rows: (ApiDashboardAccountRow | ApiDashboardCostCenterExpenseRow)[]): DonutBreakdownRow[] {
    const conValor = rows
        .map((fila) => ({
            key: 'codigo' in fila ? fila.codigo : (fila.centro_costo_id ?? fila.nombre),
            nombre: fila.nombre,
            valor: fila.valor,
        }))
        .filter((fila) => centsFromMoneyText(fila.valor) !== 0n);

    if (conValor.length <= MAX_FILAS_DONUT) {
        return conValor;
    }

    const ordenadas = [...conValor].sort((a, b) => {
        const absolutaA = centsFromMoneyText(a.valor) < 0n ? -centsFromMoneyText(a.valor) : centsFromMoneyText(a.valor);
        const absolutaB = centsFromMoneyText(b.valor) < 0n ? -centsFromMoneyText(b.valor) : centsFromMoneyText(b.valor);

        return absolutaA > absolutaB ? -1 : absolutaA < absolutaB ? 1 : 0;
    });

    const principales = ordenadas.slice(0, MAX_FILAS_DONUT);
    const resto = ordenadas.slice(MAX_FILAS_DONUT);
    const totalResto = resto.reduce((suma, fila) => suma + centsFromMoneyText(fila.valor), 0n);

    return [...principales, { key: 'otros', nombre: 'Otros', valor: moneyTextFromCents(totalResto) }];
}

/**
 * Donut de composición + leyenda con participación %, igual patrón que "Categoría de ventas" del demo CRM de
 * TailAdmin. `compact` apila el donut sobre la leyenda (en vez de lado a lado) y reduce tamaños, para cuando
 * varias de estas tarjetas comparten una fila de 3-4 columnas en vez de 2.
 */
const DonutBreakdown: FC<{
    title: string;
    to?: string;
    rows: (ApiDashboardAccountRow | ApiDashboardCostCenterExpenseRow)[];
    emptyMessage: string;
    compact?: boolean;
}> = ({ title, to, rows, emptyMessage, compact = false }) => {
    const filas = construirFilasDonut(rows);
    const magnitudes = filas.map((fila) => Math.abs(Number(fila.valor)));
    const total = magnitudes.reduce((suma, valor) => suma + valor, 0);

    return (
        <Card title={title} to={to} centerContent>
            {filas.length === 0 ? (
                <p className="text-center text-sm text-gray-500 dark:text-gray-400">{emptyMessage}</p>
            ) : (
                <div className={`flex flex-col items-center gap-4 ${compact ? '' : 'sm:flex-row sm:gap-6'}`}>
                    <div className={`w-full shrink-0 ${compact ? 'max-w-40' : 'max-w-55'}`}>
                        <DonutChart
                            series={magnitudes}
                            labels={filas.map((fila) => fila.nombre)}
                            colors={filas.map((_, indice) => CATEGORY_COLORS[indice % CATEGORY_COLORS.length])}
                            totalLabel="Total"
                            totalValue={formatMoney(total)}
                            valueFormatter={(valor) => formatMoney(valor)}
                            height={compact ? 170 : 220}
                            showLegend={false}
                        />
                    </div>
                    <ul className={`custom-scrollbar w-full flex-1 overflow-y-auto ${compact ? 'max-h-40 space-y-1.5' : 'max-h-96 space-y-3'}`}>
                        {filas.map((fila, indice) => (
                            <li key={fila.key} className={`flex items-center justify-between gap-2 ${compact ? 'text-xs' : 'text-sm'}`}>
                                <span className="flex min-w-0 items-center gap-2">
                                    <span
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{ backgroundColor: CATEGORY_COLORS[indice % CATEGORY_COLORS.length] }}
                                    />
                                    <span className="truncate text-gray-600 dark:text-gray-300">{fila.nombre}</span>
                                </span>
                                <span className="shrink-0 font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                                    {formatMoney(fila.valor)}
                                    <span className="ml-1.5 text-gray-400 dark:text-gray-500">
                                        {total === 0 ? '0' : ((magnitudes[indice] / total) * 100).toFixed(0)}%
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
};

const SemaphoreRow: FC<{ label: string; ok: boolean; detail: string; to: string }> = ({ label, ok, detail, to }) => (
    <li className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
        <div className="flex min-w-0 items-center gap-2">
            {ok ? (
                <CheckCircle2 className="size-4 shrink-0 text-success-600 dark:text-success-400" />
            ) : (
                <AlertTriangle className="size-4 shrink-0 text-error-600 dark:text-error-400" />
            )}
            <span className="truncate text-sm text-gray-700 dark:text-gray-300">{label}</span>
        </div>
        <div className="flex shrink-0 items-center gap-3">
            <span className="text-theme-xs text-gray-500 dark:text-gray-400">{detail}</span>
            <Link to={to} className="text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                Ver
            </Link>
        </div>
    </li>
);

const TaxObligationRow: FC<{ obligacion: ApiTaxObligation }> = ({ obligacion }) => (
    <li className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
        <span className={`mt-0.5 shrink-0 rounded-lg px-2.5 py-1 text-center text-xs font-semibold whitespace-nowrap ${OBLIGATION_COLOR_CLASSES[obligacion.color]}`}>
            {formatDateEs(obligacion.fecha_limite)}
        </span>
        <div className="min-w-0">
            <p className="truncate text-sm font-medium text-gray-800 dark:text-white/90">{obligacion.tipo_obligacion.nombre}</p>
            <p className="truncate text-theme-xs text-gray-500 dark:text-gray-400">
                {obligacion.periodo}
                {obligacion.responsable && ` · ${obligacion.responsable.nombre}`}
            </p>
        </div>
    </li>
);

const balanceDetail = (balance: ApiDashboardClosingBalanceSummary): string =>
    balance.cuadra ? 'Cuadra' : `Diferencia ${formatMoney(balance.diferencia)}`;

export const AccountingDashboardPage: FC = () => {
    const [hasta, setHasta] = useState(() => new Date().toISOString().slice(0, 10));
    const [desde, setDesde] = useState(primerDiaDelAnio());

    const { data: situacion } = useResource<ApiDashboardSituacion>(`/contabilidad/dashboard/situacion?hasta=${hasta}`);
    const { data: resultados } = useResource<ApiDashboardResultados>(`/contabilidad/dashboard/resultados?desde=${desde}&hasta=${hasta}`);
    const { data: cierre } = useResource<ApiDashboardCierre>(`/contabilidad/dashboard/cierre?hasta=${hasta}`);

    const puedeVerObligaciones = usePermission('obligaciones_tributarias', 'listar');
    const { data: obligacionesResp } = useResource<{ data: ApiTaxObligation[] }>(
        puedeVerObligaciones ? '/obligaciones-tributarias?estado=pendiente' : null,
    );
    const proximasObligaciones = (obligacionesResp?.data ?? [])
        .slice()
        .sort((a, b) => a.fecha_limite.localeCompare(b.fecha_limite))
        .slice(0, 5);

    const cambioUtilidad = resultados
        ? construirCambio(resultados.utilidad_periodo, resultados.utilidad_mismo_periodo_anio_anterior, 'vs. mismo período año anterior')
        : null;

    return (
        <div className="flex min-h-full flex-col gap-6 pb-6">
            <PageBreadcrumb pageTitle="Panel contable" />

            <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <Label htmlFor="desde">Desde (resultados)</Label>
                        <DatePicker id="desde" value={desde} onChange={setDesde} />
                    </div>
                    <div>
                        <Label htmlFor="hasta">Corte al</Label>
                        <DatePicker id="hasta" value={hasta} onChange={setHasta} />
                    </div>
                </div>
            </div>

            {situacion && (
                <>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Situación financiera (corte {hasta})</h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
                        <StatCard label="Total activo" value={formatMoney(situacion.total_activos)} icon={Wallet} to="/contabilidad/estados-financieros" />
                        <StatCard label="Total pasivo" value={formatMoney(situacion.total_pasivos)} icon={Landmark} to="/contabilidad/estados-financieros" />
                        <StatCard label="Total patrimonio" value={formatMoney(situacion.total_patrimonio)} icon={PiggyBank} to="/contabilidad/estados-financieros" />
                        <StatCard
                            label="Ecuación contable"
                            value={situacion.cuadra ? 'Cuadra' : 'No cuadra'}
                            icon={Scale}
                            accent={situacion.cuadra ? 'success' : 'error'}
                            to="/contabilidad/estados-financieros"
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                        <DonutBreakdown title="Composición del activo" rows={situacion.composicion_activo} emptyMessage="Sin movimientos de activo en el rango." compact />
                        <DonutBreakdown title="Composición del pasivo" rows={situacion.composicion_pasivo} emptyMessage="Sin movimientos de pasivo en el rango." compact />
                        <DonutBreakdown
                            title="Composición del patrimonio"
                            rows={situacion.composicion_patrimonio}
                            emptyMessage="Sin movimientos de patrimonio en el rango."
                            compact
                        />
                        <Card title="Cartera neta contable (1305-1399)" to="/contabilidad/cuentas-por-cobrar" centerContent>
                            <div className="relative">
                                <RadialGaugeChart percentage={Number(situacion.cartera_neta.porcentaje_deterioro)} color="#F79009" height={200} />
                                <p className="-mt-6 text-center text-theme-xs text-gray-500 dark:text-gray-400">% deterioro</p>
                            </div>
                            <div className="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-xs dark:border-gray-800">
                                <div className="flex items-center justify-between">
                                    <span className="text-gray-500 dark:text-gray-400">Bruta</span>
                                    <span className="font-semibold text-gray-800 dark:text-white/90">{formatMoney(situacion.cartera_neta.bruta)}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-gray-500 dark:text-gray-400">Deterioro</span>
                                    <span className="font-semibold text-error-600 dark:text-error-400">{formatMoney(situacion.cartera_neta.deterioro)}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-gray-500 dark:text-gray-400">Neta</span>
                                    <span className="font-semibold text-gray-800 dark:text-white/90">{formatMoney(situacion.cartera_neta.neta)}</span>
                                </div>
                            </div>
                        </Card>
                    </div>
                </>
            )}

            {resultados && (
                <>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">
                        Resultados ({desde} a {hasta})
                    </h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
                        <StatCard label="Total ingresos" value={formatMoney(resultados.total_ingresos)} icon={BookOpenCheck} to="/contabilidad/estados-financieros" />
                        <StatCard label="Total costos y gastos" value={formatMoney(resultados.total_gastos)} icon={FileClock} to="/contabilidad/estados-financieros" />
                        <StatCard
                            label="Utilidad del período"
                            value={formatMoney(resultados.utilidad_periodo)}
                            icon={Scale}
                            cambio={cambioUtilidad ?? undefined}
                            to="/contabilidad/estados-financieros"
                        />
                        <StatCard
                            label="Utilidad acumulada del año"
                            value={formatMoney(resultados.utilidad_acumulada_anio)}
                            icon={PiggyBank}
                            to="/contabilidad/estados-financieros"
                        />
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 sm:p-6">
                        <h3 className="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Histórico de 12 meses</h3>
                        <LineChart
                            categories={resultados.serie_mensual.map((fila) => etiquetaDeMes(fila.mes))}
                            series={[
                                { name: 'Ingresos', data: resultados.serie_mensual.map((fila) => Number(fila.ingresos)), color: '#12B76A' },
                                { name: 'Gastos', data: resultados.serie_mensual.map((fila) => Number(fila.gastos)), color: '#F04438' },
                                { name: 'Utilidad', data: resultados.serie_mensual.map((fila) => Number(fila.utilidad)), color: '#465FFF' },
                            ]}
                            valueFormatter={(valor) => formatMoney(valor)}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <Card title="Ingresos por cuenta" to="/contabilidad/estados-financieros">
                            <div className="custom-scrollbar max-h-96 overflow-y-auto">
                                <DataTable
                                    columns={[
                                        { key: 'codigo', label: 'Cuenta' },
                                        { key: 'nombre', label: 'Nombre' },
                                        { key: 'valor', label: 'Valor', render: (row) => formatMoney(row.valor) },
                                    ]}
                                    rows={resultados.ingresos}
                                    getRowKey={(row) => row.codigo}
                                    emptyMessage="Sin ingresos en el rango."
                                />
                            </div>
                        </Card>
                        <DonutBreakdown
                            title="Gastos y costos por centro de costo"
                            to="/contabilidad/centros-costo"
                            rows={resultados.gastos_por_centro_costo}
                            emptyMessage="Sin gastos en el rango."
                        />
                    </div>
                </>
            )}

            {cierre && (
                <>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Calidad de la información y cierre</h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
                        <StatCard
                            label="Período"
                            value={`${MONTHS[cierre.periodo.mes - 1] ?? cierre.periodo.mes} ${cierre.periodo.anio}`}
                            icon={CalendarClock}
                            accent={cierre.periodo.estado === 'abierto' ? 'success' : 'neutral'}
                            to="/contabilidad/periodos"
                        />
                        <StatCard
                            label="Solicitudes de reapertura pendientes"
                            value={String(cierre.solicitudes_reapertura_pendientes.length)}
                            icon={ShieldAlert}
                            accent={cierre.solicitudes_reapertura_pendientes.length === 0 ? 'success' : 'error'}
                            to="/contabilidad/periodos"
                        />
                        <StatCard
                            label="Comprobantes pendientes de revisión"
                            value={String(cierre.comprobantes_pendientes_revision)}
                            icon={FileClock}
                            accent={cierre.comprobantes_pendientes_revision === 0 ? 'success' : 'error'}
                            to="/contabilidad/comprobantes"
                        />
                        <StatCard
                            label="Devengo de intereses"
                            value={
                                cierre.devengo_intereses.corrio_este_mes
                                    ? cierre.devengo_intereses.resultado === 'ok'
                                        ? 'Corrió bien este mes'
                                        : 'Corrió con error'
                                    : 'No ha corrido este mes'
                            }
                            icon={CalendarClock}
                            accent={cierre.devengo_intereses.corrio_este_mes && cierre.devengo_intereses.resultado === 'ok' ? 'success' : 'error'}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <Card title="Semáforo de controles de cierre" to="/contabilidad/controles-cierre">
                            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                <SemaphoreRow
                                    label="Cuentas por cobrar (1305)"
                                    ok={cierre.controles_cierre.cuentas_por_cobrar.cuadra}
                                    detail={balanceDetail(cierre.controles_cierre.cuentas_por_cobrar)}
                                    to="/contabilidad/controles-cierre"
                                />
                                <SemaphoreRow
                                    label="Cuentas por pagar (2205/2335)"
                                    ok={cierre.controles_cierre.cuentas_por_pagar.cuadra}
                                    detail={balanceDetail(cierre.controles_cierre.cuentas_por_pagar)}
                                    to="/contabilidad/controles-cierre"
                                />
                                <SemaphoreRow
                                    label="Saldos contrarios"
                                    ok={cierre.controles_cierre.saldos_contrarios === 0}
                                    detail={`${cierre.controles_cierre.saldos_contrarios} cuenta(s)`}
                                    to="/contabilidad/controles-cierre"
                                />
                                <SemaphoreRow
                                    label="Documentos inconsistentes"
                                    ok={cierre.controles_cierre.documentos_inconsistentes === 0}
                                    detail={`${cierre.controles_cierre.documentos_inconsistentes} documento(s)`}
                                    to="/contabilidad/controles-cierre"
                                />
                                <SemaphoreRow
                                    label="Conciliación bancaria"
                                    ok={cierre.controles_cierre.conciliacion_bancaria === 0}
                                    detail={`${cierre.controles_cierre.conciliacion_bancaria} movimiento(s)`}
                                    to="/contabilidad/controles-cierre"
                                />
                            </ul>
                        </Card>

                        <Card title="Cartera operativa vs. contable" to="/cartera">
                            {cierre.cartera_operativa_vs_contable.diferencia !== '0.00' && (
                                <div className="mb-4">
                                    <Alert
                                        variant="danger"
                                        title="Diferencia entre cartera y libros"
                                        message={`Diferencia de ${formatMoney(cierre.cartera_operativa_vs_contable.diferencia)} entre el saldo de los créditos vigentes y el saldo contable de la 1305.`}
                                    />
                                </div>
                            )}
                            <div className="flex flex-wrap gap-6 text-sm">
                                <span>
                                    <span className="text-gray-500 dark:text-gray-400">Operativa (créditos): </span>
                                    <span className="font-semibold text-gray-800 dark:text-white/90">
                                        {formatMoney(cierre.cartera_operativa_vs_contable.saldo_operativo)}
                                    </span>
                                </span>
                                <span>
                                    <span className="text-gray-500 dark:text-gray-400">Contable (1305-1399): </span>
                                    <span className="font-semibold text-gray-800 dark:text-white/90">
                                        {formatMoney(cierre.cartera_operativa_vs_contable.saldo_contable)}
                                    </span>
                                </span>
                            </div>
                        </Card>
                    </div>

                    {(proximasObligaciones.length > 0 || cierre.solicitudes_reapertura_pendientes.length > 0) && (
                        <div
                            className={`grid grid-cols-1 gap-6 ${
                                proximasObligaciones.length > 0 && cierre.solicitudes_reapertura_pendientes.length > 0 ? 'lg:grid-cols-2' : ''
                            }`}
                        >
                            {proximasObligaciones.length > 0 && (
                                <Card title="Próximas obligaciones tributarias" to="/obligaciones-tributarias">
                                    <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {proximasObligaciones.map((obligacion) => (
                                            <TaxObligationRow key={obligacion.id} obligacion={obligacion} />
                                        ))}
                                    </ul>
                                </Card>
                            )}

                            {cierre.solicitudes_reapertura_pendientes.length > 0 && (
                                <Card title="Solicitudes de reapertura pendientes" to="/contabilidad/periodos">
                                    <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {cierre.solicitudes_reapertura_pendientes.map((solicitud) => (
                                            <li key={solicitud.id} className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                                <span className="text-sm text-gray-700 dark:text-gray-300">
                                                    {solicitud.periodo
                                                        ? `${MONTHS[solicitud.periodo.mes - 1] ?? solicitud.periodo.mes} ${solicitud.periodo.anio}`
                                                        : 'Período'}
                                                    {' · '}
                                                    {solicitud.solicitante ?? '—'}
                                                </span>
                                                <span className="text-theme-xs text-gray-500 dark:text-gray-400">{formatDateTimeEs(solicitud.creado_en)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            )}
                        </div>
                    )}

                    {cierre.eventos_sin_regla.length > 0 && (
                        <Card title="Eventos contables sin regla activa" to="/contabilidad/reglas">
                            <ul className="grid grid-cols-1 gap-x-8 sm:grid-cols-2 xl:grid-cols-3">
                                {cierre.eventos_sin_regla.map((evento) => (
                                    <li
                                        key={evento.evento}
                                        className="flex items-center gap-2 border-b border-gray-100 py-3 text-sm text-gray-700 last:border-0 dark:border-gray-800 dark:text-gray-300 sm:nth-last-[-n+2]:border-0 xl:nth-last-[-n+3]:border-0"
                                    >
                                        <AlertTriangle className="size-4 shrink-0 text-warning-600 dark:text-warning-400" />
                                        <span className="truncate">{evento.etiqueta}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </>
            )}
        </div>
    );
};
