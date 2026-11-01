import type { FC } from 'react';

import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { RANGE_OPTIONS } from '../constants/range-options';
import type { CalendarFiltersProps } from '../props/calendar-filters-props';
import type { CalendarRange } from '../types/calendar-range';

function esRango(valor: string): valor is CalendarRange {
    return RANGE_OPTIONS.some((opcion) => opcion.value === valor);
}

export const CalendarFilters: FC<CalendarFiltersProps> = ({ rango, onChangeRango, empleadores, empleadorId, onChangeEmpleadorId }) => (
    <section className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-4">
            <div className="min-w-0">
                <Label htmlFor="calendario-filtro-rango">Rango</Label>
                <Select
                    id="calendario-filtro-rango"
                    key={rango}
                    options={RANGE_OPTIONS}
                    defaultValue={rango}
                    onChange={(valor) => esRango(valor) && onChangeRango(valor)}
                />
            </div>

            <div className="min-w-0">
                <Label htmlFor="calendario-filtro-empleador">Empleador</Label>
                <Select
                    id="calendario-filtro-empleador"
                    key={empleadorId ?? ''}
                    options={empleadores.map((empleador) => ({ value: String(empleador.id), label: empleador.nombre }))}
                    defaultValue={empleadorId ? String(empleadorId) : ''}
                    placeholder="Todos los empleadores"
                    placeholderSelectable
                    onChange={(valor) => onChangeEmpleadorId(valor ? Number(valor) : null)}
                />
            </div>
        </div>
    </section>
);
