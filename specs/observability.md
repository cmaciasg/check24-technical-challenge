# Observability

## 0. Alcance de este documento

El brief pide a los perfiles senior una especificación de observabilidad cuyo objetivo es *entender si el comparador está "sano" tras el lanzamiento*, y avisa de que interesa menos el dashboard que **qué métricas son útiles y qué se hace con ellas**. Este documento fija:

- Qué significa "sano" y cuál es la línea base esperada de cada provider (§1).
- Qué es el "tiempo de carga de resultados" y dónde se mide (§2).
- Qué métricas existen, con qué etiquetas y por qué (§3).
- Qué se registra en logs, con qué campos y qué no se registra nunca (§4).
- Qué dashboard existe y cómo se lee cada panel (§5).
- Qué alertas existen, con qué umbrales y qué se hace al recibirlas (§6).
- Cómo se usa todo esto en el día a día (§7).
- Cómo se levanta el setup básico (§8), dónde vive el código (§9) y las consecuencias testables TC-O1 a TC-O4 (§10).

Fuera de alcance (se referencia, se define en otro fichero de `/specs`):

- El origen de los datos: `ProviderResult` (`elapsedMs`, `ProviderFailureReason`) en `providers.md` §1.2 y `providerStatuses` en `architecture.md` §4.2.
- La ejecución concreta de los tests TC-O1 a TC-O4 (herramientas, carpetas, comandos): `testing.md`.
- Las alternativas consideradas y descartadas: `decisions.md`.

Es un setup **básico** para un ejercicio, no un stack de producción: una sola instancia de backend, sin notificaciones, sin retención larga. §12 dice qué haría falta para pasar a producción.

## 1. Qué significa "sano" y principios

Se considera que el comparador está sano cuando cumple tres cosas a la vez:

1. **Responde** (disponibilidad): `POST /comparison` no devuelve `500` y el backend contesta al scrape.
2. **Es útil**: casi siempre devuelve al menos una oferta.
3. **Es rápido dentro de lo acordado**: cada comparación termina dentro del techo que impone el timeout de providers (`providers.md` §5.2).

Un dato de partida cambia cómo hay que alertar: los providers **fallan por diseño** (`providers.md` §3). Que Provider C falle a veces no es un incidente; que falle mucho más de lo que su contrato dice, sí. Por eso todo umbral de este documento se calibra contra esta línea base, derivada de los perfiles de `providers.md` §3:

| Magnitud | Línea base esperada |
|---|---|
| Provider A | fallo 0 %; latencia de llamadas correctas 80–200 ms |
| Provider B | fallo 0 %; latencia 1200–1800 ms (siempre lento, pero por debajo del timeout) |
| Provider C | fallo ≈ 15 % (10 % pico que acaba en `timeout`, 3 % `http_error`, 2 % `malformed_response`); latencia de llamadas correctas 150–400 ms |
| Comparaciones (`outcome`) | `complete` ≈ 85 %, `partial` ≈ 15 %, `empty` 0 %, `error` 0 % |
| Duración de `POST /comparison` | p50 ≈ 1,5 s (lo marca B); p95 ≈ 2,0 s (cuando C hace un pico, la comparación dura lo que el timeout) |

Cinco principios guían el resto del documento:

1. **Línea base primero.** Una alerta sin línea base o salta siempre (y se ignora) o no salta nunca.
2. **Alertar síntomas, diagnosticar causas.** Solo avisa lo que afecta ya al cliente (errores, resultados vacíos, lentitud, degradación anómala de un partner). Las causas se buscan en el dashboard y en los logs.
3. **Etiquetas acotadas.** Una etiqueta de métrica solo puede tomar valores de un conjunto cerrado y pequeño (§3.2).
4. **Ningún dato personal fuera de la petición.** Ni en logs ni como etiqueta de métrica se escribe la fecha de nacimiento ni ningún dato individual del `QuoteRequest` (§4.5).
5. **Cada métrica habilita una decisión.** No se mide "por medir": la tabla de §3.1 dice, para cada una, qué pregunta responde y qué se hace con ella.

## 2. "Tiempo de carga de resultados"

### 2.1 Definición adoptada

En este ejercicio, el tiempo de carga de resultados se mide **en el servidor**: es la duración de una comparación válida dentro del caso de uso `CompareQuotes` (`architecture.md` §6), desde que empieza hasta que tiene el resultado completo (providers resueltos y campaña aplicada). Se expone como `comparison_duration_seconds` (§3.1). Como cada provider ya devuelve `elapsedMs` (`providers.md` §1.2), esa misma medición se desglosa por provider en `provider_call_duration_seconds`.

Consecuencia de diseño que hay que tener presente al leerla: por `providers.md` §3 y §5.2, la duración **no se reparte como una campana**. B tarda siempre entre 1,2 y 1,8 s, así que ninguna comparación dura menos de ≈ 1,2 s; y el timeout de 2 s + el overhead la acotan por arriba. Es una distribución comprimida en un rango estrecho, con un segundo bloque en ≈ 2,0 s cuando C hace un pico. La **media esconde esa forma** (no distingue "todo va a 1,7 s" de "90 % a 1,5 s y 10 % a 2 s"), y el brief la nombra solo como ejemplo. Por eso los SLIs usan **percentiles (p50, p95, p99)** y un **heatmap** que muestra los dos bloques, no un promedio.

### 2.2 Límite conocido: no se mide lo que ve el navegador

La métrica de servidor **no incluye** la red del cliente (relevante en móvil), la descarga y el parseo del JSON ni el renderizado de las tarjetas, ni el tiempo previo que pueda pasar en el servidor web antes de que PHP reciba la petición. Lo que percibe el cliente es esa duración **más** todo eso. Se asume como límite consciente de un setup básico.

### 2.3 Evolución especificada, no implementada: tiempo de carga en el navegador

Para medir lo que percibe el cliente haría falta telemetría del frontend. Queda especificada aquí para que la decisión sea explícita, pero **no se implementa** porque obligaría a reabrir el contrato de `architecture.md` §4 y §5 (endpoint nuevo, código de medición en Vue), `testing.md` y el tratamiento de datos, en un ejercicio de alcance ajustado:

