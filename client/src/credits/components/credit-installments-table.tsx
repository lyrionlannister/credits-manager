import type { FC } from 'react';

import { centsFromMoneyText } from '../../shared/utils/cents-from-money-text';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { DataTable } from '@ui/table/components/data-table';
import { INSTALLMENT_STATUS_CLASSES, INSTALLMENT_STATUS_LABEL } from '../constants/installment-status-styles';
import type { CreditInstallmentsTableProps } from '../props/credit-installments-table-props';
import type { InstallmentStatus } from '../types/installment-status';

const BARRA_AVANCE_CLASSES: Record<InstallmentStatus, string> = {
    pagada: 'bg-success-500',
    vencida: 'bg-error-500',
    pendiente: 'bg-brand-500',
};

function porcentajePagado(pagado: string, total: string): number {
    const totalCentavos = centsFromMoneyText(total);

    return totalCentavos === 0n ? 0 : Math.min(100, Number((centsFromMoneyText(pagado) * 100n) / totalCentavos));
}

export const CreditInstallmentsTable: FC<CreditInstallmentsTableProps> = ({ cuotas, saldoCapitalTeoricoPorCuota }) => {
    const pagadas = cuotas.filter((cuota) => cuota.estado === 'pagada').length;
    const proxima = cuotas.find((cuota) => cuota.estado !== 'pagada');

    return (
        <div className="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-5 md:p-6 print:border-0 print:p-0 dark:border-gray-800 dark:bg-white/3">
            <div className="mb-5">
                <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Plan de cuotas</h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {pagadas} de {cuotas.length} cuotas pagadas
                    {proxima && ` · próxima el ${formatDateEs(proxima.fecha_vencimiento)}`}
                </p>
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
                        key: 'valor_pagado',
                        label: 'Pagado',
                        render: (row) => (
                            <div className="flex min-w-28 flex-col gap-1.5">
                                <span className="whitespace-nowrap">{formatMoney(row.valor_pagado)}</span>
                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                    <div
                                        className={`h-full rounded-full ${BARRA_AVANCE_CLASSES[row.estado]}`}
                                        style={{ width: `${porcentajePagado(row.valor_pagado, row.valor_total_cuota)}%` }}
                                    />
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'estado',
                        label: 'Estado',
                        render: (row) => (
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ${INSTALLMENT_STATUS_CLASSES[row.estado]}`}>
                                {INSTALLMENT_STATUS_LABEL[row.estado]}
                            </span>
                        ),
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
                        render: (row) => (
                            <span className="whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {formatMoney(saldoCapitalTeoricoPorCuota[row.numero_cuota] ?? 0)}
                            </span>
                        ),
                    },
                ]}
                rows={cuotas}
                getRowKey={(row) => row.id}
                className="min-h-0 flex-1"
                // Desde `xl` la tarjeta toma el alto de la columna lateral y la tabla llena ese espacio con su propio scroll.
                maxHeightClassName="max-h-[65vh] xl:h-full xl:max-h-none"
            />
        </div>
    );
};
