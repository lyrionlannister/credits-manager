import type { FC } from 'react';

import { formatMoney } from '../../shared/utils/format-money';
import { RadialGaugeChart } from '@ui/charts/components/radial-gauge-chart';
import type { CreditCapitalRecoveryCardProps } from '../props/credit-capital-recovery-card-props';

export const CreditCapitalRecoveryCard: FC<CreditCapitalRecoveryCardProps> = ({ porcentaje, resumenCuotas }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/3">
        <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Capital recuperado</h3>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Del valor prestado ya cubierto por los pagos.</p>

        <RadialGaugeChart percentage={porcentaje} color="#465fff" />

        <dl className="mt-2 flex flex-col divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
            <div className="flex items-center justify-between gap-4 py-2.5 text-sm">
                <dt className="text-gray-500 dark:text-gray-400">Capital pagado</dt>
                <dd className="font-medium text-success-600 dark:text-success-400">{formatMoney(resumenCuotas.saldo_capital_pagado)}</dd>
            </div>
            <div className="flex items-center justify-between gap-4 py-2.5 text-sm">
                <dt className="text-gray-500 dark:text-gray-400">Capital pendiente</dt>
                <dd className="font-medium text-gray-800 dark:text-white/90">{formatMoney(resumenCuotas.saldo_capital_pendiente)}</dd>
            </div>
            <div className="flex items-center justify-between gap-4 py-2.5 text-sm">
                <dt className="text-gray-500 dark:text-gray-400">Interés pendiente</dt>
                <dd className="font-medium text-gray-800 dark:text-white/90">{formatMoney(resumenCuotas.interes_pendiente)}</dd>
            </div>
        </dl>
    </div>
);