- Métrica: `ui_results_load_seconds`, histograma con una única etiqueta `device_class` (`mobile` | `desktop`, decidida por un criterio de viewport fijo).
- Medición: el frontend mide con `performance.now()` desde el envío del formulario hasta que `results-panel` (`architecture.md` §5.8) queda pintado, y lo envía con `navigator.sendBeacon` a un endpoint de telemetría propio.
- Valor añadido: la diferencia entre `ui_results_load_seconds` y `comparison_duration_seconds` aísla el coste de red y renderizado, que es lo único que distingue la experiencia en móvil y en escritorio.
- Registrado como alternativa considerada en `decisions.md`.

## 3. Métricas

### 3.1 Catálogo

Siete métricas. Los nombres de la tabla son los nombres **exactamente expuestos** en `/metrics`.

| Métrica | Tipo | Etiquetas | Pregunta que responde | Decisión que habilita |
|---|---|---|---|---|
| `comparison_requests_total` | contador | `outcome` | ¿Cuánto se usa y cómo terminan las comparaciones? | Detectar picos de uso, y que `empty` o `error` dejen de ser 0. |
| `comparison_duration_seconds` | histograma | — | ¿Cuánto tarda una comparación (§2)? | Ver si se cumple el techo del timeout; revisar el timeout único. |
| `provider_calls_total` | contador | `provider`, `outcome` | ¿Qué partner falla y por qué motivo? | Escalar a un partner; distinguir un pico de latencia de un fallo de formato. |
| `provider_call_duration_seconds` | histograma | `provider`, `result` | ¿Qué partner se ha vuelto lento? | Anticipar que un partner va a empezar a perder ofertas por timeout. |
| `comparison_quotes_total` | contador | `vehicle_type`, `usage_type` | ¿Qué piden los clientes? | Conocer el mix de producto (p. ej. peso de `COMMERCIAL`). |
| `comparison_overhead_seconds` | histograma | — | ¿El retraso es de los partners o nuestro? | Separar "un partner va lento" de "nuestro código va lento". |
| `campaign_discount_cents_total` | contador | — | ¿Cuánto subvenciona la campaña en las ofertas mostradas? | Dar a marketing visibilidad de la exposición de la campaña. |

Definiciones exactas:

- **`outcome` de `comparison_requests_total`** (enum cerrado `ComparisonOutcome`):
  - `complete`: todos los providers registrados respondieron con éxito.
  - `partial`: al menos uno respondió con éxito y al menos uno falló.
  - `empty`: ninguno respondió con éxito (TC-3 de `providers.md`): la respuesta es `200` con `offers: []`.
  - `invalid`: `400`, el `QuoteRequest` no era válido (`architecture.md` §4.1).
  - `error`: `500`, fallo propio no controlado.
- **`outcome` de `provider_calls_total`**: `ok`, o el valor de `ProviderFailureReason` (`timeout`, `http_error`, `malformed_response`, `unknown_error`; `providers.md` §1.1).
- **`result` de `provider_call_duration_seconds`**: `ok` o `failed`. Se separa para que los timeouts (siempre ≈ 2 s) no contaminen la latencia de las llamadas correctas. Valor observado: `elapsedMs / 1000` del `ProviderResult`, en éxito y en fallo.
- **`provider`**: el valor de `ProviderId` (`provider-a`, …), nunca `providerName`.
- **`comparison_overhead_seconds`**: `max(0, duración de la comparación − máximo elapsedMs de los ProviderResult / 1000)`. Es el tiempo propio (orquestación, campaña, construcción del resultado). Se observa solo cuando hay al menos un `ProviderResult`.
- **`campaign_discount_cents_total`**: suma, por oferta, de `price.amountCents − discountedPrice.amountCents`, solo con la campaña activa (`product.md` §4). Cuenta ofertas **mostradas**, no contratadas (el ejercicio no modela la compra): es exposición de la campaña, no coste real.
- **`comparison_quotes_total`**: se incrementa una vez por petición que llega al caso de uso (es decir, con `QuoteRequest` ya válido), sea cual sea su `outcome`.

### 3.2 Etiquetas y cardinalidad

Cada combinación distinta de valores de etiquetas es una serie temporal propia que Prometheus almacena y consulta (equivale a una fila distinta por cada combinación). Una etiqueta con valores casi únicos (una fecha de nacimiento, un `request_id`) crea una serie por petición y degrada Prometheus. Regla vinculante: **las etiquetas solo pueden tomar valores de un conjunto cerrado y pequeño.**

| Etiqueta | Valores permitidos | Quién lo garantiza |
|---|---|---|
| `outcome` (comparación) | `complete`, `partial`, `empty`, `invalid`, `error` | enum `ComparisonOutcome` |
| `outcome` (provider) | `ok`, `timeout`, `http_error`, `malformed_response`, `unknown_error` | `ProviderFailureReason` + `ok` |
| `provider` | ids de los providers registrados (hoy `provider-a`, `provider-b`, `provider-c`) | `ProviderId` de cada adapter |
| `result` | `ok`, `failed` | valor fijo en el adaptador de métricas |
| `vehicle_type` | `SEDAN`, `HATCHBACK`, `SUV`, `VAN` | enum `VehicleType` (`product.md` §1.2) |
| `usage_type` | `PRIVATE`, `COMMERCIAL` | enum `UsageType` (`product.md` §1.3) |

Nunca se usan como etiqueta: `birthDate` (ni edad ni tramo de edad), `request_id`, `providerQuoteReference`, precios, mensajes de error. Con los tres providers actuales el catálogo genera del orden de 10² series, muy por debajo de cualquier límite práctico. Añadir un provider añade sus series sin tocar nada más.

### 3.3 Histogramas: tramos (buckets)

Un histograma cuenta cuántas observaciones caen por debajo de cada umbral ("≤ 1 s", "≤ 1,5 s", …) y Prometheus estima los percentiles a partir de ellos con `histogram_quantile`. Los tramos se fijan de antemano y determinan la precisión, así que se eligen alrededor de los valores que importan (los umbrales de alerta y el timeout de 2 s). Valores en segundos:

| Métrica | Tramos |
|---|---|
| `comparison_duration_seconds` | 0.5, 1, 1.25, 1.5, 1.75, 2, 2.15, 2.3, 2.5, 3, 5 |
| `provider_call_duration_seconds` | 0.1, 0.2, 0.3, 0.4, 0.5, 1, 1.5, 1.8, 1.9, 2, 2.5 |
| `comparison_overhead_seconds` | 0.01, 0.025, 0.05, 0.1, 0.15, 0.25, 0.5, 1 |

### 3.4 Cuándo se registra cada métrica y dónde vive el código

Reparto de responsabilidades (estructura de carpetas en `architecture.md` §7.2):

