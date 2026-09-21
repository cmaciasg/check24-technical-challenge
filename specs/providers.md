# Providers — contrato, comportamiento simulado y estrategia de fallos

## 0. Alcance de este documento

Este documento fija:

- El contrato del puerto `ProviderPort` y los tipos de dominio asociados (`ComparisonOffer`, `ProviderResult`, `ProviderFailureReason`). `QuoteRequest` se usa aquí solo como entrada ya construida y validada — su definición completa (todos los campos) está en `product.md` §1; este documento no la fija. El VO `Price` usado por `ComparisonOffer` (ver §1.3) tampoco se fija aquí — está definido en `product.md` §4.2.
- La responsabilidad y los límites de cada adapter (`ProviderAAdapter`, `ProviderBAdapter`, `ProviderCAdapter`).
- El comportamiento simulado concreto de cada uno de los tres providers (latencia, tasa de fallo, formato de payload).
- La estrategia de timeout y aislamiento de fallos, con sus consecuencias testables.

Fuera de alcance de este documento (se referencian pero se definen en otro fichero de `/specs`):

- El mecanismo de concurrencia a nivel de infraestructura (cómo se ejecutan las tres llamadas en paralelo dentro de Symfony) — detalle completo en `architecture.md`.
- La lógica de descuento/subvención de campañas de marketing sobre el precio — es un paso posterior a `ComparisonOffer`, fuera del dominio de comparación con providers. Modelo completo (`CampaignDiscount`, `Price`) en `product.md` §4; aplicación en el flujo en `architecture.md` §6.
- La organización general de la suite de tests (herramientas, convenciones, estructura de carpetas de test) — `testing.md`.
- Las métricas y dashboards derivados de `elapsedMs` / `providerStatuses` — `observability.md`.
- La decisión de no usar payloads reales de integradores de un empleador anterior — registrada en `decisions.md`; este documento ya asume esa decisión y por eso todos los formatos de payload que siguen son inventados para el ejercicio.

## 1. Contrato del puerto Provider

```php
interface ProviderPort
{
    public function id(): ProviderId;

    /** Nombre comercial legible del provider, pensado para mostrarse al usuario. */
    public function name(): ProviderName;

    /**
     * Primer paso: construye el payload y lanza la consulta al provider sin esperar
     * su respuesta. Nunca lanza excepción por un fallo esperado del partner: si algo
     * falla al lanzar, devuelve un PendingQuote ya resuelto a ProviderResult::failure().
     * Solo debería propagar una excepción ante un error de programación (p. ej. un
     * QuoteRequest inválido que ya debería haber sido validado antes de llegar aquí).
     */
    public function start(QuoteRequest $request): PendingQuote;
}

interface PendingQuote
{
    /**
     * Segundo paso: espera el resultado (o el vencimiento del timeout) y lo traduce.
     * Nunca lanza excepción por un fallo esperado del partner (timeout, HTTP de error,
     * respuesta malformada): esos casos se modelan como ProviderResult::failure().
     * Se llama exactamente una vez por PendingQuote.
     */
    public function collect(): ProviderResult;
}
```

El puerto tiene dos pasos, y no un único `quote()` bloqueante, porque solo separando "lanzar" de "recoger" pueden solaparse las esperas de varios providers sin que el orquestador conozca cómo se comunica cada uno. `PendingQuote` es un tipo propio de `Application`, junto a `ProviderPort`: no expone ninguna clase de HTTP, así que el puerto no filtra infraestructura hacia el dominio. Un provider cuyo cálculo fuera síncrono devolvería un `PendingQuote` que ya lleva el resultado dentro. El aislamiento de fallos (§5.2) se mantiene en los dos pasos: ni `start()` ni `collect()` dejan escapar una excepción por un fallo esperado.

`ProviderId` es un value object, no un enum cerrado: el conjunto de providers es abierto por naturaleza (cada partner nuevo añade uno) y añadir un provider no debe obligar a tocar ningún fichero existente (`architecture.md` §1). Envuelve un texto con los mismos valores que se usan como `providerId` en el JSON de `POST /comparison` (`architecture.md` §4):

