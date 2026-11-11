import type { FC } from 'react';
import { useParams } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { FormSkeletonBody } from '@ui/skeleton/components/form-skeleton-body';
import { ReprogramCreditForm } from '../components/reprogram-credit-form';
import type { ApiCreditDetail } from '../types/api-credit-detail';

export const ReprogramCreditPage: FC = () => {
    const { id } = useParams<{ id: string }>();
    const { data: detalle, error } = useResource<ApiCreditDetail>(`/creditos/${id}`);

    const credito = detalle?.credito;

    return (
        <div className="flex min-h-full flex-col pb-6">
            <PageBreadcrumb pageTitle="Reprogramar crédito" />

            <div className="flex-1 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 md:p-8 dark:border-gray-800 dark:bg-white/3">
                {error ? (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                        {error}
                    </div>
                ) : !credito ? (
                    <FormSkeletonBody />
                ) : credito.estado !== 'desembolsado' ? (
                    <div className="rounded-lg border border-warning-500 bg-warning-50 px-4 py-3 text-sm text-warning-600 dark:border-warning-800 dark:bg-warning-500/10 dark:text-orange-400">
                        Solo se reprograma un crédito desembolsado. Antes del desembolso, usa «Modificar condiciones».
                    </div>
                ) : (
                    <ReprogramCreditForm credito={credito} />
                )}
            </div>
        </div>
    );
};