- **`CompareQuotes`** (`Application/Compare`) registra todo lo que ocurre una vez que la petición es válida: `comparison_quotes_total` al empezar; `provider_calls_total` y `provider_call_duration_seconds` de cada `ProviderResult`, **justo después de que `ComparisonService` devuelva** y antes de aplicar la campaña (así un fallo posterior de la campaña no pierde los datos de los providers); `campaign_discount_cents_total` tras `CampaignPricingService`; y, al final, `comparison_requests_total` (`complete`/`partial`/`empty`), `comparison_duration_seconds` y `comparison_overhead_seconds`.
- **`ComparisonController`** (`UI/Http`) registra el `400` (`outcome="invalid"`).
- **Un subscriber de excepciones** (`src/Observability/`) registra el `500` (`outcome="error"`) ante cualquier excepción no controlada de `POST /comparison`.
- Si la excepción ocurre dentro del caso de uso, no se registra `comparison_duration_seconds` de esa petición: solo cuenta el `error`.

`CompareQuotes` no conoce ninguna librería de métricas. Depende de un puerto de salida, `ComparisonMetrics` (`Application/Compare`, junto a su consumidor, con el mismo criterio que `ProviderPort`), con la responsabilidad de registrar hechos del dominio, sin nombres de métrica ni etiquetas:

```php
interface ComparisonMetrics
{
    /** Una petición válida llega al caso de uso. */
    public function quoteRequested(VehicleType $vehicleType, UsageType $usageType): void;

    /** Un provider ha respondido (éxito o fallo). */
    public function providerCallFinished(ProviderResult $result): void;

    public function campaignDiscountApplied(int $discountCents): void;

    /** Fin de una comparación válida. El overhead lo calcula el llamador. */
    public function comparisonFinished(ComparisonOutcome $outcome, float $durationSeconds, ?float $overheadSeconds): void;

    /** 400 y 500, registrados fuera del caso de uso. */
    public function requestRejected(): void;
    public function requestFailed(): void;

    /** Crea a 0 todas las series conocidas (ver §3.5). */
    public function initializeSeries(): void;
}
```

La forma exacta de los métodos la decide quien implementa, respetando el reparto. Lo que no puede cambiar: los nombres y las etiquetas de §3.1–3.2 solo aparecen en **una** clase de `Infra/Observability` (`PrometheusComparisonMetrics`), que traduce estos hechos a la librería, y las etiquetas son enums, nunca cadenas libres. La duración se mide con un reloj inyectado (`Psr\Clock\ClockInterface`), para poder fijarlo en los tests.

### 3.5 Almacenamiento y endpoint `/metrics`

**Modelo pull.** La aplicación no envía métricas: expone `GET /metrics` con el estado actual en texto plano y Prometheus lo consulta cada 15 s (`nombre{etiquetas} valor`).

**Almacén.** PHP-FPM no comparte memoria entre peticiones, así que los contadores no pueden vivir en variables del proceso. Se usa la librería `promphp/prometheus_client_php` con su adaptador de **APCu** (memoria compartida por el pool de PHP-FPM, sin servicio adicional). Límite conocido: los contadores se reinician si se reinicia PHP-FPM; Prometheus lo tolera porque `rate()`/`increase()` detectan el reinicio del contador. Con más de un contenedor de backend, APCu dejaría de ser suficiente (haría falta Redis); no aplica aquí.

**Endpoint.** `GET /metrics` (`src/Observability/`, controlador propio, no en `Comparator`): llama a `ComparisonMetrics::initializeSeries()` y devuelve el texto en el formato de exposición de Prometheus. Reglas:

- `initializeSeries()` crea con valor 0 todas las combinaciones de etiquetas de los contadores (los `outcome` de comparación, los `outcome` de cada provider registrado, las 8 combinaciones de `vehicle_type` × `usage_type`). Sin esto, una serie que aún no ha ocurrido no existe, y la primera vez que ocurre Prometheus no ve el incremento (`increase()` necesita dos muestras): la primera `empty` o el primer `error` pasaría inadvertido. Los ids de provider salen de los providers registrados (`architecture.md` §1).
- El endpoint no es público: en `docker-compose` solo lo alcanza Prometheus por la red interna. El servidor web del frontend solo reenvía `/comparison` al backend (`architecture.md` §5.6), nunca `/metrics`. En un sistema real se protegería además por red o credenciales.

## 4. Logs

Los logs sirven para **diagnosticar** qué le pasó a una petición concreta; las métricas sirven para vigilar tendencias. Por eso las alertas salen de métricas y los logs pueden ser pocos y precisos.

### 4.1 Formato

- Monolog, una línea JSON por evento, a la salida de error del contenedor (`php://stderr`), de forma que aparezcan en `docker compose logs backend`. Con PHP-FPM debe estar activo `catch_workers_output`, o los logs de los workers se descartan.
- Los campos de contexto van **al primer nivel** del JSON (no anidados en un objeto `context`), para poder filtrarlos con `jq` directamente.
- Campos comunes a toda línea: `timestamp` (ISO-8601, UTC, con milisegundos), `level` (`INFO`, `WARNING`, `ERROR`), `event` (nombre estable del evento, minúsculas con punto: es lo que se filtra) y `request_id`.
- **`request_id`**: identifica todas las líneas de una misma petición (equivale a un id de transacción). Un subscriber lo fija al empezar la petición: si llega una cabecera `X-Request-Id` cuyo valor cumple `^[A-Za-z0-9-]{8,64}$`, se usa; si no (ausente o con otro formato, para no admitir texto arbitrario en los logs), se genera un UUID v4. Un procesador de Monolog lo añade a toda línea.
- Con `APP_ENV=prod` en `docker-compose`.

### 4.2 Eventos

| `event` | Nivel | Cuándo | Campos propios |
|---|---|---|---|
| `comparison.completed` | `INFO` | Una comparación válida termina (cualquier `outcome` de `complete`/`partial`/`empty`) | `outcome`, `duration_ms`, `offers_count`, `providers_ok`, `providers_failed`, `campaign_active` |
| `provider.call.failed` | `WARNING` (`ERROR` si `reason = unknown_error`) | Un provider falla, uno por provider fallido | `provider_id`, `reason`, `elapsed_ms` |
| `comparison.rejected` | `INFO` | `400`: `QuoteRequest` inválido | `field`, `rule` |
| `comparison.failed` | `ERROR` | `500`: fallo propio no controlado | `exception_class`, `file`, `line`, `trace` |

