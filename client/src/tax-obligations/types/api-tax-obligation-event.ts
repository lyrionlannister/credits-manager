import type { EventInput } from '@fullcalendar/react';

import type { ApiTaxObligation, ApiTaxObligationColor } from './api-tax-obligation';

export type TaxObligationEventProps = {
    color: ApiTaxObligationColor;
    obligacion: ApiTaxObligation;
};

export type TaxObligationEvent = EventInput & {
    extendedProps: TaxObligationEventProps;
};
