import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Spinner } from '@ui/spinner/components/spinner';
import { CalendarClock } from 'lucide-react';
import { annualRateOfCredit } from '../utils/annual-rate-of-credit';
import { monthlyRateFromAnnual } from '../utils/monthly-rate-from-annual';
import type { ReprogramCreditFormProps } from '../props/reprogram-credit-form-props';

export const ReprogramCreditForm: FC<ReprogramCreditFormProps> = ({ credito }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();

    const [cuotasRestantes, setCuotasRestantes] = useState('');
    const [tasaEfectivaAnual, setTasaEfectivaAnual] = useState(() => annualRateOfCredit(credito));
    const [fechaPrimerVencimiento, setFechaPrimerVencimiento] = useState('');
    const [motivo, setMotivo] = useState('');

    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await sendRequest(`/creditos/${credito.id}/reprogramacion`, {
                method: 'POST',
                body: {
                    cuotas_restantes: Number(cuotasRestantes),
                    // Sin tocar la tasa, la reprogramación sigue con la del crédito (la mensual exacta si es anterior a la EA).
                    ...(tasaEfectivaAnual === annualRateOfCredit(credito) ? {} : { tasa_efectiva_anual: tasaEfectivaAnual }),
                    fecha_primer_vencimiento: fechaPrimerVencimiento,
                    motivo,
                },
            });

            navigate(`/creditos/${credito.id}`, { replace: true, state: { message: 'Crédito reprogramado correctamente.' } });
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

                <div>
                    <Label htmlFor="cuotas-restantes">Número de cuotas nuevas</Label>
                    <Input
                        id="cuotas-restantes"
                        name="cuotas-restantes"
                        type="number"
                        min="1"
                        max="240"
                        required
                        value={cuotasRestantes}
                        onChange={(event) => setCuotasRestantes(event.target.value)}
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
                    <Label htmlFor="fecha-primer-vencimiento">Primer vencimiento</Label>
                    <DatePicker
                        id="fecha-primer-vencimiento"
                        name="fecha-primer-vencimiento"
                        required
                        value={fechaPrimerVencimiento}
                        onChange={setFechaPrimerVencimiento}
                    />
                </div>

                <div className="md:col-span-3">
                    <Label htmlFor="motivo-reprogramacion">Motivo de la reprogramación</Label>
                    <textarea
                        id="motivo-reprogramacion"
                        required
                        rows={3}
                        maxLength={500}
                        value={motivo}
                        onChange={(event) => setMotivo(event.target.value)}
                        className="h-auto w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                    />
                </div>

                <p className="text-sm text-gray-500 md:col-span-3 dark:text-gray-400">
                    Se rehacen las cuotas que aún no tienen pagos: su capital se reparte en el nuevo número de cuotas desde
                    el primer vencimiento. Las cuotas pagadas, con abonos o con interés ya causado no cambian y se siguen
                    debiendo. No se crea otro crédito.
                </p>

                <div className="flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:justify-end md:col-span-3 dark:border-gray-800">
                    <Button variant="outline" onClick={() => navigate(`/creditos/${credito.id}`)} disabled={isSubmitting}>
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        disabled={isSubmitting || motivo.trim() === ''}
                        startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : <CalendarClock className="size-4" />}
                    >
                        {isSubmitting ? 'Reprogramando…' : 'Reprogramar cuotas'}
                    </Button>
                </div>
            </div>
        </form>
    );
};
