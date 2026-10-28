import type { ApiTaxObligation } from '../types/api-tax-obligation';
import type { ApiTaxObligationResponsible } from '../types/api-tax-obligation-responsible';
import type { ApiTaxObligationType } from '../types/api-tax-obligation-type';

export type TaxObligationEventModalProps = {
    isOpen: boolean;
    /** `null` = modo crear (se usa `fechaInicial` como fecha límite de partida). */
    obligacion: ApiTaxObligation | null;
    fechaInicial: string | null;
    tipos: ApiTaxObligationType[];
    responsables: ApiTaxObligationResponsible[];
    /** Sin este permiso el evento se abre en solo lectura. */
    canEdit: boolean;
    canChangeStatus: boolean;
    canDelete: boolean;
    onClose: () => void;
    onSaved: () => void;
    /** El modal no confirma por sí mismo: lo hace la página con un `ConfirmModal`. */
    onDelete: (obligacion: ApiTaxObligation) => void;
};