```php
final class ProviderId
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        // Valida: no vacío; minúsculas, dígitos y guiones; empieza por una letra (p. ej. 'provider-a').
        // Un valor inválido lanza una excepción de dominio: es un error de programación
        // de quien declara el id, no un fallo esperado de un partner.
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

Cada adapter declara su propio id como constante y lo devuelve en `id()` (p. ej. `ProviderAAdapter::ID = 'provider-a'`). Los tres de este ejercicio son `provider-a`, `provider-b` y `provider-c`. Los ids deben ser únicos entre los providers registrados.

`ProviderName` es el nombre comercial legible del provider (p. ej. `Provider A`), pensado para mostrarse al usuario. Es un value object que valida que no esté vacío. Cada adapter lo declara junto a su id y lo devuelve en `name()`. Es el texto que el frontend muestra tal cual: el frontend nunca deriva ni infiere un nombre a partir del `providerId` (`architecture.md` §4.2).

### 1.1 `ProviderFailureReason`

```php
enum ProviderFailureReason: string
{
    case TIMEOUT = 'timeout';
    case HTTP_ERROR = 'http_error';
    case MALFORMED_RESPONSE = 'malformed_response';
    // Reservado para errores de transporte no cubiertos por el simulador
    // (DNS, conexión rechazada). No debería producirse en este ejercicio,
    // pero el adapter lo captura igual para no dejar ninguna excepción sin
    // normalizar.
    case UNKNOWN_ERROR = 'unknown_error';
}
```

`TIMEOUT` es todo fallo de transporte que llega cuando han transcurrido, desde `start()`, al menos `app.providers.timeout_ms` según el reloj de la aplicación (`ClockInterface`). Cualquier otro fallo de transporte (DNS, conexión rechazada) es `UNKNOWN_ERROR`. La regla no depende del texto de ninguna excepción: el cliente HTTP informa de los vencimientos de formas distintas según el transporte y la versión, y ese texto no es una API estable.

### 1.2 `ProviderResult`

```php
final class ProviderResult
{
    private function __construct(
        public readonly ProviderId $providerId,
        public readonly ProviderName $providerName,
        public readonly bool $success,
        public readonly ?ComparisonOffer $offer,
        public readonly ?ProviderFailureReason $failureReason,
        public readonly int $elapsedMs,
    ) {
    }

    public static function success(ProviderId $id, ProviderName $name, ComparisonOffer $offer, int $elapsedMs): self
    {
        return new self($id, $name, true, $offer, null, $elapsedMs);
    }

