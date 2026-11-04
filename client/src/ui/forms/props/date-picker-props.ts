export type DatePickerProps = {
    id: string;
    name?: string;
    mode?: 'single' | 'range' | 'month';
    value?: string;
    onChange?: (value: string) => void;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    /** `md` (por defecto) es el tamaño de siempre; `sm` achica alto y relleno para barras de filtros densas. */
    size?: 'sm' | 'md';
    /** Ancla el calendario al `body` en vez de renderizarlo `static` junto al input: evita que quede recortado dentro de un contenedor con `overflow-x-auto` (p. ej. una celda de tabla). */
    appendToBody?: boolean;
    /** Fecha máxima seleccionable, formato `Y-m-d`. */
    maxDate?: string;
};
