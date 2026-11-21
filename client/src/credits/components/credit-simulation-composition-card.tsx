import type { FC } from 'react';

import { centsFromMoneyText } from '../../shared/utils/cents-from-money-text';
import { formatMoney } from '../../shared/utils/format-money';
import { moneyTextFromCents } from '../../shared/utils/money-text-from-cents';
import { DonutChart } from '@ui/charts/components/donut-chart';
import type { CreditSimulationCompositionCardProps } from '../props/credit-simulation-composition-card-props';

export const CreditSimulationCompositionCard: FC<CreditSimulationCompositionCardProps> = ({ preview }) => {
    const totalCentavos = centsFromMoneyText(preview.valor_total_con_otros);
    const gastos = moneyTextFromCents(totalCentavos - centsFromMoneyText(preview.valor_total));

    const partes = [
        { nombre: 'Capital', valor: preview.valor_prestamo, color: '#465fff', punto: 'bg-brand-500' },
        { nombre: 'Intereses', valor: preview.interes_total, color: '#f79009', punto: 'bg-warning-500' },
        { nombre: 'Gastos', valor: gastos, color: '#98a2b3', punto: 'bg-gray-400 dark:bg-gray-500' },
    ].filter((parte) => centsFromMoneyText(parte.valor) > 0n);

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/3">
            <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">¿A dónde va el dinero?</h3>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Composición del total que pagará el cliente.</p>

            <div className="mt-4">
                <DonutChart
                    series={partes.map((parte) => parseFloat(parte.valor))}
                    labels={partes.map((parte) => parte.nombre)}
                    colors={partes.map((parte) => parte.color)}
                    totalLabel="Total a pagar"
                    totalValue={formatMoney(preview.valor_total_con_otros)}
                    valueFormatter={(value) => formatMoney(value)}
                    height={240}
                    showLegend={false}
                />
            </div>

            <ul className="mt-4 flex flex-col gap-3">
                {partes.map((parte) => (
                    <li key={parte.nombre} className="flex items-center justify-between gap-3 text-sm">
                        <span className="flex min-w-0 items-center gap-2 text-gray-500 dark:text-gray-400">
                            <span className={`size-2.5 shrink-0 rounded-full ${parte.punto}`} />
                            <span className="truncate">{parte.nombre}</span>
                        </span>
                        <span className="flex items-baseline gap-2 whitespace-nowrap">
                            <span className="font-medium text-gray-800 dark:text-white/90">{formatMoney(parte.valor)}</span>
                            <span className="text-theme-xs text-gray-400 dark:text-gray-500">
                                {totalCentavos === 0n ? '0' : (Number((centsFromMoneyText(parte.valor) * 1000n) / totalCentavos) / 10).toFixed(1)}%
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
};