- `comparison.completed` es una línea resumen: contiene lo necesario para reconstruir la petición sin buscar en otras líneas.
- Las llamadas correctas a providers **no** se loguean una a una: ya están en las métricas, y hacerlo triplicaría el volumen.
- `provider.call.failed` es `WARNING` porque son fallos esperados de partners (Provider C fallará a menudo por diseño); el volumen de esa línea no importa, porque nada alerta a partir de logs. `unknown_error` no debería ocurrir en este ejercicio (`providers.md` §1.1), por eso sube a `ERROR`.
- Los `rule` del `comparison.rejected` son los códigos de `product.md` §1.5.
- Ejemplos:

```json
{"timestamp":"2026-09-22T10:15:03.412Z","level":"INFO","event":"comparison.completed","request_id":"3f2b9c1e-6a4d-4c1b-9d0e-7a2f5b8c1d44","outcome":"partial","duration_ms":2004,"offers_count":2,"providers_ok":2,"providers_failed":1,"campaign_active":true}
{"timestamp":"2026-09-22T10:15:03.410Z","level":"WARNING","event":"provider.call.failed","request_id":"3f2b9c1e-6a4d-4c1b-9d0e-7a2f5b8c1d44","provider_id":"provider-c","reason":"timeout","elapsed_ms":2001}
{"timestamp":"2026-09-22T10:16:40.118Z","level":"INFO","event":"comparison.rejected","request_id":"a91c77e0-2f43-4b1a-8e56-0c5d3f9a2b17","field":"birthDate","rule":"age_out_of_range"}
```

### 4.3 Quién emite qué

`CompareQuotes` emite `comparison.completed` y `provider.call.failed` (con el logger PSR-3 estándar inyectado; los logs no necesitan un puerto propio). `ComparisonController` emite `comparison.rejected`. El subscriber de excepciones emite `comparison.failed`.

### 4.4 Niveles y volumen

Sin muestreo: el volumen de una petición es 1 línea de resumen más 0–3 de fallo de provider, aceptable para este alcance.

### 4.5 Privacidad: qué no aparece nunca en un log

**Ningún dato del `QuoteRequest` aparece en logs** (ni `birthDate`, ni `vehicle_type`, ni `usage_type`): los datos agregados de uso solo existen como etiquetas de métrica (§3.2). Se garantiza por construcción y se comprueba con un test (TC-O2):

- Cada línea se construye con **valores escalares explícitos** (los campos de §4.2), nunca volcando un objeto de la petición, del `QuoteRequest` o de un payload.
- Los `400` registran el campo y el código de la regla incumplida, **nunca el valor** que la incumplió.
- Los `500` registran la clase de la excepción, fichero, línea y una traza formada solo por fichero, línea, clase y función de cada frame, **sin argumentos** (`getTraceAsString()` incluye los valores de los argumentos y filtraría la fecha), y **sin el mensaje** de la excepción, porque un mensaje de PHP o de librería puede citar el valor que falló.
- No se registran cuerpos de petición ni de respuesta de providers (el payload que se envía a un provider contiene la fecha de nacimiento).
- Los logs del propio framework sobre excepciones no capturadas (canal `request` de Symfony) incluyen el mensaje de la excepción y **no se emiten**: `comparison.failed` los sustituye. En `docker-compose` el log de acceso del servidor web registra solo método y ruta, nunca el cuerpo.

## 5. Dashboard

Un único dashboard, `Comparator — salud y uso`, con cuatro filas ordenadas como las preguntas de quien está de guardia. Se provisiona desde fichero (§8.3), sin configuración manual. Rango por defecto: última hora; refresco: 10 s. En cada panel se anota la línea base esperada de §1 en su descripción, para que el número tenga referencia. Las consultas están en PromQL.

### 5.1 Fila 1 — ¿Está sano ahora?

| Panel | Consulta | Lectura |
|---|---|---|
| Peticiones por minuto | `sum(rate(comparison_requests_total[5m])) * 60` | Volumen de uso. Una caída brusca a 0 con el frontend abierto apunta a un problema de conectividad. |
| % comparaciones completas | `sum(rate(comparison_requests_total{outcome="complete"}[5m])) / sum(rate(comparison_requests_total{outcome!="invalid"}[5m]))` | Línea base ≈ 85 % (Provider C falla ≈ 15 %). Verde ≥ 80 %, ámbar 65–80 %, rojo < 65 %. |
| % vacías + errores | `sum(rate(comparison_requests_total{outcome=~"empty|error"}[5m])) / sum(rate(comparison_requests_total{outcome!="invalid"}[5m]))` | Línea base 0 %. Cualquier valor sostenido por encima es anómalo (verde 0, ámbar > 0, rojo > 1 %). |
| p95 de duración | `histogram_quantile(0.95, sum by (le) (rate(comparison_duration_seconds_bucket[5m])))` | Línea base ≈ 2,0 s. Alerta por encima de 2,3 s. |
| Alertas activas | `count(ALERTS{alertstate="firing"}) or vector(0)` | Cero es lo normal. |

### 5.2 Fila 2 — Uso

| Panel | Consulta | Lectura |
|---|---|---|
| Peticiones por minuto por resultado (apiladas) | `sum by (outcome) (rate(comparison_requests_total[5m])) * 60` | Proporción de `complete`/`partial`/`empty`/`invalid`/`error` en el tiempo. Un aumento de `invalid` sin más tráfico apunta a un cambio en el formulario. |
| Comparaciones por tipo de vehículo y uso | `sum by (vehicle_type, usage_type) (increase(comparison_quotes_total[$__range]))` (barras) | Qué piden los clientes; insumo de producto. |
| Subvención de la campaña (€, última hora) | `sum(increase(campaign_discount_cents_total[1h])) / 100` | Exposición de la campaña en ofertas mostradas (no compras). 0 con la campaña inactiva. |

### 5.3 Fila 3 — Partners

