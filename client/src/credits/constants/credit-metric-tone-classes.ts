import type { CreditMetricTone } from '../types/credit-metric-tone';

export const CREDIT_METRIC_TONE_CLASSES: Record<CreditMetricTone, { icono: string; valor: string }> = {
    default: { icono: '', valor: 'text-gray-800 dark:text-white/90' },
    brand: { icono: 'text-brand-500 dark:text-brand-400', valor: 'text-brand-600 dark:text-brand-400' },
    success: { icono: 'text-success-500 dark:text-success-400', valor: 'text-success-600 dark:text-success-400' },
    error: { icono: 'text-error-500 dark:text-error-400', valor: 'text-error-600 dark:text-error-400' },
};
