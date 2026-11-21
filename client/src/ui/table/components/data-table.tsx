import type { ReactElement } from 'react';

import { ChevronDown } from 'lucide-react';
import { TableSkeletonRows } from '@ui/skeleton/components/table-skeleton-rows';
import type { DataTableProps } from '../props/data-table-props';

export function DataTable<T>({
    columns,
    rows,
    getRowKey,
    sortKey = null,
    sortDirection = 'asc',
    onSortChange,
    loading = false,
    loadingMessage = 'Cargando…',
    emptyMessage = 'No hay resultados.',
    maxHeightClassName,
    className,
}: DataTableProps<T>): ReactElement {
    return (
        <div className={className}>
            <div className={`custom-scrollbar overflow-x-auto ${maxHeightClassName ? `${maxHeightClassName} overflow-y-auto` : ''}`}>
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-gray-100 dark:border-gray-800">
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    className={`px-4 py-3 text-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400 ${
                                        maxHeightClassName ? 'sticky top-0 z-10 bg-white dark:bg-gray-900' : ''
                                    }`}
                                >
                                    {column.sortable ? (
                                        <button
                                            type="button"
                                            onClick={() => onSortChange?.(column.key)}
                                            className="flex items-center gap-1"
                                        >
                                            {column.label}
                                            <ChevronDown
                                                className={`size-3 transition-transform ${
                                                    sortKey === column.key ? 'text-brand-500' : 'text-gray-400'
                                                } ${sortKey === column.key && sortDirection === 'desc' ? 'rotate-180' : ''}`}
                                            />
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {/* Mientras carga, filas esqueleto con las mismas columnas que la tabla real. */}
                        {loading && <TableSkeletonRows columns={columns.length} />}
                        {!loading &&
                            rows.map((row) => (
                                <tr key={getRowKey(row)} className="border-b border-gray-100 dark:border-gray-800">
                                    {columns.map((column) => (
                                        <td key={column.key} className="px-4 py-3 text-gray-700 dark:text-gray-300">
                                            {column.render
                                                ? column.render(row)
                                                : String((row as Record<string, unknown>)[column.key] ?? '')}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>

            {/* Fuera del scroll horizontal: dentro de la tabla el texto se centraría en el ancho total y quedaría cortado en móvil. */}
            {loading ? (
                <p role="status" className="sr-only">
                    {loadingMessage}
                </p>
            ) : rows.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">{emptyMessage}</p>
            ) : null}
        </div>
    );
}
