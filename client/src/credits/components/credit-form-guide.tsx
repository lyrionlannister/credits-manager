import type { FC } from 'react';

import { Calculator, CalendarClock, CalendarDays } from 'lucide-react';
import type { CreditFormGuideProps } from '../props/credit-form-guide-props';

/** Panel lateral del formulario de crédito: cómo se calculan las cuotas, según el tipo de pago elegido. */
export const CreditFormGuide: FC<CreditFormGuideProps> = ({ tipoPago }) => {
    const widgets = [
        {
            icon: Calculator,
            titulo: 'Sistema francés',
            texto: 'Cuota fija con interés compuesto sobre el saldo de capital. La tasa se pacta efectiva anual (EA) y se convierte a mensual; los gastos de administración son mensuales.',
        },
        {
            icon: CalendarDays,
            titulo: tipoPago === 'quincenal' ? 'Pago quincenal' : 'Pago mensual',
            texto:
                tipoPago === 'quincenal'
                    ? 'Las cuotas vencen el 15 y el último día de cada mes, y cada mes se cobra en dos cuotas iguales. El plazo es en cuotas: 24 meses son 48 cuotas.'
                    : 'Cada cuota vence el mismo día de cada mes, contado desde el desembolso. El plazo es el número de meses.',
        },
        {
            icon: CalendarClock,
            titulo: 'Primer vencimiento',
            texto: 'Opcional. Solo mueve las fechas de las cuotas: cada mes cobra su interés completo, sin importar los días desde el desembolso.',
        },
    ];

    return (
        <aside className="flex flex-col gap-4 md:gap-6">
            {widgets.map(({ icon: Icon, titulo, texto }) => (
                <div key={titulo} className="flex gap-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400">
                        <Icon className="size-5" />
                    </div>
                    <div className="min-w-0">
                        <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">{titulo}</h3>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{texto}</p>
                    </div>
                </div>
            ))}
        </aside>
    );
};
