import type { FC } from 'react';
import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { usePermission } from '../../auth/hooks/use-permission';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import { centsFromMoneyText } from '../../shared/utils/cents-from-money-text';
import { formatMoney } from '../../shared/utils/format-money';
import { CreditCapitalRecoveryCard } from '../components/credit-capital-recovery-card';
import { CreditConditionsCard } from '../components/credit-conditions-card';
import { CreditDetailHeader } from '../components/credit-detail-header';
import { CreditInstallmentsChartCard } from '../components/credit-installments-chart-card';
import { CreditInstallmentsTable } from '../components/credit-installments-table';
import { CreditMetricTile } from '../components/credit-metric-tile';
import { RefinancingNotice } from '../components/refinancing-notice';
import { CREDIT_TRANSITION_ACTION_CONFIG } from '../constants/credit-transition-action-config';
import { puedeCancelarCredito } from '../utils/can-cancel-credit';
import type { ApiCreditDetail } from '../types/api-credit-detail';
import type { CreditTransitionAction } from '../types/credit-transition-action';
import { Ban, Banknote, CalendarClock, CheckCircle2, CircleDollarSign, FileText, HandCoins, Receipt, SlidersHorizontal, Trash2, Wallet } from 'lucide-react';

export const CreditDetailPage: FC = () => {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const canRegisterPayment = usePermission('pagos', 'crear');
    const canViewPayments = usePermission('pagos', 'listar');
    const canEditar = usePermission('creditos', 'editar');
    const canAprobar = usePermission('creditos', 'aprobar');
    const canDesembolsar = usePermission('creditos', 'desembolsar');
    const canCancelar = usePermission('creditos', 'cancelar');
    const canEliminar = usePermission('creditos', 'eliminar');
    const { sendRequest } = useSendRequest();

    const { data: detalle, error, refetch } = useResource<ApiCreditDetail>(`/creditos/${id}`);

    const [accionPendiente, setAccionPendiente] = useState<CreditTransitionAction | null>(null);
    const [isConfirming, setIsConfirming] = useState(false);
    const [accionError, setAccionError] = useState<string | null>(null);

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const confirmarAccion = async () => {
        if (!accionPendiente) {
            return;
        }

        setAccionError(null);
        setIsConfirming(true);

        try {
            await sendRequest(`/creditos/${id}/${accionPendiente}`, { method: 'POST' });
            setAccionPendiente(null);
            refetch();
        } catch (caught) {
            setAccionError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsConfirming(false);
        }
    };

    const confirmarEliminar = async () => {
        setDeleteError(null);
        setIsDeleting(true);

        try {
            await sendRequest(`/creditos/${id}`, { method: 'DELETE' });
            navigate('/creditos', { replace: true, state: { message: 'Crédito eliminado correctamente.' } });
        } catch (caught) {
            setDeleteError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
            setShowDeleteConfirm(false);
        } finally {
            setIsDeleting(false);
        }
    };

    if (error) {
        return (
            <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                {error}
            </div>
        );
    }

    if (!detalle) {
        return <PageSkeleton />;
    }

    const { credito, saldo, cartera, mora, refinanciacion, pagos, resumen_cuotas: resumenCuotas } = detalle;
    const prestadoCentavos = centsFromMoneyText(credito.valor_prestamo);
    const porcentajeCapitalRecuperado =
        prestadoCentavos > 0n ? Number((centsFromMoneyText(resumenCuotas.saldo_capital_pagado) * 10000n) / prestadoCentavos) / 100 : 0;
    const enMora = mora.cuotas_vencidas > 0;

    return (
        <div className="flex min-h-full flex-col gap-4 pb-6 md:gap-6">
            <PageBreadcrumb pageTitle={`Crédito de ${credito.cliente?.tercero.nombre ?? '—'}`} />

            <CreditDetailHeader credito={credito} cartera={cartera}>
                {(credito.estado === 'solicitado' || credito.estado === 'aprobado') && canEditar && (
                    <Button iconOnly startIcon={<SlidersHorizontal className="size-4" />} variant="outline" onClick={() => navigate(`/creditos/${credito.id}/editar`)}>
                        Modificar condiciones
                    </Button>
                )}
                {credito.estado === 'desembolsado' && canEditar && (
                    <Button iconOnly startIcon={<CalendarClock className="size-4" />} variant="outline" onClick={() => navigate(`/creditos/${credito.id}/reprogramar`)}>
                        Reprogramar
                    </Button>
                )}
                {canViewPayments && (
                    <Button iconOnly startIcon={<Receipt className="size-4" />} variant="outline" onClick={() => navigate(`/pagos/creditos/${credito.id}`)}>
                        Ver pagos
                    </Button>
                )}
                <Button iconOnly startIcon={<FileText className="size-4" />} variant="outline" onClick={() => navigate(`/creditos/${credito.id}/estado-cuenta`)}>
                    Estado de cuenta
                </Button>
                {canCancelar && puedeCancelarCredito(credito, pagos.length > 0) && (
                    <Button iconOnly startIcon={<Ban className="size-4" />} variant="outline" onClick={() => setAccionPendiente('cancelar')}>
                        Cancelar crédito
                    </Button>
                )}
                {canEliminar && (
                    <Button iconOnly startIcon={<Trash2 className="size-4" />} variant="outline" onClick={() => setShowDeleteConfirm(true)}>
                        Eliminar crédito
                    </Button>
                )}
                {credito.estado === 'desembolsado' && canRegisterPayment && (
                    <Button variant="outline" startIcon={<CircleDollarSign className="size-4" />} onClick={() => navigate(`/pagos/creditos/${credito.id}/liquidar`)}>
                        Liquidar
                    </Button>
                )}
                {credito.estado === 'solicitado' && canAprobar && (
                    <Button className="flex-1 lg:flex-none" startIcon={<CheckCircle2 className="size-4" />} onClick={() => setAccionPendiente('aprobar')}>
                        Aprobar
                    </Button>
                )}
                {credito.estado === 'aprobado' && canDesembolsar && (
                    <Button className="flex-1 lg:flex-none" startIcon={<Banknote className="size-4" />} onClick={() => setAccionPendiente('desembolsar')}>
                        Desembolsar
                    </Button>
                )}
                {credito.estado === 'desembolsado' && canRegisterPayment && (
                    <Button className="flex-1 lg:flex-none" startIcon={<HandCoins className="size-4" />} onClick={() => navigate(`/pagos/creditos/${credito.id}/nuevo`)}>
                        Pagar cuota
                    </Button>
                )}
            </CreditDetailHeader>

            {accionError && <Alert variant="danger" title="Error" message={accionError} onDismiss={() => setAccionError(null)} />}
            {deleteError && <Alert variant="danger" title="Error" message={deleteError} onDismiss={() => setDeleteError(null)} />}

            {refinanciacion && <RefinancingNotice vinculo={refinanciacion} valorPrestamo={credito.valor_prestamo} />}

            <div className="grid grid-cols-2 gap-3 md:gap-6 xl:grid-cols-4">
                <CreditMetricTile
                    label={cartera === 'Refinanciado' ? 'Saldo al refinanciar' : 'Saldo pendiente'}
                    value={formatMoney(saldo)}
                    icon={Wallet}
                    tone={enMora ? 'error' : 'brand'}
                    detail={
                        enMora
                            ? `${formatMoney(mora.monto_vencido)} vencido · ${mora.cuotas_vencidas} cuota${mora.cuotas_vencidas === 1 ? '' : 's'} · ${mora.dias} días`
                            : undefined
                    }
                />
                <CreditMetricTile label={`Cuota ${credito.tipo_pago}`} value={formatMoney(credito.valor_cuota_total)} icon={CalendarClock} />
                <CreditMetricTile label="Valor prestado" value={formatMoney(credito.valor_prestamo)} icon={Banknote} />
                <CreditMetricTile label="Total a pagar" value={formatMoney(credito.valor_total_con_otros)} icon={Receipt} />
            </div>

            <CreditInstallmentsChartCard cuotas={credito.cuotas ?? []} />

            <div className="grid grid-cols-1 gap-4 md:gap-6 xl:grid-cols-3">
                {/* Desde `xl` la tarjeta va en absoluto: el alto de la fila lo pone la columna lateral y la tabla se estira a él sin encogerla. */}
                <div className="relative min-w-0 xl:col-span-2">
                    <div className="xl:absolute xl:inset-0">
                        <CreditInstallmentsTable cuotas={credito.cuotas ?? []} saldoCapitalTeoricoPorCuota={resumenCuotas.saldo_capital_teorico_por_cuota} />
                    </div>
                </div>

                <div className="flex min-w-0 flex-col gap-4 md:gap-6">
                    <CreditCapitalRecoveryCard porcentaje={porcentajeCapitalRecuperado} resumenCuotas={resumenCuotas} />
                    {credito.cliente && (
                        <CreditConditionsCard
                            cliente={credito.cliente}
                            creditoOrigenId={credito.credito_origen_id}
                            tasaInteres={credito.tasa_interes}
                            tasaEfectivaAnual={credito.tasa_efectiva_anual ?? ''}
                            plazo={credito.plazo}
                            tipoPago={credito.tipo_pago}
                            fechaDesembolso={credito.fecha_desembolso}
                            cuotas={credito.cuotas ?? []}
                        />
                    )}
                </div>
            </div>

            <ConfirmModal
                isOpen={accionPendiente !== null}
                onClose={() => setAccionPendiente(null)}
                onConfirm={() => void confirmarAccion()}
                variant={accionPendiente ? CREDIT_TRANSITION_ACTION_CONFIG[accionPendiente].variant : 'success'}
                title={accionPendiente ? CREDIT_TRANSITION_ACTION_CONFIG[accionPendiente].titulo : ''}
                description={accionPendiente ? `¿Seguro que quieres ${CREDIT_TRANSITION_ACTION_CONFIG[accionPendiente].verbo} este crédito?` : ''}
                confirmLabel={accionPendiente ? CREDIT_TRANSITION_ACTION_CONFIG[accionPendiente].titulo.split(' ')[0] : ''}
                isConfirming={isConfirming}
            />

            <ConfirmModal
                isOpen={showDeleteConfirm}
                onClose={() => setShowDeleteConfirm(false)}
                onConfirm={() => void confirmarEliminar()}
                variant="danger"
                title="Eliminar crédito"
                description={
                    pagos.length > 0
                        ? `¿Seguro que quieres eliminar el crédito #${credito.id}? Primero se reversarán sus ${pagos.length} pago(s) registrado(s) (quedan en la bitácora, no se borran) y, si estaba desembolsado, su comprobante contable. Esta acción NO se puede revertir desde aquí.`
                        : `¿Seguro que quieres eliminar el crédito #${credito.id}? Esta acción NO se puede revertir.`
                }
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
            />
        </div>
    );
};
