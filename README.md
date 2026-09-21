# Comparador de seguros de coche

Comparador que consulta en paralelo a tres providers simulados (A, B y C) y muestra sus ofertas.
Symfony 7.4 (PHP 8.4) en el backend, Vue 3 + TypeScript en el frontend. Las especificaciones
completas están en [`specs/`](specs/).

## Arranque

```bash
docker compose up --build
```

| Qué | URL |
|---|---|
| Aplicación | http://localhost:8080 |
| Dashboard de Grafana (sin credenciales, solo lectura) | http://localhost:3000 |
| Prometheus (alertas en `/alerts`) | http://localhost:9090 |

Para que el dashboard tenga datos: `make demo-traffic` (40 peticiones sintéticas, una a una).

### Campaña de marketing

Una sola campaña, por configuración (`product.md` §4):

```bash
MARKETING_CAMPAIGN_ACTIVE=true MARKETING_CAMPAIGN_DISCOUNT_TYPE=PERCENTAGE MARKETING_CAMPAIGN_DISCOUNT_VALUE=10 docker compose up -d
# FIXED_AMOUNT: el valor son céntimos
```

## Comandos

| Comando | Qué hace |
|---|---|
| `make test` | Unitarios e integración (backend con PHPUnit, frontend con Vitest). No necesita Docker. |
| `make test-alerts` | `promtool` sobre las reglas de alerta (TC-O4). |
| `make test-realtime` | Tiempo real contra el stack de test: techo del timeout y concurrencia (TC-2, TC-4). |
| `make test-e2e` | Playwright, escritorio y móvil emulado, con el Provider C forzado a HTTP 500. |
| `make test-all` | Todo lo anterior. |
| `make lint` | PHPStan (nivel 8), ECS, `vue-tsc` y ESLint. |
| `make install` | Instala dependencias de backend, frontend y e2e. |

`make test-realtime` y `make test-e2e` levantan y bajan solos el stack de `docker-compose.test.yml`, que
fuerza el resultado y la latencia de cada provider por variable de entorno:

```
SIMULATION_FORCE="provider-c=spike,provider-a=http_error"   # normal | spike | http_error | malformed
SIMULATION_LATENCY_MS="provider-b=1800,provider-c=3000"
```

## Desarrollo sin Docker

```bash
cd backend && composer install
PHP_CLI_SERVER_WORKERS=8 php -d variables_order=EGPCS -S 127.0.0.1:8000 -t public   # ver notas
cd frontend && npm ci && npm run dev                                                # http://localhost:5173
```

## Notas

- **Concurrencia.** Cada provider se consulta en una `Fiber`: su adapter lanza la petición HTTP sin bloquear y
  cede el control, así que las tres están en vuelo a la vez y el tiempo total es ≈ el del más lento, acotado por
  el timeout (2 s). El servidor debe poder atender **al menos 4 peticiones concurrentes** (la de
  `/comparison` más las tres simuladas): en Docker, PHP-FPM con 8 workers. `php -S` necesita
  `PHP_CLI_SERVER_WORKERS` (varios workers) y `-d variables_order=EGPCS` para que las variables de entorno reales
  ganen al `.env`.
- **Métricas.** PHP-FPM no comparte memoria entre peticiones, así que en Docker los contadores viven en APCu
  (`METRICS_STORAGE=apcu`). Con `php -S` se usa el almacén `memory`, que es por proceso: `/metrics` no
  acumula de forma fiable fuera de Docker.
- **`/metrics` no se publica**: el frontend solo reenvía `/comparison`; Prometheus lo alcanza por la red interna.
- **Lint de `promtool`.** `make test-alerts` desactiva el lint `duplicate-rules`: el umbral por defecto y la
  excepción de `provider-c` comparten nombre de alerta a propósito (`observability.md` §6.3). La sintaxis y los
  31 casos de `alerts.test.yml` se validan igual.
- **Datos personales.** La fecha de nacimiento no aparece en ningún log ni etiqueta de métrica (TC-O2). El
  formulario guarda el input en `sessionStorage` en claro, decisión de alcance documentada en `product.md` §3.
- Las specs referencian un `decisions.md` que no forma parte de `specs/`; no se ha creado.
