# Componentes de terceros y licencias

Inventario de librerías, componentes de código abierto, servicios y APIs de terceros que usa el sistema, con su licencia y su compatibilidad con el uso contratado (cláusula décima séptima del contrato).

- **Fuente:** dependencias directas de `api/composer.json` y `client/package.json`. Las versiones son las resueltas en `api/composer.lock` y `client/pnpm-lock.yaml`.
- **Fecha de corte:** 18 de octubre de 2026, rama `main` (`f31ad65`).
- **Uso contratado:** DDA usa el sistema internamente, sobre su propia infraestructura. El software no se revende ni se distribuye a terceros como producto.

## 1. Resumen

| Licencia | Directas | Transitivas | ¿Compatible con el uso contratado? |
|---|---|---|---|
| MIT, ISC, BSD-2/3-Clause, Apache-2.0, 0BSD, MIT-0, Unlicense, BlueOak-1.0.0, CC0-1.0 | Casi todas | ≈ 360 | Sí. Son permisivas: permiten uso comercial y modificación, y solo exigen conservar el aviso de copyright. |
| LGPL-2.1 / LGPL-3.0 (familia dompdf) | 1 | 2 | Sí. Se usan como librería sin modificarlas; ver sección 4.1. |
| MPL-2.0 (`lightningcss`) | 0 | 4 | Sí. Solo se usa para compilar el frontend y no viaja en el producto final. |
| CC-BY-4.0 (`caniuse-lite`) | 0 | 1 | Sí. Son datos de compatibilidad de navegadores que solo se usan al compilar. |
| BSD-3 / GPL-2.0 / GPL-3.0 a elección (`nette/schema`, `nette/utils`) | 0 | 2 | Sí. Se usan bajo la opción BSD-3-Clause. |
| **Licencia dual de ApexCharts** (`react-apexcharts`) | 1 | 0 | **Condicionada**; ver sección 4.2. |

Ningún componente tiene licencia GPL/AGPL obligatoria, SSPL, BUSL ni falta de licencia en el código que se entrega.

## 2. Backend — `api/composer.json`

### 2.1 Producción (`require`)

| Paquete | Versión | Licencia | Uso en el sistema |
|---|---|---|---|
| php | ^8.4 | PHP License 3.01 | Lenguaje del backend |
| laravel/framework | 13.24.0 | MIT | Framework de la API |
| laravel/sanctum | 4.3.3 | MIT | Autenticación del SPA con cookies |
| laravel/horizon | 5.50.0 | MIT | Panel y supervisión de colas |
| laravel/reverb | 1.11.1 | MIT | WebSockets para notificaciones en tiempo real |
| laravel/tinker | 3.0.2 | MIT | Consola de administración |
| dedoc/scramble | 0.13.42 | MIT | Documentación OpenAPI de la API |
| dompdf/dompdf | 3.1.6 | LGPL-2.1 | PDF de recibos, estados de cuenta, facturas y reportes |
| openspout/openspout | 5.11.3 | MIT | Lectura y escritura de XLSX/CSV (importaciones, exportaciones, extractos) |
| league/flysystem-aws-s3-v3 | 3.35.3 | MIT | Copia externa de respaldos en S3 |

### 2.2 Desarrollo y pruebas (`require-dev`, no se instalan en producción)

| Paquete | Versión | Licencia | Uso |
|---|---|---|---|
| phpunit/phpunit | 12.5.33 | BSD-3-Clause | Pruebas automatizadas |
| mockery/mockery | 1.6.12 | BSD-3-Clause | Dobles de prueba |
| fakerphp/faker | 1.24.1 | MIT | Datos de prueba |
| laravel/pint | 1.30.3 | MIT | Formato de código |
| laravel/pail | 1.2.7 | MIT | Lectura de logs en desarrollo |
| laravel/pao | 1.1.3 | MIT | Salida de herramientas de prueba |
| nunomaduro/collision | 8.9.5 | MIT | Reporte de errores en consola |

