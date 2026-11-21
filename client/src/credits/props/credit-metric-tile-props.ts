import type { LucideIcon } from 'lucide-react';

import type { CreditMetricTone } from '../types/credit-metric-tone';

export type CreditMetricTileProps = {
    label: string;
    /** Ya formateado (ej. con `formatMoney`). */
    value: string;
    icon: LucideIcon;
    tone?: CreditMetricTone;
    /** Línea secundaria bajo el valor (ej. el detalle de la mora). */
    detail?: string;
};