    public static function failure(ProviderId $id, ProviderName $name, ProviderFailureReason $reason, int $elapsedMs): self
    {
        return new self($id, $name, false, null, $reason, $elapsedMs);
    }
}
```

`elapsedMs` se registra siempre, en éxito y en fallo (incluido el timeout, donde es aproximadamente el valor del timeout aplicado). Se mide con el `total_time` que informa el transporte para esa petición, y no con el reloj de la aplicación: como el orquestador recoge los resultados en orden, el reloj de la aplicación inflaría la latencia de un provider rápido que se recoge después de uno lento (C, que responde en 300 ms, saldría con ≈ 1500 ms si se recoge tras B). Si el transporte no informa ese dato (ausente o no positivo), se usa la diferencia entre `start()` y la resolución según el reloj de la aplicación (`ClockInterface`), que cada adapter recibe inyectado. Es el dato que `observability.md` usa como base de la latencia por provider.

`ProviderResult` es el informe que devuelve cada adapter y viaja sin traducir por la capa de aplicación: `ComparisonService` devuelve los tres `ProviderResult` tal cual (uno por adapter, siempre presente, éxito o fallo), el caso de uso `CompareQuotes` los combina con el resultado de la campaña (`architecture.md` §6) y el controlador los serializa a la respuesta pública de `POST /comparison`: los que tuvieron éxito alimentan `offers[]` (su `ComparisonOffer`) y los tres, éxito o fallo, alimentan `providerStatuses[]` (éxito/fallo + razón + `elapsedMs`). Nunca llega al frontend tal cual: la forma exacta de `offers[]` y `providerStatuses[]` está en `architecture.md` §4, no en este documento.

### 1.3 `ComparisonOffer` (forma mínima relevante para este documento)

```php
final class ComparisonOffer
{
    public function __construct(
        public readonly ProviderId $providerId,
        public readonly ProviderName $providerName,
        public readonly string $providerQuoteReference,
        public readonly Price $price,           // Money VO (importe + divisa) — definido en product.md §4.2
        public readonly CoverageLevel $coverageLevel,
    ) {
    }
}
```

`Price` sustituye a los dos primitivos sueltos (`priceAmountCents: int`, `currencyCode: string`) con los que se definió inicialmente este tipo — se encapsulan juntos en un VO (patrón Money) para no romper la coherencia con el resto del dominio, que ya modela todo como VOs. `Price` valida que el importe no sea negativo y que la divisa esté soportada (fija a `"EUR"` en este ejercicio), y expone `withDiscount(CampaignDiscount): Price`, que usa `architecture.md` §6 al aplicar la campaña de marketing — ningún adapter de este documento llama nunca a `withDiscount()`, ya que la campaña es un paso posterior y fuera de su alcance.

`CoverageLevel` es un enum de dominio compartido (`BASIC`, `STANDARD`, `PREMIUM`) al que cada adapter traduce el vocabulario propio de su provider (ver tabla en el punto 3).

## 2. Responsabilidad y límites de cada adapter

Cada `Provider{A,B,C}Adapter implements ProviderPort` tiene exactamente cuatro responsabilidades, repartidas entre los dos pasos del puerto (§1):

En `start()`:

1. **Traducir** el `QuoteRequest` de dominio al payload específico del provider (nombres de campo, anidamiento y formato propios — ver punto 3).
2. **Invocar** al provider simulado a través de un cliente HTTP inyectado, con el techo de tiempo único definido en el punto 5, y sin esperar la respuesta.

En `collect()`:

3. **Validar y traducir** la respuesta cruda a `ComparisonOffer`. Cualquier campo ausente, tipo inesperado o JSON inválido se trata como fallo de parseo, nunca como excepción no controlada.

En ambos pasos:

4. **Nunca dejar escapar una excepción** hacia el orquestador. Toda excepción de transporte (timeout, conexión) o de parseo se captura en el propio adapter y se normaliza a `ProviderResult::failure(...)`.

Además de estas cuatro, cada adapter declara su identidad (`id()` y `name()`) y recibe inyectados el cliente HTTP y el `ClockInterface` (para clasificar el `TIMEOUT` y, si hace falta, medir `elapsedMs`, §1.1 y §1.2); eso forma parte de implementar `ProviderPort` y no es una responsabilidad adicional de traducción ni de llamada.

Esta cuarta responsabilidad es la que garantiza el aislamiento de fallos a nivel de contrato: el orquestador (`ComparisonService`, en `architecture.md`) puede asumir siempre que `start()` y `collect()` devuelven, nunca lanzan, y que un provider roto no puede tumbar la comparación completa.

La traducción de payload (responsabilidad 1 y 3) es la parte de cada adapter que se testea de forma unitaria y determinista con fixtures, independientemente del simulador de latencia — ver TC-5 en el punto 5.3.

## 3. Comportamiento simulado de cada provider

Los tres providers son deliberadamente distintos para cubrir, entre los tres, todos los modos de fallo que menciona el brief: partners siempre lentos, partners con picos de latencia, y partners que devuelven un status distinto de 200 o un formato de error en lugar del habitual.

| Provider | Rol en el ejercicio | Latencia | Tasa de fallo | Formato de respuesta |
|---|---|---|---|---|
| A | Baseline rápido y fiable | ~U(80, 200) ms | 0% | JSON plano, más cercano a nuestro propio modelo |
| B | Partner "siempre lento" | ~U(1200, 1800) ms | 0% | Envelope anidado, snake_case, importe en céntimos |
| C | Partner con picos + fallos de formato/status | 85% normal ~U(150,400) ms / 10% pico ~U(2500,4000) ms / 5% fallo a latencia normal | 5% (repartido 3% HTTP 500 + 2% payload malformado) | Lista anidada, importe como string |

Los rangos de latencia y los porcentajes son constantes de configuración (`app.providers.simulation.*`), no valores hardcodeados en el adapter, para poder ajustarlos o forzarlos desde tests.

El importe que devuelve cada simulador es determinista: se deriva de los datos del `QuoteRequest` y de un factor propio de cada provider, para que los tres devuelvan precios distintos y reproducibles. Cada simulador devuelve además un nivel de cobertura fijo. La fórmula concreta no forma parte del contrato de este documento.

### 3.1 Provider A — rápido y fiable

Sirve de control: nunca falla y nunca se acerca al timeout. Es el provider contra el que se valida que el pipeline "feliz" (traducción, llamada, normalización) funciona sin ningún caso límite de por medio.

Petición (`QuoteRequest` → payload de A):

```json
{
  "applicant": { "birthDate": "1990-05-14" },
  "vehicle": { "type": "SEDAN", "usage": "PRIVATE" }
}
```

Respuesta (éxito, único caso posible):

```json
{
  "quoteReference": "A-7f3c1e",
  "premium": { "amount": 42.50, "currencyCode": "EUR" },
  "coverageTier": "STANDARD"
}
```

Mapeo de `coverageTier`: `STANDARD → CoverageLevel::STANDARD`, `BASIC → BASIC`, `PREMIUM → PREMIUM` (vocabulario idéntico al del dominio; A es intencionadamente el caso trivial).

`premium.amount` llega en unidades decimales (p. ej. `42.50`) y el adapter lo convierte a céntimos multiplicando por 100 y redondeando al céntimo más cercano — la misma regla que aplica el adapter de C (§3.3). Con un simple truncado (`(int)(19.99 * 100)`) la coma flotante daría 1998 en lugar de 1999, por lo que el redondeo explícito es obligatorio.

### 3.2 Provider B — consistentemente lento

Nunca falla y nunca supera el timeout (ver punto 5.2): representa un partner real que simplemente tiene un backend lento, no uno que esté caído. El único comportamiento a probar aquí es que la lentitud de B no bloquea ni retrasa a A ni a C.

Petición:

```json
{
  "driver_profile": { "date_of_birth": "1990-05-14" },
  "car_info": { "category": "sedan", "commercial_use": false }
}
```

Respuesta (éxito, único caso posible):

```json
{
  "result": {
    "quote": {
      "ref": "B-9182",
      "premium_cents": 4890,
      "currency": "EUR",
      "tier": "STD"
    }
  },
  "meta": { "processedInMs": 1532 }
}
```

Mapeo de `tier`: `STD → STANDARD`, `BSC → BASIC`, `PRM → PREMIUM`. `premium_cents` se copia directo al `amountCents` de `Price` (ya viene en céntimos, a diferencia de A que viene en unidades decimales).

### 3.3 Provider C — picos de latencia + fallos de formato/status

Es el único provider con más de un resultado simulado posible por llamada. El resultado se decide por una probabilidad fija en cada invocación:

- **85% — normal**: latencia ~U(150, 400) ms, respuesta válida.
- **10% — pico**: latencia ~U(2500, 4000) ms. Este rango está definido íntegramente por encima del timeout (2000 ms, punto 5.1) a propósito: un pico de C debe traducirse siempre en `TIMEOUT` desde el punto de vista del orquestador, sin zona gris probabilística cerca del límite.
- **5% — fallo a latencia normal** (~U(150, 400) ms, para no confundirse con un pico): de este 5%, un 3% devuelve HTTP 500 y un 2% devuelve HTTP 200 con un payload malformado.

Petición:

```json
{
  "customer": { "dob": "1990-05-14" },
  "asset": { "vehicleType": "SEDAN", "usageType": "PRIVATE" }
}
```

Respuesta (resultado normal, éxito):

```json
{
  "quotes": [
    { "id": "C-551a", "amount": "39.90", "currency": "EUR", "level": "BASIC" }
  ]
}
```

Respuesta (resultado fallo — HTTP error, 3%): HTTP 500 con cuerpo `{"error": "internal_error"}` (o cuerpo vacío; el adapter no depende del contenido, solo del status code fuera del rango 2xx → `ProviderFailureReason::HTTP_ERROR`).

Respuesta (resultado fallo — payload malformado, 2%): HTTP 200 pero con `quotes: []`, o con el campo `amount` ausente, o JSON truncado. Cualquiera de estos casos → `ProviderFailureReason::MALFORMED_RESPONSE`. El adapter valida explícitamente la presencia y el tipo de cada campo antes de construir el `ComparisonOffer`; no se apoya en que un `json_decode` sin errores implique una respuesta válida.

Mapeo de `level`: `BASIC → BASIC`, `MID → STANDARD`, `TOP → PREMIUM`. `amount` llega como string decimal y se convierte a céntimos multiplicando por 100 y redondeando — es el único de los tres providers cuyo importe no llega ya en un formato numérico directo, precisamente para forzar esa conversión en el adapter.

### 3.4 Determinismo para tests

El resultado simulado de cada provider (y, dentro de C, cuál de sus tres tipos: normal, pico o fallo) se decide a través de una dependencia inyectada (p. ej. `ProviderOutcomePicker`), no con `random_int`/`mt_rand` llamados directamente en el simulador. En producción-del-ejercicio se usa una implementación real basada en las probabilidades del punto 3; en tests de orquestación se sustituye por una implementación que fuerza un resultado concreto de un provider concreto, sin depender de la semilla aleatoria. En el stack de test con servidor real (`testing.md` §3.4), ese mismo punto de sustitución se configura mediante variables de entorno que fijan el resultado (y, si hace falta, la latencia) de cada provider sin tocar código. Sin este punto de sustitución no sería posible escribir las consecuencias testables del punto 5.3 de forma reproducible.

## 4. Mecanismo de concurrencia (resumen)

El orquestador (`ComparisonService`) lanza la consulta de todos los providers antes de esperar la respuesta de ninguno: primero llama a `start()` en cada adapter y después a `collect()` en cada `PendingQuote`, en el orden en que se lanzaron. Como todas las peticiones ya están en vuelo cuando empieza la recogida, sus esperas se solapan y el tiempo total no es la suma de las latencias individuales. Cada adapter usa las peticiones asíncronas de Symfony HttpClient (`request()` sin resolver la respuesta hasta `collect()`) contra endpoints internos que simulan cada provider como si fueran sistemas HTTP externos reales. Recoger en orden no penaliza el tiempo total: cada respuesta se lee cuando le llega el turno, pero su techo de tiempo corre desde `start()`. El detalle de implementación (rutas internas, aislamiento del código de simulación respecto al dominio) se documenta en `architecture.md`; este documento asume únicamente que `start()` no espera la respuesta, que `collect()` no devuelve hasta que el resultado (éxito o fallo) está resuelto o vence el techo, y que ese techo se aplica de forma independiente por provider: el retraso de uno no consume el presupuesto de tiempo de otro.

## 5. Estrategia de timeout y aislamiento de fallos

### 5.1 Timeout único

`PROVIDER_TIMEOUT_MS = 2000` (2000 ms), configurable vía parámetro de Symfony (`app.providers.timeout_ms`), aplicado por igual a los tres providers.

El techo se aplica como duración máxima total de la petición (`max_duration` del cliente HTTP), contada desde `start()`, y lo hace cumplir el transporte. No se usa la opción `timeout`: en Symfony HttpClient es un timeout de inactividad (segundos sin recibir datos) y no limita la duración total.

Se usa un único valor para los tres en lugar de un timeout ajustado por SLA de cada partner — una simplificación deliberada para este ejercicio (una implementación real probablemente tendría un timeout por partner basado en su SLA contractual). Queda registrada como alternativa considerada y descartada en `decisions.md`.

El valor 2000 ms no es arbitrario respecto a los perfiles de latencia del punto 3: es mayor que el máximo de B (1800 ms, para que B nunca produzca un timeout espurio) y menor que el mínimo del pico de C (2500 ms, para que un pico de C produzca timeout siempre, sin casos límite ambiguos).

### 5.2 Garantía de aislamiento

Un fallo, timeout o respuesta malformada de un provider:

- No debe retrasar la resolución de los otros dos.
- No debe propagar una excepción que rompa la petición HTTP completa.
- No debe impedir que la comparación devuelva los resultados de los providers que sí respondieron a tiempo.

En consecuencia, el tiempo total de `POST /comparison` está acotado por `PROVIDER_TIMEOUT_MS` más un pequeño margen de overhead (agregación, serialización), nunca por la suma de las latencias individuales ni por el peor caso de cualquier provider en particular.

### 5.3 Consecuencias testables

- **TC-1 (aislamiento de fallo total de un provider)**: forzando (vía el punto de sustitución del punto 3.4) que C falle siempre con un resultado concreto, `POST /comparison` devuelve 200 con las ofertas de A y B, y el estado de C en `providerStatuses` refleja la razón forzada. El resultado y el tiempo de A y B no varían respecto a una ejecución sin fallos.
- **TC-2 (techo de tiempo de respuesta)**: forzando que cualquier provider supere `PROVIDER_TIMEOUT_MS`, el tiempo total de `POST /comparison` no supera `PROVIDER_TIMEOUT_MS` + un margen acordado (p. ej. 150 ms) — nunca el tiempo simulado real de ese provider.
- **TC-3 (fallo total de los tres providers)**: forzando que A, B y C fallen simultáneamente, `POST /comparison` sigue devolviendo 200 (nunca 500) con `offers: []` y los tres providers en `failed` dentro de `providerStatuses`. Un fallo de partners nunca se traduce en un fallo de nuestra propia API.
- **TC-4 (el Provider B nunca produce un timeout espurio)**: se verifica en dos partes. (a) Invariante de configuración, que recorre todos los providers configurados en lugar de nombrar a B y C: ninguna latencia de un resultado normal (no pico) de ningún provider supera `app.providers.timeout_ms` (2000 ms), y ninguna latencia de un resultado de pico de ningún provider queda por debajo de él. Con la configuración actual equivale a: máximo del Provider B (1800 ms) < 2000 ms < mínimo del pico del Provider C (2500 ms). Es instantáneo, falla si alguien cambia un rango y rompe el margen, y cubre automáticamente cualquier provider nuevo. (b) Una única ejecución real con la latencia del Provider B fijada en su máximo documentado (1800 ms): el resultado debe ser un éxito y no `failureReason = TIMEOUT`, lo que comprueba que el margen de 200 ms cubre el sobrecoste real de la llamada. Detalle en `testing.md` §3.4 y §6.
- **TC-5 (traducción de adapter independiente del timing)**: cada adapter tiene tests unitarios que alimentan fixtures de respuesta cruda (éxito, malformada, error) sin pasar por el simulador de latencia, verificando solo la función de traducción `QuoteRequest → payload` y `payload crudo → ComparisonOffer | ProviderResult::failure`. Estos tests son deterministas y no dependen de temporizadores ni de concurrencia.
- **TC-6 (determinismo del simulador)**: existe un punto de sustitución (el `ProviderOutcomePicker` del punto 3.4) que permite forzar un resultado simulado concreto de un provider concreto en un test de orquestación, sin depender de la probabilidad real. Sin este punto de sustitución, TC-1 a TC-4 no serían reproducibles de forma fiable.
- **TC-7 (la latencia de un provider no depende del orden de recogida)**: con un provider rápido recogido después de uno lento (por ejemplo, A o C en su resultado normal recogido después de B), el `elapsedMs` de un resultado con éxito refleja el tiempo que el transporte informa para su propia petición, y no el tiempo que esperó a ser recogido. Cuando el transporte no informa ese dato, `elapsedMs` sale de la diferencia entre `start()` y la resolución según el reloj inyectado. Detalle de los tests en `testing.md` §6.

## 6. Referencia cruzada

- El contrato del endpoint `POST /comparison` (incluyendo la forma exacta de `providerStatuses` que agrega los `ProviderResult` de los tres providers, y cómo se integra en la respuesta el precio con campaña) se define en `architecture.md`.
- La definición completa de `QuoteRequest` y del VO `Price` usado por `ComparisonOffer` está en `product.md` §1 y §4.2 respectivamente.
- La decisión de no usar payloads ni contratos reales de integradores de un empleador anterior, y la inspiración en patrones reales de integraciones B2B de seguros sin copiar datos concretos, está registrada en `decisions.md`.
- Las métricas derivadas de `elapsedMs` y de las razones de fallo por provider (tasa de error por partner, p95 de latencia, etc.) se especifican en `observability.md`.
