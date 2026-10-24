# credits-manager

Sistema multiempresa de **gestión de créditos y contabilidad**: cartera, cuotas y mora, pagos, conciliación bancaria, facturación, metas, calendario tributario y auditoría, con notificaciones en tiempo real, colas de trabajo, respaldos automáticos y un panel de monitoreo del servidor.

## Arquitectura

```
Navegador ──HTTPS──▶ nginx ──┬── /            SPA React (build estático)
                             ├── /api/…       PHP-FPM (Laravel 13) ──▶ MySQL 8.4
                             │                                    └──▶ Redis 7
                             └── /app (WS)    Reverb (WebSocket)
```

Una sola imagen de Laravel corre como varios servicios: `api`, `horizon` (una cola por tipo de trabajo: exportaciones, importaciones, conciliación, correos), `scheduler`, `reverb`, `monitor` y un listener que pasa la bitácora de auditoría de Redis Streams a la base de forma idempotente.

## Stack

| Capa | Tecnología |
| --- | --- |
| API | PHP 8.4, Laravel 13, Sanctum, Horizon, Reverb |
| Datos | MySQL 8.4, Redis 7 (Streams y colas) |
| Cliente | React 19, TypeScript, Vite, pnpm |
| Infra | Podman/Docker Compose, Nginx con HTTPS |
| Calidad | 1.789 pruebas (PHPUnit), Pint, ESLint, GitHub Actions con despliegue automático |

## Cómo correrlo

```bash
cp .env.example .env    # completar credenciales
podman compose up -d --build
podman compose exec api php artisan migrate --seed
```

La guía completa está en el [manual de instalación](docs/manual-instalacion.md).

## Documentación

- [Manual técnico](docs/manual-tecnico.md): arquitectura, variables de entorno, tareas programadas y operación.
- [Manual de instalación](docs/manual-instalacion.md)
- [Respaldos y restauración](docs/respaldos.md)
- [CI/CD](docs/ci-cd.md)

## Licencia

Ver [LICENSE](LICENSE).
