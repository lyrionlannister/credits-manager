import type { FC } from 'react';

import { centsFromMoneyText } from '../../shared/utils/cents-from-money-text';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { DataTable } from '@ui/table/components/data-table';
import type { CreditSimulationInstallmentsCardProps } from '../props/credit-simulation-installments-card-props';

const TIPO_PAGO_PLURAL = { mensual: 'mensuales', quincenal: 'quincenales' } as const;

function porcentajeDe(parte: string, total: string): number {
    const totalCentavos = centsFromMoneyText(total);

    return totalCentavos === 0n ? 0 : Number((centsFromMoneyText(parte) * 10000n) / totalCentavos) / 100;
}

export const CreditSimulationInstallmentsCard: FC<CreditSimulationInstallmentsCardProps> = ({ cuotas, tipoPago }) => {
    const primera = cuotas[0];
    const ultima = cuotas[cuotas.length - 1];

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/3">
            <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Plan de cuotas</h3>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {cuotas.length} cuotas {TIPO_PAGO_PLURAL[tipoPago]}
                        {primera && ultima && ` · del ${formatDateEs(primera.fecha_vencimiento)} al ${formatDateEs(ultima.fecha_vencimiento)}`}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-brand-500" />
                        Capital
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-warning-500" />
                        Interés
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-gray-300 dark:bg-gray-600" />
                        Gastos
                    </span>
                </div>
            </div>

            <DataTable
                columns={[
                    {
                        key: 'numero_cuota',
                        label: '#',
                        render: (row) => (
                            <span className="inline-flex size-7 items-center justify-center rounded-full bg-gray-100 text-theme-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                {row.numero_cuota}
                            </span>
                        ),
                    },
                    {
                        key: 'fecha_vencimiento',
                        label: 'Vencimiento',
                        render: (row) => <span className="whitespace-nowrap">{formatDateEs(row.fecha_vencimiento)}</span>,
                    },
                    {
                        key: 'valor_total_cuota',
                        label: 'Cuota',
                        render: (row) => (
                            <span className="font-semibold whitespace-nowrap text-gray-800 dark:text-white/90">{formatMoney(row.valor_total_cuota)}</span>
                        ),
                    },
                    {
                        key: 'composicion',
                        label: 'Composición',
                        render: (row) => {
                            const capital = porcentajeDe(row.valor_capital, row.valor_total_cuota);
                            const interes = porcentajeDe(row.valor_interes, row.valor_total_cuota);
                            const gastos = Math.max(0, 100 - capital - interes);

                            return (
                                <div
                                    className="flex h-2 w-28 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
                                    title={`Capital ${capital.toFixed(1)}% · Interés ${interes.toFixed(1)}% · Gastos ${gastos.toFixed(1)}%`}
                                >
                                    <span className="h-full bg-brand-500" style={{ width: `${capital}%` }} />
                                    <span className="h-full bg-warning-500" style={{ width: `${interes}%` }} />
                                    <span className="h-full bg-gray-300 dark:bg-gray-600" style={{ width: `${gastos}%` }} />
                                </div>
                            );
                        },
                    },
                    { key: 'valor_capital', label: 'Capital', render: (row) => <span className="whitespace-nowrap">{formatMoney(row.valor_capital)}</span> },
                    { key: 'valor_interes', label: 'Interés', render: (row) => <span className="whitespace-nowrap">{formatMoney(row.valor_interes)}</span> },
                    {
                        key: 'valor_otros_gastos',
                        label: 'Gastos',
                        render: (row) => <span className="whitespace-nowrap">{formatMoney(row.valor_otros_gastos)}</span>,
                    },
                    {
                        key: 'saldo_capital',
                        label: 'Saldo capital',
                        render: (row) => <span className="whitespace-nowrap text-gray-500 dark:text-gray-400">{formatMoney(row.saldo_capital)}</span>,
                    },
                ]}
                rows={cuotas}
                getRowKey={(row) => row.numero_cuota}
                maxHeightClassName="max-h-[65vh]"
            />
        </div>
    );
};
