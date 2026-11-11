import type { FC } from 'react';
import { useState } from 'react';

import { useAuth } from '../../auth/hooks/use-auth';
import { usePermission } from '../../auth/hooks/use-permission';
import { hasAnyPermission } from '../../auth/utils/has-any-permission';
import { ExportButton } from '../../shared/components/export-button';
import { Alert } from '@ui/alert/components/alert';
import type { ReportExportButtonProps } from '../props/report-export-button-props';

/**
 * Exportación de un reporte contable. Excel y CSV requieren el permiso de exportar de ese informe
 * (`informes_contables.exportar_<informe>`); el PDF, `informes_contables.imprimir`. Sin ninguno se
 * muestra qué permiso falta en vez de ocultar el botón sin más (Fase 7).
 */
export const ReportExportButton: FC<ReportExportButtonProps> = ({ endpoint, exportPermissions, pdfEndpoint, pdfFilename, filters, disabled = false }) => {
    const { user } = useAuth();
    const canExport = hasAnyPermission(user, exportPermissions);
    const canPrint = usePermission('informes_contables', 'imprimir');
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    if (!canExport && !canPrint) {
        return (
            <p className="text-right text-sm text-gray-500 dark:text-gray-400">
                {`Para exportar este informe necesitas el permiso ${exportPermissions.join(' o ')} (y para el PDF, informes_contables.imprimir). Pídeselo a un administrador.`}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex justify-end">
                <ExportButton
                    endpoint={endpoint}
                    filters={filters}
                    formats={canExport ? ['xlsx', 'csv'] : []}
                    pdfEndpoint={canPrint ? pdfEndpoint : undefined}
                    pdfFilename={pdfFilename}
                    disabled={disabled}
                    onRequested={() => {
                        setError(null);
                        setMessage('Exportación solicitada. Se genera en segundo plano y te avisaremos cuando esté lista para descargar.');
                    }}
                    onError={(errorMessage) => {
                        setMessage(null);
                        setError(errorMessage);
                    }}
                />
            </div>

            {message && <Alert variant="success" title="Listo" message={message} onDismiss={() => setMessage(null)} />}
            {error && <Alert variant="danger" title="No se pudo exportar" message={error} onDismiss={() => setError(null)} />}
        </div>
    );
};
