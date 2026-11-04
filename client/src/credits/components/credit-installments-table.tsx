import type { FC } from 'react';

import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { DataTable } from '@ui/table/components/data-table';
import { INSTALLMENT_STATUS_CLASSES, INSTALLMENT_STATUS_LABEL } from '../constants/installment-status-styles';
import type { CreditInstallmentsTableProps } from '../props/credit-installments-table-props';

export const CreditInstallmentsTable: FC<CreditInstallmentsTableProps> = ({ cuotas, saldoCapitalTeoricoPorCuota }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-6 print:border-0 print:p-0 dark:border-gray-800 dark:bg-white/3">
        <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Cuotas</h2>
        <DataTable
            columns={[
                { key: 'numero_cuota', label: '#' },
                { key: 'fecha_vencimiento', label: 'Vencimiento', render: (row) => formatDateEs(row.fecha_vencimiento) },
                { key: 'valor_capital', label: 'Capital', render: (row) => formatMoney(row.valor_capital) },
                { key: 'valor_interes', label: 'Intereses', render: (row) => formatMoney(row.valor_interes) },
                { key: 'valor_otros_gastos', label: 'Otros gastos', render: (row) => formatMoney(row.valor_otros_gastos) },
                { key: 'valor_total_cuota', label: 'Total de la cuota', render: (row) => formatMoney(row.valor_total_cuota) },
                {
                    key: 'saldo_capital',
                    label: 'Saldo capital',
                    render: (row) => formatMoney(saldoCapitalTeoricoPorCuota[row.numero_cuota] ?? 0),
                },
                { key: 'valor_pagado', label: 'Pagado', render: (row) => formatMoney(row.valor_pagado) },
                {
                    key: 'estado',
                    label: 'Estado',
                    render: (row) => (
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${INSTALLMENT_STATUS_CLASSES[row.estado]}`}>
                            {INSTALLMENT_STATUS_LABEL[row.estado]}
                        </span>
                    ),
                },
            ]}
            rows={cuotas}
            getRowKey={(row) => row.id}
            maxHeightClassName="max-h-[65vh]"
        />
    </div>
);
