import type { FC } from 'react';
import { useParams } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import type { ApiCreditDetail } from '../../credits/types/api-credit-detail';
import { SettleCreditForm } from '../components/settle-credit-form';

export const SettleCreditPage: FC = () => {
    const { id } = useParams<{ id: string }>();

    const { data: detalle, error: loadError } = useResource<ApiCreditDetail>(`/creditos/${id}`);

    if (loadError) {
        return (
            <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                {loadError}
            </div>
        );
    }

    if (!detalle) {
        return <PageSkeleton />;
    }

    const { credito } = detalle;

    return (
        <div className="flex min-h-full flex-col pb-6">
            <PageBreadcrumb pageTitle={`Liquidar crédito — ${credito.cliente?.tercero.nombre ?? '—'}`} />

            <div className="rounded-2xl border border-gray-200 bg-white p-6 md:p-8 dark:border-gray-800 dark:bg-white/3">
                <SettleCreditForm credito={credito} />
            </div>
        </div>
    );
};
