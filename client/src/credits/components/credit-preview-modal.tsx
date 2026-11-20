import type { FC } from 'react';

import { Download, Save } from 'lucide-react';
import { useDownloadFile } from '../../shared/hooks/use-download-file';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { Modal } from '@ui/modal/components/modal';
import { Spinner } from '@ui/spinner/components/spinner';
import { CreditCapitalRecoveryCard } from './credit-capital-recovery-card';
import { CreditInfoGrid } from './credit-info-grid';
import { CreditInstallmentsChartCard } from './credit-installments-chart-card';
import { CreditInstallmentsTable } from './credit-installments-table';
import { CreditSummaryCards } from './credit-summary-cards';
import type { ApiResumenCuotas } from '../types/api-resumen-cuotas';
import type { CreditPreviewModalProps } from '../props/credit-preview-modal-props';

/** Vista previa del crédito con los mismos bloques que su página de detalle, sin persistir nada. */
export const CreditPreviewModal: FC<CreditPreviewModalProps> = ({
    preview,
    cliente,
    creditoOrigenId,
    tasaInteres,
    tasaEfectivaAnual,
    plazo,
    tipoPago,
    fechaDesembolso,
    pdfPayload,
    modo = 'crear',
    isCreating,
    onConfirm,
    onClose,
}) => {
    const { download, isDownloading, error: downloadError } = useDownloadFile();

    // Nada se ha pagado todavía: las cuotas aparecen como recién generadas, igual que al crear el crédito de verdad.
    const cuotas = preview.cuotas.map((cuota) => ({
        ...cuota,
        id: cuota.numero_cuota,
        credito_id: 0,
        valor_pagado: '0.00',
        estado: 'pendiente' as const,
    }));

    const saldoCapitalTeoricoPorCuota = Object.fromEntries(preview.cuotas.map((cuota) => [cuota.numero_cuota, cuota.saldo_capital]));

    const resumenCuotas: ApiResumenCuotas = {
        interes_pendiente: preview.interes_total,
        saldo_capital_pendiente: preview.valor_prestamo,
        saldo_capital_pagado: '0.00',
        saldo_capital_teorico_por_cuota: saldoCapitalTeoricoPorCuota,
    };

    return (
        <Modal isOpen onClose={onClose} size="xl">
            <div className="flex flex-col gap-6">
                <div>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Previsualización del crédito</h2>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {modo === 'crear'
                            ? 'Así se vería el crédito ya creado. No se ha guardado nada todavía.'
                            : 'Así quedarían las cuotas con las nuevas condiciones. Al guardar se reemplazan las actuales.'}
                    </p>
                </div>

                <CreditSummaryCards
                    valorPrestamo={preview.valor_prestamo}
                    valorTotal={preview.valor_total_con_otros}
                    saldo={preview.valor_prestamo}
                    cartera="Al día"
                />

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    <CreditInstallmentsChartCard cuotas={preview.cuotas} />
                    {/* `grid`: así el div extra estira a la tarjeta en ancho y alto igual que si fuera ella la celda del grid padre. */}
                    <div className="grid">
                        <CreditCapitalRecoveryCard porcentaje={0} resumenCuotas={resumenCuotas} />
                    </div>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
                    <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Datos del crédito</h2>
                    <CreditInfoGrid
                        credito={{
                            cliente,
                            tasa_interes: tasaInteres,
                            tasa_efectiva_anual: tasaEfectivaAnual,
                            plazo,
                            tipo_pago: tipoPago,
                            fecha_desembolso: fechaDesembolso,
                            estado: 'solicitado',
                            credito_origen: creditoOrigenId ? { id: creditoOrigenId } : null,
                        }}
                    />
                </div>

                <CreditInstallmentsTable cuotas={cuotas} saldoCapitalTeoricoPorCuota={saldoCapitalTeoricoPorCuota} />

                {downloadError && <Alert variant="danger" title="No se pudo descargar" message={downloadError} />}

                <div className="flex flex-col-reverse justify-end gap-3 border-t border-gray-100 pt-6 sm:flex-row dark:border-gray-800">
                    {pdfPayload && (
                        <Button
                            variant="outline"
                            disabled={isDownloading}
                            startIcon={isDownloading ? <Spinner size="sm" /> : <Download className="size-4" />}
                            onClick={() => download('/creditos/previsualizar/pdf', 'simulacion-credito.pdf', pdfPayload)}
                        >
                            {isDownloading ? 'Generando…' : 'Descargar PDF'}
                        </Button>
                    )}
                    <Button variant="outline" onClick={onClose} disabled={isCreating}>
                        Descartar
                    </Button>
                    <Button
                        onClick={onConfirm}
                        disabled={isCreating}
                        startIcon={isCreating ? <Spinner size="sm" color="white" /> : undefined}
                        endIcon={isCreating ? undefined : <Save className="size-4" />}
                    >
                        {modo === 'crear' ? (isCreating ? 'Creando…' : 'Crear crédito') : isCreating ? 'Guardando…' : 'Guardar y regenerar cuotas'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
};
