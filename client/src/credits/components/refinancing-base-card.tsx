import type { FC } from 'react';

import { Spinner } from '@ui/spinner/components/spinner';
import { formatMoney } from '../../shared/utils/format-money';
import type { RefinancingBaseCardProps } from '../props/refinancing-base-card-props';

export const RefinancingBaseCard: FC<RefinancingBaseCardProps> = ({ saldoTomado, valorAdicional, isLoading }) => {
    const valorNuevoCredito = saldoTomado === null ? null : Number(saldoTomado) + (Number(valorAdicional) || 0);

    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 col-span-full dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Saldo de capital del crédito original (se toma como base)</p>

            {isLoading ? (
                <div className="mt-2">
                    <Spinner size="sm" />
                </div>
            ) : saldoTomado === null ? (
                <p className="mt-1 text-sm text-gray-400 dark:text-gray-500">Selecciona el crédito que se refinancia.</p>
            ) : (
                <>
                    <p className="mt-1 text-title-sm font-semibold text-gray-800 dark:text-white/90">{formatMoney(saldoTomado)}</p>
                    <div className="mt-3 flex items-center justify-between gap-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                        <p className="text-sm text-gray-500 dark:text-gray-400">Valor del nuevo crédito</p>
                        <p className="text-sm font-semibold text-brand-600 dark:text-brand-400">{formatMoney(valorNuevoCredito ?? 0)}</p>
                    </div>
                </>
            )}
        </div>
    );
};
