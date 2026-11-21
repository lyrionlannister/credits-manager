import type { FC } from 'react';
import { useEffect, useRef } from 'react';

import { ArrowLeft, Banknote, CalendarClock, Download, Percent, Save, Wallet } from 'lucide-react';
import { useDownloadFile } from '../../shared/hooks/use-download-file';
import { formatMoney } from '../../shared/utils/format-money';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { Spinner } from '@ui/spinner/components/spinner';
import { CreditConditionsCard } from './credit-conditions-card';
import { CreditMetricTile } from './credit-metric-tile';
import { CreditSimulationCompositionCard } from './credit-simulation-composition-card';
import { CreditSimulationInstallmentsCard } from './credit-simulation-installments-card';
import type { CreditSimulationViewProps } from '../props/credit-simulation-view-props';

/** Simulación del crédito a página completa, sin persistir nada: las acciones quedan fijas arriba mientras se recorre el plan. */
export const CreditSimulationView: FC<CreditSimulationViewProps> = ({
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
    onBack,
}) => {
    const { download, isDownloading, error: downloadError } = useDownloadFile();
    const raizRef = useRef<HTMLDivElement>(null);

    // El formulario queda montado debajo (oculto) y el scroller del layout conserva su posición: se vuelve al inicio.
    useEffect(() => {
        raizRef.current?.scrollIntoView({ block: 'start' });
    }, []);

    const valorCuota = preview.cuotas[0]?.valor_total_cuota ?? preview.valor_cuota;

    return (
        <div ref={raizRef} className="flex flex-col gap-4 pb-6 md:gap-6">
            <div className="sticky top-0 z-20 rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm md:px-6 dark:border-gray-800 dark:bg-gray-900">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex min-w-0 items-center gap-2">
                            <span className="shrink-0 rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-600 dark:bg-warning-500/15 dark:text-orange-400">
                                Simulación
                            </span>
                            <h3 className="truncate font-semibold text-gray-800 dark:text-white/90">{cliente.tercero.nombre}</h3>
                        </div>
                        <p className="mt-1 hidden text-theme-sm text-gray-500 md:block dark:text-gray-400">
                            {modo === 'crear'
                                ? 'Así quedaría el crédito. No se ha guardado nada todavía.'
                                : 'Así quedarían las cuotas con las nuevas condiciones. Al guardar se reemplazan las actuales.'}
                        </p>
                    </div>

                    <div className="flex items-center gap-2 lg:shrink-0">
                        <Button variant="outline" collapseBelow="md" startIcon={<ArrowLeft className="size-4" />} onClick={onBack} disabled={isCreating}>
                            Volver a editar
                        </Button>
                        {pdfPayload && (
                            <Button
                                variant="outline"
                                collapseBelow="md"
                                disabled={isDownloading}
                                startIcon={isDownloading ? <Spinner size="sm" /> : <Download className="size-4" />}
                                onClick={() => download('/creditos/previsualizar/pdf', 'simulacion-credito.pdf', pdfPayload)}
                            >
                                {isDownloading ? 'Generando…' : 'Descargar PDF'}
                            </Button>
                        )}
                        <Button
                            className="flex-1 lg:flex-none"
                            onClick={onConfirm}
                            disabled={isCreating}
                            startIcon={isCreating ? <Spinner size="sm" color="white" /> : undefined}
                            endIcon={isCreating ? undefined : <Save className="size-4" />}
                        >
                            {modo === 'crear' ? (isCreating ? 'Creando…' : 'Crear crédito') : isCreating ? 'Guardando…' : 'Guardar y regenerar cuotas'}
                        </Button>
                    </div>
                </div>

                {downloadError && (
                    <div className="mt-3">
                        <Alert variant="danger" title="No se pudo descargar" message={downloadError} />
                    </div>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 md:gap-6 xl:grid-cols-4">
                <CreditMetricTile label={`Cuota ${tipoPago}`} value={formatMoney(valorCuota)} icon={CalendarClock} tone="brand" />
                <CreditMetricTile label="Valor prestado" value={formatMoney(preview.valor_prestamo)} icon={Banknote} />
                <CreditMetricTile label="Intereses totales" value={formatMoney(preview.interes_total)} icon={Percent} />
                <CreditMetricTile label="Total a pagar" value={formatMoney(preview.valor_total_con_otros)} icon={Wallet} />
            </div>

            <div className="grid grid-cols-1 gap-4 md:gap-6 xl:grid-cols-3">
                <div className="min-w-0 xl:col-span-2">
                    <CreditSimulationInstallmentsCard cuotas={preview.cuotas} tipoPago={tipoPago} />
                </div>

                <div className="flex min-w-0 flex-col gap-4 md:gap-6">
                    <CreditConditionsCard
                        cliente={cliente}
                        creditoOrigenId={creditoOrigenId}
                        tasaInteres={tasaInteres}
                        tasaEfectivaAnual={tasaEfectivaAnual}
                        plazo={plazo}
                        tipoPago={tipoPago}
                        fechaDesembolso={fechaDesembolso}
                        cuotas={preview.cuotas}
                    />
                    <CreditSimulationCompositionCard preview={preview} />
                </div>
            </div>
        </div>
    );
};
