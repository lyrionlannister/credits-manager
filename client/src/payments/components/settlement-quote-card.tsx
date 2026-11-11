import type { FC } from 'react';

import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import type { SettlementQuoteCardProps } from '../props/settlement-quote-card-props';

export const SettlementQuoteCard: FC<SettlementQuoteCardProps> = ({ cotizacion }) => (
    <div className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 sm:grid-cols-2 dark:border-gray-800 dark:bg-white/5">
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Capital pendiente</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{formatMoney(cotizacion.capital_pendiente)}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Días transcurridos</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">
                {cotizacion.dias_transcurridos} (del {formatDateEs(cotizacion.fecha_referencia)} al {formatDateEs(cotizacion.fecha_pago)})
            </p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Interés calculado</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{formatMoney(cotizacion.interes)}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Otros gastos</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{formatMoney(cotizacion.otros_gastos)}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Total a pagar</p>
            <p className="mt-1 text-title-sm font-semibold text-brand-600 dark:text-brand-400">{formatMoney(cotizacion.total)}</p>
        </div>
    </div>
);