| Panel | Consulta | Lectura |
|---|---|---|
| Tasa de fallo por provider | `sum by (provider) (rate(provider_calls_total{outcome!="ok"}[5m])) / sum by (provider) (rate(provider_calls_total[5m]))` | A y B deberían estar en 0; C ≈ 15 % es normal. Una línea de umbral por provider marca cuándo alerta (5 %; C 30 %). |
| Fallos por motivo | `sum by (provider, outcome) (rate(provider_calls_total{outcome!="ok"}[5m])) * 60` (apiladas) | Distingue un partner lento (`timeout`) de uno roto (`http_error`) o con formato distinto (`malformed_response`). Determina a quién se escala y qué se le pide. |
| Latencia por provider (llamadas correctas), p50 y p95 | `histogram_quantile(0.5, sum by (le, provider) (rate(provider_call_duration_seconds_bucket{result="ok"}[5m])))` y la misma con `0.95` | Cada partner contra su contrato (A 80–200 ms, B 1200–1800 ms, C 150–400 ms). B acercándose a 1,9 s anticipa que perderá ofertas por timeout. |

### 5.4 Fila 4 — Latencia y capacidad

| Panel | Consulta | Lectura |
|---|---|---|
| Duración de la comparación, p50/p95/p99 | `histogram_quantile(<q>, sum by (le) (rate(comparison_duration_seconds_bucket[5m])))` con q = 0,5, 0,95 y 0,99 | El "tiempo de carga" en servidor (§2). Línea base p50 ≈ 1,5 s, p95 ≈ 2,0 s. |
| Overhead propio, p95 | `histogram_quantile(0.95, sum by (le) (rate(comparison_overhead_seconds_bucket[5m])))` | Tiempo propio, fuera de los providers. Sube si algo de nuestro código o del contenedor va mal, no si un partner va lento. |
| Heatmap de duración | `sum by (le) (increase(comparison_duration_seconds_bucket[$__rate_interval]))` (formato heatmap) | Muestra la forma real: un bloque ancho entre 1,2 y 1,8 s (B) y un segundo bloque en ≈ 2 s cuando C hace un pico. Es lo que una media no deja ver. |

## 6. Alertas

Las reglas de alerta viven en `observability/prometheus/alerts.yml` y las evalúa Prometheus (§8). Se ven en la interfaz de Prometheus (`/alerts`) y en el panel de alertas del dashboard. **No hay Alertmanager**: no se envía ninguna notificación (no hay canal al que enviarla en un ejercicio). El formato es el estándar de reglas de Prometheus, que también lee `vmalert` sin cambios. En producción, Alertmanager (o el equivalente) enrutaría cada alerta según su `severity` (`critical`: aviso inmediato a quien esté de guardia; `warning`: revisión en horario laboral).

### 6.1 Diseño

- **Umbrales simples con duración mínima (`for`).** `for: 5m` significa que la condición debe cumplirse durante 5 minutos seguidos antes de disparar (equivale al `failureThreshold` de una sonda de salud: un fallo aislado no marca la instancia como caída). No se usa burn rate multiventana, la técnica de SLO de producción; queda como evolución (§12).
- **Guarda de tráfico mínimo.** Las alertas de ratio y de percentil solo se evalúan si hay volumen suficiente en la ventana (≥ 5 comparaciones en 5 min; ≥ 10 llamadas de provider en 10 min), para que con casi tráfico cero (como en la demo) una sola petición fallida no dispare un 100 %.
- **Calibradas contra la línea base de §1.** A y B nunca fallan, así que se avisa a partir del 5 %. C falla ≈ 15 % por diseño, así que se avisa al doble de su línea base (30 %). Los umbrales por provider son un valor por defecto más una excepción explícita, para que un provider nuevo quede cubierto por el valor por defecto sin tocar nada.
- **Lo que no alerta, a propósito:** un fallo suelto de C (es su comportamiento esperado) y los `400` (error del cliente; solo dashboard).
- Los SLIs implícitos son: disponibilidad (`error` ≤ 1 %), utilidad (`empty` ≤ 5 %), latencia (p95 ≤ 2,3 s) y salud de cada partner respecto a su contrato.

### 6.2 Catálogo

| Alerta | Condición | `for` | Severidad | Qué sugiere y primera acción |
|---|---|---|---|---|
| `BackendDown` | Prometheus no consigue leer `/metrics` | 1 m | `critical` | La aplicación no responde: comprobar que el contenedor está arrancado y revisar sus logs de arranque. |
| `ComparisonErrors` | `outcome="error"` (`500`) > 1 % de las comparaciones | 5 m | `critical` | Fallo propio, nunca causado por un provider (`architecture.md` §4.1): buscar `comparison.failed` en los logs. |
| `ComparisonEmptyResults` | `outcome="empty"` > 5 % | 5 m | `critical` | Los tres providers fallan a la vez (línea base 0 %, A y B nunca fallan): apunta a red o a nuestro cliente HTTP. Mirar `provider.call.failed`. |
| `ComparisonLatencyHigh` | p95 de `comparison_duration_seconds` > 2,3 s | 10 m | `warning` | El techo teórico es el timeout (2 s) más un margen: comparar con el overhead; si no sube, mirar la latencia por provider. |
| `ComparisonOverheadHigh` | p95 de `comparison_overhead_seconds` > 0,25 s | 10 m | `warning` | Tiempo propio anómalo: revisar despliegues recientes y CPU del contenedor. |
| `ProviderFailureRateHigh` | Fallos de un provider > 5 % (Provider C: > 30 %) | 10 m | `warning` | Partner degradado respecto a su contrato: ver el motivo en el dashboard (`timeout` frente a `http_error` o `malformed_response`) y a quién escalar. |
| `ProviderLatencyDegraded` | p95 de llamadas correctas por encima del máximo documentado más margen (A > 0,3 s, B > 1,9 s, C > 0,5 s; providers nuevos > 1,9 s) | 10 m | `warning` | El partner responde más lento que su contrato. En B, además, anticipa pérdida de ofertas por timeout. |

Nota sobre saturación del pool: `architecture.md` §3 exige al menos *providers + 1* workers de PHP-FPM. Con menos, las llamadas a los providers simulados esperan turno en el mismo pool, y esa espera cuenta como latencia del provider (no como overhead). Por eso, si `ProviderLatencyDegraded` salta solo para `provider-a` (contrato 80–200 ms) sin que haya cambiado nada en su simulación, la primera hipótesis en este ejercicio es esa saturación. La detección directa (métricas propias de PHP-FPM) queda fuera de alcance (§12).

### 6.3 Reglas

`observability/prometheus/alerts.yml`:

