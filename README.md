# Comparador de seguros de coche

Comparador que consulta en paralelo a tres providers simulados (A, B y C) y muestra sus ofertas.
Symfony 7.4 (PHP 8.4) en el backend, Vue 3 + TypeScript en el frontend. Las especificaciones
completas están en [`specs/`](specs/), incluidas las decisiones de diseño ante ambigüedad del brief
([`specs/decisions.md`](specs/decisions.md)) y los prompts usados para dirigir al agente de
implementación ([`specs/prompts.md`](specs/prompts.md)).

## Sobre las especificaciones y el uso de IA

Las especificaciones de `specs/` están redactadas con ayuda de IA, y quiero ser explícito sobre ello
porque el brief pide especificaciones escritas a mano. Las decisiones de diseño, lo que descarté y por
qué, y la dirección técnica son míos; la redacción no.

La razón es que las especificaciones las va a consumir sobre todo un agente de código. Esa forma
(decisión explícita, consecuencia testable, referencia cruzada entre documentos) es la que las hace
autocontenidas. Consideré que una IA escribiría ese registro de forma más consistente y más fácil
de interpretar por un agente que yo a mano. Revisé cada decisión y cada consecuencia a medida que
iteraba antes de dar las especificaciones por cerradas, y verifiqué la autocontención al cerrar las
especificaciones, con la misma prueba que CHECK24 describe en el brief.

*Specs, README and code comments are written in Spanish — happy to provide an English summary on request.*

## Arranque

```bash
docker compose up --build
# o: make up
```

| Qué | URL |
|---|---|
| Aplicación | http://localhost:8080 |
| Dashboard de Grafana (sin credenciales, solo lectura) | http://localhost:3000 |
| Prometheus (alertas en `/alerts`) | http://localhost:9090 |

Para que el dashboard tenga datos: `make demo-traffic` (40 peticiones sintéticas, una a una).
`make down` para parar y borrar el sistema.

### Campaña de marketing

Una sola campaña, por configuración (`product.md` §4):

```bash
MARKETING_CAMPAIGN_ACTIVE=true MARKETING_CAMPAIGN_DISCOUNT_TYPE=PERCENTAGE MARKETING_CAMPAIGN_DISCOUNT_VALUE=10 docker compose up -d
# FIXED_AMOUNT: el valor son céntimos
```

## Comandos

Solo necesitan Docker — `test`, `lint` y `coverage` corren en la imagen de desarrollo del backend y
en la etapa `build` del frontend (`testing.md` §4.1), para que las versiones de las herramientas
coincidan con las de la app.

| Comando | Qué hace |
|---|---|
| `make install` | Instala dependencias de backend, frontend y e2e. |
| `make test` | Unitarios e integración, backend (PHPUnit) y frontend (Vitest), en contenedor. |
| `make lint` | PHPStan (nivel 8), ECS, Rector (`--dry-run`), `vue-tsc` y ESLint, en contenedor. |
| `make coverage` | Informe de cobertura del backend (PCOV), en contenedor. |
| `make test-alerts` | `promtool` sobre las reglas de alerta (TC-O4). No levanta el stack. |
| `make test-realtime` | Tiempo real contra el stack de test: techo del timeout y concurrencia (TC-2, TC-4). |
| `make test-e2e` | Playwright, escritorio y móvil emulado, con el Provider C forzado a HTTP 500. |
| `make test-all` | `test` + `test-alerts` + `test-realtime` + `test-e2e` (no incluye `lint` ni `coverage`). |

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

`make test`, `make lint` y `make coverage` solo corren en contenedor (ver arriba); esto es únicamente
para levantar la app y ver cambios rápido sin reconstruir imágenes.

## Notas

- **Concurrencia.** Cada adapter lanza su llamada a un provider con el soporte asíncrono nativo de
  Symfony HttpClient y el puerto de dos pasos (`providers.md` §1): `start()` envía la petición sin
  esperar la respuesta, así que las tres quedan en vuelo a la vez; `ComparisonService` las resuelve
  después con `collect()`, en el orden en que se lanzaron. El techo de tiempo (`max_duration`) cuenta
  desde `start()`, así que recoger en orden no alarga el tiempo total (`architecture.md` §3). No se usan
  Fibers: la concurrencia la da el cliente HTTP, no un scheduler propio de PHP — se consideró y se
  descartó (`specs/decisions.md` §1).
- **Por qué al menos 4 workers.** Los tres providers son endpoints HTTP internos reales
  (`src/ProviderSimulation/`), no un `usleep` simulado, para que el timeout y la concurrencia sean de
  verdad. Eso significa que la propia petición de `POST /comparison` mantiene ocupado un worker
  mientras espera a los tres providers: el entorno debe poder atender al menos *providers + 1*
  peticiones concurrentes (4 con los providers actuales) o las llamadas se encolan y la latencia
  medida de un provider incluye tiempo de espera que no es suyo (`architecture.md` §3). En Docker,
  PHP-FPM está configurado con 8 workers (`backend/docker/php-fpm-pool.conf`). Con `php -S` hace falta
  `PHP_CLI_SERVER_WORKERS` (varios workers) y `-d variables_order=EGPCS` para que las variables de
  entorno reales ganen al `.env`.
- **Métricas.** PHP-FPM no comparte memoria entre peticiones, así que en Docker los contadores viven en APCu
  (`METRICS_STORAGE=apcu`). Con `php -S` se usa el almacén `memory`, que es por proceso: `/metrics` no
  acumula de forma fiable fuera de Docker.
- **`/metrics` no se publica**: el frontend solo reenvía `/comparison`; Prometheus lo alcanza por la red interna.
- **Lint de `promtool`.** `make test-alerts` desactiva el lint `duplicate-rules`: el umbral por defecto y la
  excepción de `provider-c` comparten nombre de alerta a propósito (`observability.md` §6.3). La sintaxis y los
  casos de `alerts.test.yml` se validan igual.
- **Datos personales.** La fecha de nacimiento no aparece en ningún log ni etiqueta de métrica (TC-O2). El
  formulario guarda el input en `sessionStorage` en claro, decisión de alcance documentada en `product.md` §3.
