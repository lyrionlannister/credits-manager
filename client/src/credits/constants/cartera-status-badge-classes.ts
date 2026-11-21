import type { ApiCreditDetail } from '../types/api-credit-detail';

export const CARTERA_STATUS_BADGE_CLASSES: Record<ApiCreditDetail['cartera'], string> = {
    'En mora': 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400',
    'Al día': 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
    Refinanciado: 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-400',
};