```yaml
# Reglas de alerta del comparador (observability.md §6).
# Cada alerta avisa de un síntoma; la causa se diagnostica en el dashboard (§5).
# Guarda de tráfico mínimo: las alertas de ratio y de percentil solo se evalúan si hay
# volumen suficiente en la ventana, para que con poco tráfico una petición suelta no dispare un 100 %.
groups:
  - name: comparator
    rules:
      - alert: BackendDown
        expr: up{job="backend"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "El backend no responde al scrape de /metrics"
          action: "Revisar que el contenedor backend esté arrancado y sus logs de arranque."

      - alert: ComparisonErrors
        expr: |
          (
            sum(increase(comparison_requests_total{outcome="error"}[5m]))
            /
            sum(increase(comparison_requests_total{outcome!="invalid"}[5m]))
          ) > 0.01
          and on()
          sum(increase(comparison_requests_total{outcome!="invalid"}[5m])) >= 5
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Más del 1 % de las comparaciones acaban en 500"
          action: "Es un fallo propio, nunca de un provider: buscar comparison.failed en los logs (exception_class y traza)."

      - alert: ComparisonEmptyResults
        expr: |
          (
            sum(increase(comparison_requests_total{outcome="empty"}[5m]))
            /
            sum(increase(comparison_requests_total{outcome!="invalid"}[5m]))
          ) > 0.05
          and on()
          sum(increase(comparison_requests_total{outcome!="invalid"}[5m])) >= 5
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Más del 5 % de las comparaciones no devuelve ninguna oferta"
          action: "Los tres providers fallan a la vez (línea base 0 %): mirar provider.call.failed y sospechar de red o del cliente HTTP propio."

      - alert: ComparisonLatencyHigh
        expr: |
          histogram_quantile(0.95, sum by (le) (rate(comparison_duration_seconds_bucket[5m]))) > 2.3
          and on()
          sum(increase(comparison_requests_total{outcome!="invalid"}[5m])) >= 5
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "El p95 de POST /comparison supera 2,3 s (línea base ≈ 2,0 s)"
          action: "Comparar con comparison_overhead_seconds: si sube, el retraso es propio; si no, mirar la latencia por provider."

      - alert: ComparisonOverheadHigh
        expr: |
          histogram_quantile(0.95, sum by (le) (rate(comparison_overhead_seconds_bucket[5m]))) > 0.25
          and on()
          sum(increase(comparison_requests_total{outcome!="invalid"}[5m])) >= 5
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "El tiempo propio de una comparación (fuera de los providers) supera 250 ms en p95"
          action: "Revisar despliegues recientes y CPU del contenedor. Si además sube la latencia de provider-a (línea base 80-200 ms), sospechar de saturación del pool PHP-FPM (architecture.md §3)."

      # Umbral por defecto para cualquier provider salvo los que tienen una excepción explícita.
      - alert: ProviderFailureRateHigh
        expr: |
          (
            sum by (provider) (increase(provider_calls_total{provider!="provider-c", outcome!="ok"}[10m]))
            /
            sum by (provider) (increase(provider_calls_total{provider!="provider-c"}[10m]))
          ) > 0.05
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider!="provider-c"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "El provider {{ $labels.provider }} falla en más del 5 % de las llamadas"
          action: "Ver el motivo en el dashboard (timeout frente a http_error o malformed_response) y en provider.call.failed."

      # Excepción: provider-c falla por diseño ≈ 15 % (providers.md §3.3); se alerta al doble de su línea base.
      - alert: ProviderFailureRateHigh
        expr: |
          (
            sum by (provider) (increase(provider_calls_total{provider="provider-c", outcome!="ok"}[10m]))
            /
            sum by (provider) (increase(provider_calls_total{provider="provider-c"}[10m]))
          ) > 0.30
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider="provider-c"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "El provider {{ $labels.provider }} falla en más del 30 % de las llamadas (línea base 15 %)"
          action: "Ver el motivo en el dashboard (timeout frente a http_error o malformed_response) y en provider.call.failed."

      # Latencia de las llamadas correctas: máximo documentado + margen, por provider (providers.md §3).
      - alert: ProviderLatencyDegraded
        expr: |
          histogram_quantile(0.95, sum by (le, provider) (rate(provider_call_duration_seconds_bucket{provider="provider-a", result="ok"}[10m]))) > 0.3
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider="provider-a", outcome="ok"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "provider-a responde más lento que su contrato (p95 > 0,3 s; contrato 80-200 ms)"
          action: "Si solo es provider-a, sospechar de saturación del pool PHP-FPM (architecture.md §3); si son todos, del entorno."

      - alert: ProviderLatencyDegraded
        expr: |
          histogram_quantile(0.95, sum by (le, provider) (rate(provider_call_duration_seconds_bucket{provider="provider-b", result="ok"}[10m]))) > 1.9
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider="provider-b", outcome="ok"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "provider-b se acerca al timeout (p95 > 1,9 s; contrato 1,2-1,8 s)"
          action: "Sus ofertas están a punto de perderse por timeout: valorar con el partner o revisar el timeout único (decisions.md)."

      - alert: ProviderLatencyDegraded
        expr: |
          histogram_quantile(0.95, sum by (le, provider) (rate(provider_call_duration_seconds_bucket{provider="provider-c", result="ok"}[10m]))) > 0.5
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider="provider-c", outcome="ok"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "provider-c responde más lento que su contrato (p95 > 0,5 s; contrato normal 150-400 ms)"
          action: "Comparar con su tasa de fallos: un partner que se degrada suele fallar antes de tardar más."

      # Umbral por defecto para providers nuevos: una llamada correcta nunca supera el timeout (2 s).
      - alert: ProviderLatencyDegraded
        expr: |
          histogram_quantile(0.95, sum by (le, provider) (rate(provider_call_duration_seconds_bucket{provider!~"provider-a|provider-b|provider-c", result="ok"}[10m]))) > 1.9
          and on(provider)
          sum by (provider) (increase(provider_calls_total{provider!~"provider-a|provider-b|provider-c", outcome="ok"}[10m])) >= 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "El provider {{ $labels.provider }} se acerca al timeout (p95 > 1,9 s)"
          action: "Añadir una regla con su umbral propio si su latencia normal es distinta."
```

Al añadir un provider (`architecture.md` §1) queda cubierto por los dos umbrales por defecto (fallos > 5 %, latencia > 1,9 s). Si su comportamiento normal es distinto, se añade una regla de excepción como las de `provider-c`, y se excluye del valor por defecto.

## 7. Uso diario: qué se hace con todo esto

Medir solo tiene sentido si cambia lo que hace el equipo. Rutinas concretas:

- **Al empezar el día (30 segundos):** fila 1 del dashboard. Si las alertas están a cero y "% vacías + errores" es 0, no hace falta mirar más. Si algo no cuadra, se baja a la fila que corresponde a la alerta (partners → fila 3, latencia → fila 4).
- **Tras un despliegue:** comparar durante 15 minutos p95 de duración, overhead y tasa de errores con los valores previos. Si el overhead sube y los providers no, el cambio es nuestro.
- **Ante una alerta:** la columna "primera acción" de §6.2 dice dónde mirar. El flujo es: la alerta indica el síntoma → el dashboard aísla la causa (qué provider, qué motivo, si es propio o de un partner) → el `request_id` de una petición fallida (`comparison.completed` con `outcome` anómalo) reconstruye el caso completo en los logs.
- **Revisión semanal de partners:** tasa de fallo por motivo y p95 por provider contra su contrato. Es lo que se lleva a una conversación con el partner: "en la última semana, `provider-c` devolvió `malformed_response` en un X % de las llamadas, cuando el contrato dice ≈ 2 %".
- **Decisiones de producto y de diseño que estas métricas alimentan:** si el p95 de B se acerca a 1,9 s, hay que decidir entre renegociar con el partner y subir el timeout único (con el coste de subir el techo de espera para todos, decisión registrada en `decisions.md`); el mix de `comparison_quotes_total` indica qué segmento pesa más al elegir a qué partner se le da prioridad; `campaign_discount_cents_total` le da a marketing una referencia de exposición.

## 8. Setup básico

### 8.1 Componentes y estructura

Dos servicios nuevos en `docker-compose.yml` (Prometheus y Grafana) y una carpeta `observability/` en la raíz del proyecto (`architecture.md` §7.1):

```
observability/
  prometheus/
    prometheus.yml              # scrape del backend y reglas
    alerts.yml                  # reglas de alerta (§6.3)
    tests/
      alerts.test.yml           # tests de las reglas con promtool (§10, TC-O4)
  grafana/
    provisioning/
      datasources/prometheus.yml
      dashboards/dashboards.yml
    dashboards/
      comparator-overview.json  # el dashboard de §5
```

El backend usa la imagen de PHP con la extensión **APCu** instalada (§3.5). No hay más servicios: nada de Alertmanager, Loki ni Redis (§12).

### 8.2 `docker-compose.yml`: servicios nuevos

Los nombres de servicio, puertos y versiones concretas los fija quien implementa (imágenes con versión fija, nunca `latest`, anotadas en el propio compose); lo que sigue es lo vinculante:

- **`prometheus`**: monta `observability/prometheus/` como solo lectura en `/etc/prometheus`, retención corta (`--storage.tsdb.retention.time=2d`), volumen con nombre para sus datos y puerto `9090` publicado. Depende del backend.
- **`grafana`**: monta `observability/grafana/provisioning` en `/etc/grafana/provisioning` y `observability/grafana/dashboards` en `/var/lib/grafana/dashboards`; puerto `3000` publicado; variables `GF_AUTH_ANONYMOUS_ENABLED=true` y `GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer` (acceso sin credenciales, de solo lectura), y `GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH` apuntando al dashboard, de modo que `http://localhost:3000` lo abre directamente. Depende de Prometheus.
- **Acceso anónimo: decisión de demo, no de producción.** Está para que quien evalúe el proyecto entre sin credenciales. La cuenta de administración se deja con una contraseña definida por variable de entorno, no la de fábrica.
- El backend no publica `/metrics` hacia fuera: solo lo alcanza Prometheus por la red interna de compose (§3.5).
- El requisito de al menos *providers + 1* workers de PHP-FPM (`architecture.md` §3) sigue siendo válido.

### 8.3 Ficheros de configuración

`observability/prometheus/prometheus.yml`:

```yaml
# Configuración mínima de Prometheus (observability.md §8).
global:
  scrape_interval: 15s       # cada cuánto pregunta a la app por /metrics
  evaluation_interval: 15s   # cada cuánto evalúa las reglas de alerta

rule_files:
  - /etc/prometheus/alerts.yml

scrape_configs:
  - job_name: backend        # las reglas de alerta dependen de este nombre (up{job="backend"})
    metrics_path: /metrics
    static_configs:
      - targets: ["backend:80"]   # servicio del backend en docker-compose; ajustar al nombre/puerto reales
```

`observability/grafana/provisioning/datasources/prometheus.yml`: un único datasource de tipo `prometheus`, con `uid: prometheus` (fijo: el JSON del dashboard lo referencia), `url: http://prometheus:9090`, `isDefault: true` y `editable: false`.

`observability/grafana/provisioning/dashboards/dashboards.yml`: un proveedor de tipo `file` que carga los JSON de `/var/lib/grafana/dashboards`.

`observability/grafana/dashboards/comparator-overview.json`: el dashboard de §5, con `uid: comparator-overview`, las cuatro filas de §5.1–5.4 y las consultas de esas tablas, con el datasource `prometheus`. Se versiona como JSON y no se edita a mano en Grafana (los cambios se hacen sobre el fichero).

### 8.4 Datos de demo

Sin tráfico el dashboard sale vacío y el revisor no vería nada. Se incluye un comando de consola de Symfony, `app:observability:demo-traffic` (en `src/Observability/`), que envía a `POST /comparison` una serie de peticiones sintéticas variadas:

- Opciones: `--requests` (por defecto 40) y `--base-url` (por defecto la URL del backend en compose).
- Combina tipos de vehículo y uso, con fechas de nacimiento generadas al azar dentro del rango válido (18–99 años; datos sintéticos, no reales), e incluye alrededor de un 10 % de peticiones deliberadamente inválidas para que aparezca `invalid`.
- Las envía **de una en una, sin concurrencia**: con *providers + 1* = 4 workers (`architecture.md` §3), una segunda petición simultánea saturaría el pool y falsearía las latencias por provider que se quiere enseñar.
- Objetivo `make demo-traffic`: ejecuta el comando dentro del contenedor del backend.

### 8.5 Comandos

| Comando | Qué hace |
|---|---|
| `docker compose up` | Levanta el sistema completo, con Prometheus y Grafana. |
| `make demo-traffic` | Genera tráfico de ejemplo (§8.4). |
| `make test-alerts` | `promtool check rules` y `promtool test rules` sobre las reglas (TC-O4), ejecutando `promtool` desde la imagen oficial de Prometheus en un contenedor de un solo uso: no exige instalar nada ni levantar el stack. |

