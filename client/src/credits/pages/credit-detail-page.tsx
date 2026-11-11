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
import { CreditCapitalRecoveryCard } from '../components/credit-capital-recovery-card';
import { CreditInfoGrid } from '../components/credit-info-grid';
import { CreditInstallmentsChartCard } from '../components/credit-installments-chart-card';
import { CreditInstallmentsTable } from '../components/credit-installments-table';
import { CreditSummaryCards } from '../components/credit-summary-cards';
import { RefinancingNotice } from '../components/refinancing-notice';
import { CREDIT_TRANSITION_ACTION_CONFIG } from '../constants/credit-transition-action-config';
import { puedeCancelarCredito } from '../utils/can-cancel-credit';
import type { ApiCreditDetail } from '../types/api-credit-detail';
import type { CreditTransitionAction } from '../types/credit-transition-action';
import { CalendarClock, CircleDollarSign, FileText, HandCoins, Receipt, SlidersHorizontal } from 'lucide-react';

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
    const valorPrestamoNumerico = parseFloat(credito.valor_prestamo);
    const porcentajeCapitalRecuperado =
        valorPrestamoNumerico > 0 ? (Number(resumenCuotas.saldo_capital_pagado) / valorPrestamoNumerico) * 100 : 0;

    return (
        <div className="flex min-h-full flex-col pb-6">
            <PageBreadcrumb pageTitle={`Crédito de ${credito.cliente?.tercero.nombre ?? '—'}`} />

            {refinanciacion && <RefinancingNotice vinculo={refinanciacion} valorPrestamo={credito.valor_prestamo} className="mb-6" />}

            <CreditSummaryCards valorPrestamo={credito.valor_prestamo} valorTotal={credito.valor_total_con_otros} saldo={saldo} cartera={cartera} mora={mora} />

            <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
                <CreditInstallmentsChartCard cuotas={credito.cuotas ?? []} />
                <CreditCapitalRecoveryCard porcentaje={porcentajeCapitalRecuperado} resumenCuotas={resumenCuotas} />
            </div>

            <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
                <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Datos del crédito</h2>
                <CreditInfoGrid credito={credito} />

                {accionError && (
                    <div className="mt-6">
                        <Alert variant="danger" title="Error" message={accionError} onDismiss={() => setAccionError(null)} />
                    </div>
                )}

                {deleteError && (
                    <div className="mt-6">
                        <Alert variant="danger" title="Error" message={deleteError} onDismiss={() => setDeleteError(null)} />
                    </div>
                )}

                <div className="mt-6 flex flex-wrap gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                    {(credito.estado === 'solicitado' || credito.estado === 'aprobado') && canEditar && (
                        <Button iconOnly startIcon={<SlidersHorizontal className="size-4" />} variant="outline" onClick={() => navigate(`/creditos/${credito.id}/editar`)}>
                            Modificar condiciones
                        </Button>
                    )}
                    {credito.estado === 'solicitado' && canAprobar && (
                        <Button onClick={() => setAccionPendiente('aprobar')}>Aprobar</Button>
                    )}
                    {credito.estado === 'aprobado' && canDesembolsar && (
                        <Button onClick={() => setAccionPendiente('desembolsar')}>Desembolsar</Button>
                    )}
                    {canCancelar && puedeCancelarCredito(credito, pagos.length > 0) && (
                        <Button variant="outline" onClick={() => setAccionPendiente('cancelar')}>
                            Cancelar
                        </Button>
                    )}
                    {canEliminar && pagos.length === 0 && (
                        <Button variant="outline" onClick={() => setShowDeleteConfirm(true)}>
                            Eliminar
                        </Button>
                    )}
                    {credito.estado === 'desembolsado' && canRegisterPayment && (
                        <>
                            <Button iconOnly startIcon={<HandCoins className="size-4" />} onClick={() => navigate(`/pagos/creditos/${credito.id}/nuevo`)}>Pagar cuota</Button>
                            <Button iconOnly startIcon={<CircleDollarSign className="size-4" />} variant="outline" onClick={() => navigate(`/pagos/creditos/${credito.id}/liquidar`)}>
                                Liquidar crédito
                            </Button>
                        </>
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
                description={`¿Seguro que quieres eliminar el crédito #${credito.id}? Esta acción NO se puede revertir.`}
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
            />

            <CreditInstallmentsTable cuotas={credito.cuotas ?? []} saldoCapitalTeoricoPorCuota={resumenCuotas.saldo_capital_teorico_por_cuota} />
        </div>
    );
};