En total, `composer.lock` resuelve 108 paquetes de producción y 32 de desarrollo, contando los transitivos.

## 3. Frontend — `client/package.json`

### 3.1 Producción (`dependencies`, incluidas en el build)

| Paquete | Versión | Licencia | Uso en el sistema |
|---|---|---|---|
| react, react-dom | 19.2.8 | MIT | Librería de interfaz |
| react-router | 8.3.0 | MIT | Navegación del SPA |
| tailwindcss, @tailwindcss/postcss | 4.3.3 | MIT | Estilos |
| postcss | 8.5.25 | MIT | Procesamiento de CSS |
| tailwind-merge | 3.6.0 | MIT | Combinación de clases |
| apexcharts | 3.54.1 | MIT | Gráficos del dashboard y los reportes |
| **react-apexcharts** | **1.9.0** | **Dual ApexCharts** (sección 4.2) | Componente React de los gráficos |
| @fullcalendar/core, daygrid, interaction, react, timegrid | 6.1.21 | MIT | Calendario de cobranza |
| flatpickr | 4.6.13 | MIT | Selector de fechas |
| lucide-react | 0.545.0 | ISC | Iconos |
| react-dropzone | 14.4.1 | MIT | Carga de archivos |
| laravel-echo | 2.5.0 | MIT | Cliente de WebSockets |
| pusher-js | 8.6.0 | MIT | Protocolo de WebSockets (contra Reverb, sin usar el servicio Pusher) |

### 3.2 Desarrollo y compilación (`devDependencies`, no viajan en el build)

| Paquete | Versión | Licencia |
|---|---|---|
| typescript | 6.0.3 | Apache-2.0 |
| vite | 8.2.0 | MIT |
| @vitejs/plugin-react | 6.0.5 | MIT |
| @babel/core | 7.29.7 | MIT |
| @rolldown/plugin-babel | 0.2.3 | MIT |
| babel-plugin-react-compiler | 1.0.0 | MIT |
| eslint | 10.8.0 | MIT |
| @eslint/js | 10.0.1 | MIT |
| typescript-eslint | 8.66.0 | MIT |
| eslint-plugin-react-hooks | 7.1.1 | MIT |
| eslint-plugin-react-refresh | 0.5.3 | MIT |
| globals | 17.9.0 | MIT |
| vitest | 5.0.3 | MIT |
| jsdom | 30.1.1 | MIT |
| @testing-library/dom | 10.4.2 | MIT |
| @testing-library/react | 16.3.3 | MIT |
| @types/node, @types/react, @types/react-dom, @types/babel__core | — | MIT |

### 3.3 Otros recursos del frontend

| Recurso | Licencia | Nota |
|---|---|---|
| Plantilla TailAdmin React | MIT (edición gratuita) | Base visual del SPA. Hay que confirmar que se partió de la edición gratuita y no de la Pro, que es comercial. |
| Fuente Outfit (Google Fonts) | SIL Open Font License 1.1 | Se carga desde `fonts.googleapis.com`. |

## 4. Licencias que requieren atención

### 4.1 dompdf (LGPL)

`dompdf/dompdf` (LGPL-2.1), `dompdf/php-font-lib` (LGPL-2.1-or-later) y `dompdf/php-svg-lib` (LGPL-3.0-or-later) se usan como librerías sin modificar, instaladas por Composer. La LGPL permite usarlas desde software con otra licencia. La única obligación es que, si alguna vez se modifica el código de dompdf y se distribuye, esas modificaciones se publiquen bajo LGPL. El uso interno de DDA no genera ninguna obligación adicional.

### 4.2 react-apexcharts 1.9.0 (licencia dual de ApexCharts)

El núcleo `apexcharts` 3.54.1 es MIT. El componente `react-apexcharts` 1.9.0, en cambio, trae la licencia dual de ApexCharts:

- **Community (gratuita):** para organizaciones con **ingresos anuales menores a USD 2 millones**. Permite uso comercial.
- **Commercial (de pago):** obligatoria si la organización, o su grupo, factura USD 2 millones o más al año.
- **OEM:** solo aplica si el producto se redistribuye a terceros. No es el caso de DDA.

