import type { FC } from 'react';
import { useEffect, useEffectEvent, useRef } from 'react';

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';
import monthSelectPlugin from 'flatpickr/dist/plugins/monthSelect';
import 'flatpickr/dist/plugins/monthSelect/style.css';
import { Calendar } from 'lucide-react';
import { FIELD_SIZE_CLASSES } from '../constants/field-size-classes';
import type { DatePickerProps } from '../props/date-picker-props';

export const DatePicker: FC<DatePickerProps> = ({
    id,
    name,
    mode = 'single',
    value,
    onChange,
    placeholder = mode === 'month' ? 'Seleccione un mes' : 'Seleccione una fecha',
    required = false,
    disabled = false,
    className = '',
    size = 'md',
    appendToBody = false,
    maxDate,
}) => {
    const inputRef = useRef<HTMLInputElement>(null);
    const instanceRef = useRef<flatpickr.Instance | null>(null);
    // Lee siempre el `onChange` vigente sin volver a crear la instancia de flatpickr en cada render.
    const notificarCambio = useEffectEvent((fecha: string) => onChange?.(fecha));

    useEffect(() => {
        if (!inputRef.current) {
            return;
        }

        instanceRef.current = flatpickr(inputRef.current, {
            mode: mode === 'month' ? 'single' : mode,
            static: !appendToBody,
            appendTo: appendToBody ? document.body : undefined,
            monthSelectorType: 'static',
            dateFormat: mode === 'month' ? 'Y-m' : 'Y-m-d',
            defaultDate: value || undefined,
            maxDate: maxDate || undefined,
            disableMobile: true,
            plugins: mode === 'month' ? [monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'M Y' })] : [],
            onChange: (_dates, dateStr) => notificarCambio(dateStr),
        });

        return () => {
            instanceRef.current?.destroy();
            instanceRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode, appendToBody, maxDate]);

    useEffect(() => {
        if (instanceRef.current && (value ?? '') !== instanceRef.current.input.value) {
            instanceRef.current.setDate(value || '', false);
        }
    }, [value]);

    return (
        <div className="relative">
            <input
                ref={inputRef}
                id={id}
                name={name}
                placeholder={placeholder}
                required={required}
                disabled={disabled}
                readOnly
                className={`${FIELD_SIZE_CLASSES[size]} w-full rounded-lg border appearance-none pl-4 pr-11 text-sm shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 bg-transparent text-gray-800 border-gray-300 focus:border-brand-300 focus:ring-brand-500/20 dark:border-gray-700 dark:focus:border-brand-800 ${
                    disabled ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'
                } ${className}`}
            />

            <span className="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                <Calendar className="size-5" />
            </span>
        </div>
    );
};
