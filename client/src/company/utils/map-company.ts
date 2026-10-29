import type { ApiCompany } from '../types/api-company';
import type { Company } from '../types/company';

export function mapCompany(apiCompany: ApiCompany): Company {
    return {
        id: apiCompany.id,
        name: apiCompany.nombre_empresa,
        taxId: apiCompany.nit,
        taxVerificationDigit: apiCompany.digito_verificacion,
        address: apiCompany.direccion,
        municipality: apiCompany.municipio
            ? {
                  id: apiCompany.municipio.id,
                  name: apiCompany.municipio.nombre,
                  stateId: apiCompany.municipio.estado?.id ?? null,
                  countryId: apiCompany.municipio.estado?.pais_id ?? null,
              }
            : null,
        economicActivity: apiCompany.actividad_economica
            ? { id: apiCompany.actividad_economica.id, code: apiCompany.actividad_economica.codigo, name: apiCompany.actividad_economica.nombre }
            : null,
        phone: apiCompany.telefono,
        email: apiCompany.correo,
        logoPath: apiCompany.logo_path,
        logoDarkPath: apiCompany.logo_dark_path,
    };
}
