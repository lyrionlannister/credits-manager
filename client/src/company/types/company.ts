export type Company = {
    id: number;
    name: string;
    taxId: string | null;
    taxVerificationDigit: string | null;
    address: string | null;
    municipality: { id: number; name: string; stateId: number | null; countryId: number | null } | null;
    economicActivity: { id: number; code: string; name: string } | null;
    phone: string | null;
    email: string | null;
    logoPath: string | null;
    logoDarkPath: string | null;
};