Acceso: dashboard en `http://localhost:3000`; interfaz de Prometheus (incluye la vista de alertas) en `http://localhost:9090`.

## 9. Estructura de código

La ubicación de cada pieza está en `architecture.md` §7.2. Resumen de responsabilidades:

| Pieza | Ubicación | Responsabilidad |
|---|---|---|
| `ComparisonMetrics` (puerto), `ComparisonOutcome` (enum) | `Comparator/CarInsurance/Application/Compare` | Registrar hechos del dominio sin conocer la librería de métricas. |
| `PrometheusComparisonMetrics` | `Comparator/CarInsurance/Infra/Observability` | El único sitio con nombres de métrica, etiquetas y tramos (§3). |
| `InvalidQuoteRequest` | `Comparator/CarInsurance/Domain/Exception` | Excepción del `400`, con `field` y `ruleCode` (`product.md` §1.5). |
| `MetricsController` (`GET /metrics`), subscriber de `request_id`, subscriber de excepciones (`500`), configuración de Monolog y del almacén APCu, `DemoTrafficCommand` | `src/Observability/` | Piezas genéricas, fuera del dominio de comparación (el mismo criterio que `ProviderSimulation`). |

## 10. Consecuencias testables

Cuatro consecuencias que dan forma comprobable a las decisiones de este documento (herramientas y carpetas en `testing.md`):

- **TC-O1 (cada resultado incrementa la métrica correcta)**: con un registro de métricas en memoria y providers falsos que fuerzan cada caso (`complete`, `partial`, `empty`), `CompareQuotes` incrementa `comparison_requests_total` con el `outcome` esperado, `provider_calls_total` con el `outcome` de cada provider, registra la duración y el overhead con un reloj fijado, y suma `campaign_discount_cents_total` solo con la campaña activa. El `400` y el `500` incrementan `outcome="invalid"` y `outcome="error"`.
- **TC-O2 (ningún dato personal en logs)**: tras enviar `POST /comparison` con una fecha de nacimiento reconocible (válida, y otra inválida para provocar un `400`) y tras forzar un `500`, ninguna línea de log capturada contiene la fecha enviada ni los valores de `vehicleType`/`usageType`; las líneas de `comparison.rejected` contienen `field` y `rule` y no el valor.
- **TC-O3 (`/metrics` expone las series y respeta la cardinalidad)**: tras arrancar, `GET /metrics` ya lista con valor 0 todas las series iniciales de §3.5; tras una petición, las series esperadas cambian; y todos los valores de todas las etiquetas pertenecen al conjunto cerrado de §3.2 (guarda de cardinalidad). En el test de integración, con almacén en memoria, el cliente de test debe conservar el mismo contenedor entre peticiones (`disableReboot()`), o el almacén se vaciaría entre la petición y la lectura de `/metrics`.
- **TC-O4 (las alertas son válidas y saltan cuando deben)**: `promtool check rules` valida `alerts.yml` y `promtool test rules` ejecuta `tests/alerts.test.yml`, que, para cada alerta del catálogo de §6.2, comprueba con series de entrada sintéticas un caso que la dispara y otro que **no** la dispara. Casos obligatorios además: la guarda de tráfico mínimo (mismo ratio con poco volumen no dispara), el valor por defecto frente a la excepción de `provider-c` (un 15 % de fallos en C no dispara, en A sí) y `BackendDown`.

## 11. Criterios de aceptación

Un agente implementador ha terminado cuando, desde un directorio limpio:

1. `docker compose up` levanta el sistema sin pasos manuales, y `http://localhost:3000` abre el dashboard `Comparator — salud y uso` sin pedir credenciales.
2. En `http://localhost:9090/targets`, el objetivo `backend` aparece `UP`.
3. Tras `make demo-traffic`, todos los paneles de las cuatro filas muestran datos y los valores son coherentes con la línea base de §1 (Provider A sin fallos, Provider B lento y sin fallos, Provider C con fallos y picos, `complete` ≈ 85 %).
4. Cada línea de log es JSON válido y contiene `timestamp`, `level`, `event` y `request_id`, y ninguna contiene datos del `QuoteRequest` (§4.5).
5. `make test-alerts` y los tests TC-O1 a TC-O4 pasan.
6. Los nombres de métrica y las etiquetas coinciden exactamente con §3.1–3.2, y aparecen solo en `PrometheusComparisonMetrics`.

## 12. Fuera de alcance y evolución hacia producción

Cosas que un sistema real añadiría y que aquí se dejan fuera de forma consciente (varias se registran como alternativas descartadas en `decisions.md`):

- **Telemetría del navegador** (tiempo de carga percibido por el cliente, por móvil y escritorio): especificada en §2.3.
- **Alertmanager** para enrutar y agrupar notificaciones a Slack, correo o guardia; y **burn rate multiventana** sobre SLOs formales en lugar de umbrales simples.
- **Logs en Grafana** (Loki con un agente de recogida) para correlacionar logs y métricas en el mismo dashboard por `request_id`. El formato JSON de §4 ya está pensado para ello.
- **Trazas distribuidas** (OpenTelemetry): permitirían ver dentro de una comparación cada llamada a un provider como un tramo.
- **Métricas del propio PHP-FPM** (cola de espera y workers ocupados): detectarían directamente la saturación del pool que hoy solo se sospecha de forma indirecta (§6.2).
- **Redis como almacén de contadores** si hubiera más de una instancia de backend, en lugar de APCu (§3.5).
- **Protección de `/metrics` y de Grafana** por red y credenciales, y retención de datos más larga que los 2 días de este setup.

## 13. Referencia cruzada

- `ProviderResult`, `elapsedMs`, `ProviderFailureReason` y los perfiles de latencia y fallo de cada provider, en `providers.md` §1 y §3; el timeout único y su margen, en `providers.md` §5.1.
- `providerStatuses`, los códigos `200`/`400`/`500` y el flujo `CompareQuotes`, en `architecture.md` §4 y §6; ubicación de todo el código de observabilidad, en `architecture.md` §7.2; estructura de la raíz, en §7.1.
- Los códigos de regla (`rule`) de los `400` y la excepción `InvalidQuoteRequest`, en `product.md` §1.5.
- Los tests TC-O1 a TC-O4 y su ejecución, en `testing.md`.
- Las alternativas consideradas y descartadas (métricas de navegador, Loki, Alertmanager, OpenTelemetry, Redis, alertas gestionadas por Grafana, un solo puerto para métricas y logs), en `decisions.md`.
