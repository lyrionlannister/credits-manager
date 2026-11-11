import type { FC } from 'react';

import { formatMoney } from '../../shared/utils/format-money';
import { CARTERA_STATUS_TEXT_CLASSES } from '../constants/cartera-status-text-classes';
import type { CreditSummaryCardsProps } from '../props/credit-summary-cards-props';

export const CreditSummaryCards: FC<CreditSummaryCardsProps> = ({ valorPrestamo, valorTotal, saldo, cartera, mora }) => (
    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Valor prestado</p>
            <p className="mt-1 text-title-sm font-semibold text-gray-800 dark:text-white/90">{formatMoney(valorPrestamo)}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Valor total a pagar</p>
            <p className="mt-1 text-title-sm font-semibold text-gray-800 dark:text-white/90">{formatMoney(valorTotal)}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">{cartera === 'Refinanciado' ? 'Saldo al refinanciar' : 'Saldo pendiente'}</p>
            <p
                className={`mt-1 text-title-sm font-semibold ${
                    cartera === 'Refinanciado' ? 'text-gray-800 dark:text-white/90' : 'text-brand-600 dark:text-brand-400'
                }`}
            >
                {formatMoney(saldo)}
            </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Estado de cartera</p>
            <p className={`mt-1 text-title-sm font-semibold ${CARTERA_STATUS_TEXT_CLASSES[cartera]}`}>{cartera}</p>
            {mora && mora.cuotas_vencidas > 0 && (
                <p className="mt-1 text-sm text-error-600 dark:text-error-400">
                    {formatMoney(mora.monto_vencido)} vencido · {mora.cuotas_vencidas} cuota{mora.cuotas_vencidas === 1 ? '' : 's'} ·{' '}
                    {mora.dias} días
                </p>
            )}
        </div>
    </div>
);
