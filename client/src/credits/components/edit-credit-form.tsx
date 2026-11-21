import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { Eye } from 'lucide-react';
import { PAYMENT_TYPE_OPTIONS } from '../constants/payment-type-options';
import { annualRateOfCredit } from '../utils/annual-rate-of-credit';
import { monthlyRateFromAnnual } from '../utils/monthly-rate-from-annual';
import type { EditCreditFormProps } from '../props/edit-credit-form-props';
import type { ApiCreditPreview } from '../types/api-credit-preview';
import type { PaymentType } from '../types/payment-type';
import { CreditFormGuide } from './credit-form-guide';
import { CreditSimulationView } from './credit-simulation-view';
import { RefinancingBaseCard } from './refinancing-base-card';

const PASO_PARAM = 'paso';
const PASO_SIMULACION = 'simulacion';

export const EditCreditForm: FC<EditCreditFormProps> = ({ credito }) => {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const { sendRequest } = useSendRequest();

    const esRefinanciacion = credito.tipo_credito === 'refinanciacion';
    const valorInicial = esRefinanciacion && credito.saldo_refinanciado !== null
        ? (Number(credito.valor_prestamo) - Number(credito.saldo_refinanciado)).toFixed(2)
        : credito.valor_prestamo;

    const [valor, setValor] = useState(valorInicial);
    const [tasaEfectivaAnual, setTasaEfectivaAnual] = useState(() => annualRateOfCredit(credito));
    const tasaSinCambios = tasaEfectivaAnual === annualRateOfCredit(credito);
    const [gastosAdministracion, setGastosAdministracion] = useState(credito.gastos_administracion_pct);
    const [plazo, setPlazo] = useState(String(credito.plazo));
    const [tipoPago, setTipoPago] = useState<PaymentType>(credito.tipo_pago);
    const [fechaDesembolso, setFechaDesembolso] = useState(credito.fecha_desembolso);
    const [fechaPrimerVencimiento, setFechaPrimerVencimiento] = useState(credito.cuotas?.[0]?.fecha_vencimiento ?? '');
    const [motivo, setMotivo] = useState('');

    const [error, setError] = useState<string | null>(null);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [preview, setPreview] = useState<ApiCreditPreview | null>(null);

    // Igual que al crear: la simulación vive en `?paso=simulacion` y el formulario sigue montado con sus datos.
    const enSimulacion = preview !== null && searchParams.get(PASO_PARAM) === PASO_SIMULACION;

    const buildPayload = () => ({
        ...(esRefinanciacion ? { valor_adicional: valor } : { valor_prestamo: valor }),
        // Sin tocar la tasa, un crédito anterior a la EA conserva su mensual exacta en vez de pasar por la conversión.
        ...(tasaSinCambios && credito.tasa_efectiva_anual === null ? { tasa_interes: credito.tasa_interes } : { tasa_efectiva_anual: tasaEfectivaAnual }),
        gastos_administracion_pct: gastosAdministracion,
        plazo,
        tipo_pago: tipoPago,
        fecha_desembolso: fechaDesembolso,
        fecha_primer_vencimiento: fechaPrimerVencimiento || null,
    });

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsPreviewing(true);

        try {
            setPreview(await sendRequest<ApiCreditPreview>(`/creditos/${credito.id}/previsualizar`, { method: 'POST', body: buildPayload() }));
            setSearchParams({ [PASO_PARAM]: PASO_SIMULACION });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsPreviewing(false);
        }
    };

    const handleConfirmarEdicion = async () => {
        setIsSaving(true);

        try {
            await sendRequest(`/creditos/${credito.id}`, { method: 'PATCH', body: { ...buildPayload(), motivo } });

            navigate(`/creditos/${credito.id}`, {
                replace: true,
                state: { message: 'Condiciones actualizadas y cuotas regeneradas correctamente.' },
            });
        } catch (caught) {
            setPreview(null);
            setSearchParams({}, { replace: true });
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <>
            <div className={enSimulacion ? 'hidden' : 'grid grid-cols-1 gap-6 xl:grid-cols-3'}>
                <form onSubmit={handleSubmit} className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 xl:col-span-2 dark:border-gray-800 dark:bg-white/3">
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        {error && (
                            <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 md:col-span-2 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                                {error}
                            </div>
                        )}

                        {esRefinanciacion && (
                            <RefinancingBaseCard saldoTomado={credito.saldo_refinanciado} valorAdicional={valor} isLoading={false} />
                        )}

                        <div>
                            <Label htmlFor="valor-prestamo">{esRefinanciacion ? 'Nuevo valor a prestar (puede ser 0)' : 'Valor del préstamo'}</Label>
                            <Input
                                id="valor-prestamo"
                                name="valor-prestamo"
                                type="number"
                                step={0.01}
                                min={esRefinanciacion ? '0' : '1'}
                                required
                                value={valor}
                                onChange={(event) => setValor(event.target.value)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="tasa-interes">Tasa de interés (% EA)</Label>
                            <Input
                                id="tasa-interes"
                                name="tasa-interes"
                                type="number"
                                step={0.0001}
                                min="0"
                                required
                                value={tasaEfectivaAnual}
                                onChange={(event) => setTasaEfectivaAnual(event.target.value)}
                                hint={monthlyRateFromAnnual(tasaEfectivaAnual) ? `Equivale a ${monthlyRateFromAnnual(tasaEfectivaAnual)} % mensual` : undefined}
                            />
                        </div>

                        <div>
                            <Label htmlFor="gastos-administracion">Gastos de administración (% Mensual)</Label>
                            <Input
                                id="gastos-administracion"
                                name="gastos-administracion"
                                type="number"
                                step={0.001}
                                min="0"
                                max="100"
                                value={gastosAdministracion}
                                onChange={(event) => setGastosAdministracion(event.target.value)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="plazo">Plazo (número de cuotas)</Label>
                            <Input
                                id="plazo"
                                name="plazo"
                                type="number"
                                min="1"
                                required
                                value={plazo}
                                onChange={(event) => setPlazo(event.target.value)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="tipo-pago">Tipo de pago</Label>
                            <Select id="tipo-pago" options={PAYMENT_TYPE_OPTIONS} defaultValue={tipoPago} onChange={(value) => setTipoPago(value as PaymentType)} />
                        </div>

                        <div>
                            <Label htmlFor="fecha-desembolso">Fecha de desembolso</Label>
                            <DatePicker id="fecha-desembolso" name="fecha-desembolso" required value={fechaDesembolso} onChange={setFechaDesembolso} />
                        </div>

                        <div>
                            <Label htmlFor="fecha-primer-vencimiento">Primer vencimiento (opcional)</Label>
                            <DatePicker
                                id="fecha-primer-vencimiento"
                                name="fecha-primer-vencimiento"
                                value={fechaPrimerVencimiento}
                                onChange={setFechaPrimerVencimiento}
                            />
                        </div>

                        <div className="md:col-span-2">
                            <Label htmlFor="motivo-modificacion">Motivo de la modificación</Label>
                            <textarea
                                id="motivo-modificacion"
                                required
                                rows={3}
                                maxLength={500}
                                value={motivo}
                                onChange={(event) => setMotivo(event.target.value)}
                                className="h-auto w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                            />
                        </div>

                        <div className="flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:justify-end md:col-span-2 dark:border-gray-800">
                            <Button variant="outline" onClick={() => navigate(`/creditos/${credito.id}`)} disabled={isPreviewing}>
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                disabled={isPreviewing || motivo.trim() === ''}
                                startIcon={isPreviewing ? <Spinner size="sm" color="white" /> : undefined}
                                endIcon={isPreviewing ? undefined : <Eye className="size-4" />}
                            >
                                {isPreviewing ? 'Calculando…' : 'Previsualizar cuotas'}
                            </Button>
                        </div>
                    </div>
                </form>

                <CreditFormGuide tipoPago={tipoPago} />
            </div>

            {enSimulacion && preview && credito.cliente && (
                <CreditSimulationView
                    preview={preview}
                    cliente={credito.cliente}
                    creditoOrigenId={credito.credito_origen_id}
                    tasaInteres={preview.tasa_interes}
                    tasaEfectivaAnual={tasaSinCambios && credito.tasa_efectiva_anual === null ? '' : tasaEfectivaAnual}
                    plazo={Number(plazo)}
                    tipoPago={tipoPago}
                    fechaDesembolso={fechaDesembolso}
                    modo="editar"
                    isCreating={isSaving}
                    onConfirm={handleConfirmarEdicion}
                    onBack={() => navigate(-1)}
                />
            )}
        </>
    );
};
