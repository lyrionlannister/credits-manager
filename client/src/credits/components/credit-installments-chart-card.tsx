import type { FC } from 'react';

import { formatMoney } from '../../shared/utils/format-money';
import { StackedBarChart } from '@ui/charts/components/stacked-bar-chart';
import type { CreditInstallmentsChartCardProps } from '../props/credit-installments-chart-card-props';

export const CreditInstallmentsChartCard: FC<CreditInstallmentsChartCardProps> = ({ cuotas }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-6 print:border-0 print:p-0 dark:border-gray-800 dark:bg-white/3 xl:col-span-2">
        <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Composición de las cuotas</h2>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Interés y capital de cada cuota, según el plan original.</p>

        <StackedBarChart
            categories={cuotas.map((cuota) => `#${cuota.numero_cuota}`)}
            series={[
                { name: 'Capital', data: cuotas.map((cuota) => parseFloat(cuota.valor_capital)), color: '#465fff' },
                { name: 'Interés', data: cuotas.map((cuota) => parseFloat(cuota.valor_interes)), color: '#f79009' },
            ]}
            valueFormatter={(value) => formatMoney(value)}
        />
    </div>
);