**Acción requerida:** DDA debe confirmar por escrito que sus ingresos anuales son menores a USD 2 millones; en ese caso queda cubierta por la licencia Community, sin costo. Si no cumple ese umbral, hay dos alternativas:

- (a) Fijar `react-apexcharts` en una versión anterior publicada bajo MIT.
- (b) Escribir un componente propio sobre `apexcharts` (MIT), que es un envoltorio pequeño.

Cualquiera de las dos elimina la dependencia de la licencia dual sin cambiar los gráficos.

## 5. Software de infraestructura

| Componente | Versión / imagen | Licencia | Compatibilidad |
|---|---|---|---|
| MySQL Community Server | `mysql:8.4` | GPL-2.0 (con excepción FOSS) | Sí. Se usa como servidor independiente; la aplicación se conecta por red y no lo modifica ni lo distribuye. |
| Redis | `redis:7-alpine` | BSD-3 hasta 7.2; RSALv2 / SSPLv1 desde 7.4 | Sí. Las restricciones de RSAL/SSPL solo aplican a quien ofrezca Redis como servicio a terceros. Si se quiere evitar del todo, se puede fijar la imagen en `redis:7.2-alpine` (BSD-3) o usar Valkey (BSD-3). |
| Nginx | `nginx:alpine` | BSD-2-Clause | Sí |
| PHP-FPM | `php:8.4-fpm-alpine` | PHP License 3.01 | Sí |
| Composer | `composer:2` (solo al construir) | MIT | Sí |
| Node.js | `node:22-alpine` (solo al construir) | MIT | Sí |
| phpMyAdmin | `phpmyadmin/phpmyadmin:latest` | GPL-2.0 | Sí. Es una herramienta de administración separada y no se integra al código. Es opcional en producción. |
| Podman / Docker Compose | — | Apache-2.0 | Sí |
| mkcert (certificados locales) | — | BSD-3-Clause | Sí. Solo se usa en desarrollo. |

## 6. Servicios y APIs externos

Ninguno tiene costo para el CONTRATISTA. Los servicios que generan cargos los contrata y paga DDA, previa aprobación (cláusulas cuarta y quinta del contrato).

| Servicio | Uso | ¿Obligatorio? | Costo |
|---|---|---|---|
| Hosting / servidor | Correr la aplicación | Sí | Según el proveedor que elija DDA |
| Dominio y certificado TLS | Acceso por HTTPS | Sí | Dominio de pago; el certificado puede ser gratuito (Let's Encrypt) |
| Servidor de correo SMTP (`cobranza@creditosdda.com`) | Recordatorios de cobro y envío de facturas | Sí, para la cobranza por correo | Según el proveedor de correo de DDA |
| Almacenamiento compatible con S3 | Copia externa de respaldos | Opcional | Según el proveedor y el volumen |
| WhatsApp | Enlace `wa.me` con el mensaje prellenado | No requiere API ni cuenta empresarial | Sin costo |
| Google Fonts | Fuente Outfit | Sí; se puede servir localmente si se prefiere | Sin costo |
| Proveedor de facturación electrónica DIAN | — | **No integrado** | Fuera del alcance; requiere aprobación (anexo, §13.4) |
| API bancaria | — | **No integrada** (los extractos se cargan como archivo) | Fuera del alcance; requiere aprobación (anexo, §13.6) |

## 7. Declaración

El CONTRATISTA declara que los componentes listados se usan conforme a sus licencias y que son compatibles con el uso interno contratado por DDA, con la salvedad indicada en la sección 4.2, que se resuelve con la confirmación de DDA o con el cambio técnico allí descrito. Los textos completos de cada licencia vienen dentro de cada paquete (`api/vendor/*/LICENSE`, `client/node_modules/*/LICENSE`) y se pueden regenerar con:

```bash
composer licenses --working-dir=api
pnpm --dir client licenses list
```
