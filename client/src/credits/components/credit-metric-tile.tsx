import type { FC } from 'react';

import { CREDIT_METRIC_TONE_CLASSES } from '../constants/credit-metric-tone-classes';
import type { CreditMetricTileProps } from '../props/credit-metric-tile-props';

/** Métrica compacta (dos por fila desde móvil): deja el plan de cuotas a la vista sin recorrer tarjetas altas. */
export const CreditMetricTile: FC<CreditMetricTileProps> = ({ label, value, icon: Icon, tone = 'default', detail }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 md:p-5 dark:border-gray-800 dark:bg-white/3">
        <div className="flex items-center gap-2 text-gray-500 dark:text-gray-400">
            <Icon className={`size-4 shrink-0 ${CREDIT_METRIC_TONE_CLASSES[tone].icono}`} />
            <span className="truncate text-theme-xs md:text-sm">{label}</span>
        </div>
        <p className={`mt-2 text-base font-semibold wrap-break-word md:text-theme-xl ${CREDIT_METRIC_TONE_CLASSES[tone].valor}`}>{value}</p>
        {detail && <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">{detail}</p>}
    </div>
);
