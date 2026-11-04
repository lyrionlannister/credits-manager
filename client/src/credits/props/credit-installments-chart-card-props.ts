import type { ApiInstallment } from '../types/api-installment';

export type CreditInstallmentsChartCardProps = {
    cuotas: Pick<ApiInstallment, 'numero_cuota' | 'valor_capital' | 'valor_interes'>[];
};
