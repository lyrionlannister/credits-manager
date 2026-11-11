import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { Save } from 'lucide-react';
import { PAYMENT_TYPE_OPTIONS } from '../constants/payment-type-options';
import type { EditCreditFormProps } from '../props/edit-credit-form-props';
import type { PaymentType } from '../types/payment-type';
import { RefinancingBaseCard } from './refinancing-base-card';

export const EditCreditForm: FC<EditCreditFormProps> = ({ credito }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();

    const esRefinanciacion = credito.tipo_credito === 'refinanciacion';
    const valorInicial = esRefinanciacion && credito.saldo_refinanciado !== null
        ? (Number(credito.valor_prestamo) - Number(credito.saldo_refinanciado)).toFixed(2)
        : credito.valor_prestamo;

    const [valor, setValor] = useState(valorInicial);
    const [tasaInteres, setTasaInteres] = useState(credito.tasa_interes);
    const [gastosAdministracion, setGastosAdministracion] = useState(credito.gastos_administracion_pct);
    const [plazo, setPlazo] = useState(String(credito.plazo));
    const [tipoPago, setTipoPago] = useState<PaymentType>(credito.tipo_pago);
    const [fechaDesembolso, setFechaDesembolso] = useState(credito.fecha_desembolso);
    const [fechaPrimerVencimiento, setFechaPrimerVencimiento] = useState(credito.cuotas?.[0]?.fecha_vencimiento ?? '');
    const [motivo, setMotivo] = useState('');

    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await sendRequest(`/creditos/${credito.id}`, {
                method: 'PATCH',
                body: {
                    ...(esRefinanciacion ? { valor_adicional: valor } : { valor_prestamo: valor }),
                    tasa_interes: tasaInteres,
                    gastos_administracion_pct: gastosAdministracion,
                    plazo,
                    tipo_pago: tipoPago,
                    fecha_desembolso: fechaDesembolso,
                    fecha_primer_vencimiento: fechaPrimerVencimiento || null,
                    motivo,
                },
            });

            navigate(`/creditos/${credito.id}`, {
                replace: true,
                state: { message: 'Condiciones actualizadas y cuotas regeneradas correctamente.' },
            });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                {error && (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 md:col-span-3 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
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
                    <Label htmlFor="tasa-interes">Tasa de interés (% por periodo)</Label>
                    <Input
                        id="tasa-interes"
                        name="tasa-interes"
                        type="number"
                        step={0.001}
                        min="0"
                        required
                        value={tasaInteres}
                        onChange={(event) => setTasaInteres(event.target.value)}
                    />
                </div>

                <div>
                    <Label htmlFor="gastos-administracion">Gastos de administración (%)</Label>
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

                <div className="md:col-span-3">
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

                <p className="text-sm text-gray-500 md:col-span-3 dark:text-gray-400">
                    Al guardar se recalculan las cuotas con las nuevas condiciones.
                </p>

                <div className="flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:justify-end md:col-span-3 dark:border-gray-800">
                    <Button variant="outline" onClick={() => navigate(`/creditos/${credito.id}`)} disabled={isSubmitting}>
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        disabled={isSubmitting || motivo.trim() === ''}
                        startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : <Save className="size-4" />}
                    >
                        {isSubmitting ? 'Guardando…' : 'Guardar y regenerar cuotas'}
                    </Button>
                </div>
            </div>
        </form>
    );
};
