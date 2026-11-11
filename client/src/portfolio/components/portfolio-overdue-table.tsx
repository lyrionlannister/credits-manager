import type { FC } from 'react';

import { formatMoney } from '../../shared/utils/format-money';
import { ActionButton } from '@ui/action-button/components/action-button';
import { Button } from '@ui/button/components/button';
import { DataTable } from '@ui/table/components/data-table';
import type { PortfolioOverdueTableProps } from '../props/portfolio-overdue-table-props';
import { Briefcase, Eye } from 'lucide-react';

export const PortfolioOverdueTable: FC<PortfolioOverdueTableProps> = ({ entradas, onView, onOpenEmployerReport }) => (
    <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
        <div className="mb-4 flex items-center justify-between">
            <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">En mora</h2>
            <Button iconOnly startIcon={<Briefcase className="size-4" />} variant="outline" onClick={onOpenEmployerReport}>
                Reporte por empleador
            </Button>
        </div>

        <DataTable
            columns={[
                { key: 'cliente', label: 'Cliente', render: (row) => row.credito.cliente?.tercero.nombre ?? '—' },
                { key: 'telefono', label: 'Teléfono', render: (row) => row.credito.cliente?.tercero.celular ?? '—' },
                { key: 'saldo', label: 'Saldo', render: (row) => formatMoney(row.saldo) },
                {
                    key: 'monto_vencido',
                    label: 'Vencido',
                    render: (row) => (
                        <span className="font-medium text-error-600 dark:text-error-400">
                            {formatMoney(row.monto_vencido ?? '0')}
                            <span className="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">({row.cuotas_vencidas ?? 0} cuotas)</span>
                        </span>
                    ),
                },
                {
                    key: 'dias_mora',
                    label: 'Días en mora',
                    render: (row) => (
                        <span className="rounded-full bg-error-50 px-2.5 py-0.5 text-xs font-medium text-error-600 dark:bg-error-500/15 dark:text-error-400">
                            {row.dias_mora} días
                        </span>
                    ),
                },
                {
                    key: 'acciones',
                    label: '',
                    render: (row) => (
                        <div className="text-right">
                            <ActionButton icon={<Eye className="size-5" />} label="Ver" onClick={() => onView(row.credito.id)} />
                        </div>
                    ),
                },
            ]}
            rows={entradas}
            getRowKey={(row) => row.credito.id}
            emptyMessage="No hay créditos en mora."
            maxHeightClassName="max-h-[65vh]"
        />
    </div>
);
