import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { Save } from 'lucide-react';
import { SettlementQuoteCard } from './settlement-quote-card';
import { PAYMENT_METHOD_OPTIONS } from '../constants/payment-method-options';
import type { SettleCreditFormProps } from '../props/settle-credit-form-props';
import type { ApiSettlementQuote } from '../types/api-settlement-quote';
import type { PaymentMethod } from '../types/payment-method';

export const SettleCreditForm: FC<SettleCreditFormProps> = ({ credito }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();

    const [formaPago, setFormaPago] = useState<PaymentMethod>('efectivo');
    const [fechaPago, setFechaPago] = useState('');
    const [observacion, setObservacion] = useState('');
    const [valorAcordado, setValorAcordado] = useState('');
    const [motivoDescuento, setMotivoDescuento] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const quoteEndpoint = `/creditos/${credito.id}/liquidacion${fechaPago ? `?fecha_pago=${fechaPago}` : ''}`;
    const { data: cotizacion, error: quoteError } = useResource<ApiSettlementQuote>(quoteEndpoint);
    const conDescuento = cotizacion !== null && valorAcordado !== '' && Number(valorAcordado) < Number(cotizacion.total);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await sendRequest(`/creditos/${credito.id}/liquidacion`, {
                method: 'POST',
                body: {
                    forma_pago: formaPago,
                    fecha_pago: fechaPago || null,
                    valor_pago: valorAcordado || null,
                    motivo_descuento: conDescuento ? motivoDescuento : null,
                    observacion: observacion || null,
                },
            });

            navigate(`/pagos/creditos/${credito.id}`, { replace: true, state: { message: 'Crédito liquidado correctamente.' } });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <>
            {cotizacion ? (
                <SettlementQuoteCard cotizacion={cotizacion} />
            ) : (
                !quoteError && (
                    <div className="mb-6 flex justify-center rounded-xl border border-gray-100 bg-gray-50 p-5 dark:border-gray-800 dark:bg-white/5">
                        <Spinner size="sm" />
                    </div>
                )
            )}

            <form onSubmit={handleSubmit}>
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    {(error ?? quoteError) && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 sm:col-span-2 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error ?? quoteError}
                        </div>
                    )}

                    <div>
                        <Label htmlFor="forma-pago">Forma de pago</Label>
                        <Select
                            id="forma-pago"
                            options={PAYMENT_METHOD_OPTIONS}
                            defaultValue={formaPago}
                            onChange={(value) => setFormaPago(value as PaymentMethod)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="fecha-pago">Fecha de pago (opcional)</Label>
                        <DatePicker
                            id="fecha-pago"
                            name="fecha-pago"
                            value={fechaPago}
                            onChange={setFechaPago}
                            maxDate={new Date().toISOString().slice(0, 10)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="valor-acordado">Valor acordado (opcional)</Label>
                        <Input
                            id="valor-acordado"
                            name="valor-acordado"
                            type="number"
                            step={0.01}
                            min="0"
                            placeholder={cotizacion?.total}
                            value={valorAcordado}
                            onChange={(event) => setValorAcordado(event.target.value)}
                        />
                        <p className="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Si es menor al total, la diferencia se descuenta de los intereses.
                        </p>
                    </div>

                    {conDescuento && (
                        <div>
                            <Label htmlFor="motivo-descuento">Motivo del descuento</Label>
                            <Input
                                id="motivo-descuento"
                                name="motivo-descuento"
                                required
                                value={motivoDescuento}
                                onChange={(event) => setMotivoDescuento(event.target.value)}
                            />
                        </div>
                    )}

                    <div className="sm:col-span-2">
                        <Label htmlFor="observacion">Observación (opcional)</Label>
                        <Input
                            id="observacion"
                            name="observacion"
                            value={observacion}
                            onChange={(event) => setObservacion(event.target.value)}
                        />
                    </div>

                    <p className="text-sm text-gray-500 sm:col-span-2 dark:text-gray-400">
                        Si el cliente pagó antes de hoy, elige la fecha real del pago: el interés se calcula hasta ese día.
                        Esta acción cierra el crédito por completo: todas las cuotas quedan pagadas.
                    </p>

                    <div className="flex flex-wrap gap-3 border-t border-gray-100 pt-6 sm:col-span-2 dark:border-gray-800">
                        <Button
                            type="submit"
                            disabled={isSubmitting || !cotizacion}
                            startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}
                            endIcon={isSubmitting ? undefined : <Save className="size-4" />}
                        >
                            {isSubmitting ? 'Liquidando…' : 'Confirmar liquidación'}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => navigate(`/pagos/creditos/${credito.id}`)}>
                            Cancelar
                        </Button>
                    </div>
                </div>
            </form>
        </>
    );
};
