import type { FC } from 'react';
import { useParams, useNavigate } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { useLocationSuccessMessage } from '../../shared/hooks/use-location-success-message';
import { usePermission } from '../../auth/hooks/use-permission';
import { CreditSummaryCards } from '../../credits/components/credit-summary-cards';
import type { ApiCreditDetail } from '../../credits/types/api-credit-detail';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import { PaymentHistoryTable } from '../components/payment-history-table';
import { CircleDollarSign, Eye, HandCoins } from 'lucide-react';

export const CreditPaymentsPage: FC = () => {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const canRegisterPayment = usePermission('pagos', 'crear');

    const [successMessage, setSuccessMessage] = useLocationSuccessMessage<{ message?: string }>();
    const { data: detalle, error } = useResource<ApiCreditDetail>(`/creditos/${id}`);

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

    const { credito, saldo, cartera, mora, pagos } = detalle;

    return (
        <div className="flex min-h-full flex-col pb-6">
            <PageBreadcrumb pageTitle={`Pagos — ${credito.cliente?.tercero.nombre ?? '—'}`} />

            {successMessage && (
                <div className="mb-6">
                    <Alert variant="success" title="Listo" message={successMessage} onDismiss={() => setSuccessMessage(null)} />
                </div>
            )}

            <CreditSummaryCards valorPrestamo={credito.valor_prestamo} valorTotal={credito.valor_total_con_otros} saldo={saldo} cartera={cartera} mora={mora} />

            <div className="mb-6 flex flex-wrap gap-3 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
                {credito.estado === 'desembolsado' && canRegisterPayment && (
                    <>
                        <Button iconOnly startIcon={<HandCoins className="size-4" />} onClick={() => navigate(`/pagos/creditos/${id}/nuevo`)}>Pagar cuota</Button>
                        <Button iconOnly startIcon={<CircleDollarSign className="size-4" />} variant="outline" onClick={() => navigate(`/pagos/creditos/${id}/liquidar`)}>
                            Liquidar crédito
                        </Button>
                    </>
                )}
                <Button iconOnly startIcon={<Eye className="size-4" />} variant="outline" onClick={() => navigate(`/creditos/${id}`)}>
                    Ver crédito
                </Button>
            </div>

            <PaymentHistoryTable pagos={pagos} />
        </div>
    );
};
