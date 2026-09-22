# Decisiones de diseño y alternativas descartadas

Este documento recoge, para cada punto donde las specs originales dejaban un hueco (una pregunta implícita, una invención posible, o varias soluciones razonables), la decisión que tomé, las alternativas que consideré y descarté, y por qué. Surge de revisar tres iteraciones de implementación contra `providers.md`, `architecture.md`, `testing.md` y `product.md`, cerrando cada hueco en la spec correspondiente antes de continuar. El cuaderno de trabajo completo (hallazgos clasificados como pregunta/invención/contradicción, con su resolución) queda fuera de esta entrega; aquí dejo solo las decisiones ya cerradas.

## 1. Puerto de provider en dos pasos (concurrencia)

**Specs afectadas:** `providers.md` §1, §2, §4 · `architecture.md` §1, §3, §6, §7.2

**Decisión:** dividí el puerto en dos pasos: `ProviderPort::start(QuoteRequest): PendingQuote` lanza la consulta sin bloquear, y `PendingQuote::collect(): ProviderResult` la resuelve. `ComparisonService` llama a `start()` en los tres adapters (quedan en vuelo a la vez) y recoge con `collect()` en el orden en que los lanzó — no "a medida que completan".

Ni `start()` ni `collect()` lanzan excepciones por fallos esperados: un fallo al lanzar la consulta devuelve directamente un `PendingQuote` ya resuelto a `failure(...)`. Solo los errores de programación se propagan como excepción.

**Alternativas descartadas:**
- Fibers de PHP, documentados como mecanismo — añaden una capa de complejidad (scheduler propio) que no hacía falta para tres llamadas HTTP en paralelo.
- Dejar el mecanismo de concurrencia libre en la spec — es justo el tipo de hueco que un agente distinto rellenaría de forma distinta cada vez; lo cerré explícitamente.
- Una interfaz asíncrona separada, conviviendo con el `quote()` síncrono original — dos contratos para un mismo concepto complica más de lo que resuelve.
- Que los errores de `start()` los capture el orquestador (`ComparisonService`) en vez del propio `PendingQuote`, o que se difieran hasta `collect()` — preferí que cada `PendingQuote` sea autocontenido y ya sepa si su lanzamiento falló.

## 2. Techo de tiempo, TIMEOUT y elapsedMs

**Specs afectadas:** `providers.md` §1.1, §1.2, §5.1

**Decisión:** el techo de tiempo total es `max_duration`, contado desde `start()` — no el `timeout` de inactividad de `request()`, que es otra cosa. `elapsedMs` sale de `total_time` del transporte cuando está disponible; si no lo está (ausente o no positivo), cae a un `ClockInterface` inyectado en el adapter, medido entre `start()` y la resolución. Clasifico como `TIMEOUT` cualquier fallo de transporte que llega habiendo pasado al menos `timeout_ms` desde `start()` según el reloj de la aplicación; cualquier otro fallo de transporte es `UNKNOWN_ERROR`.

**Alternativas descartadas:**
- Medir `elapsedMs` solo con el reloj de la aplicación, sin usar `total_time` del transporte cuando está disponible — menos preciso teniendo el dato del transporte a mano.
- Recogida por llegada (`stream()`) en vez de por orden de lanzamiento — cambia la semántica de "tres consultas en vuelo, tres resultados en orden estable" que quería mantener.
- Clasificar `TIMEOUT` por el texto de la excepción de curl — en Symfony 7.4.19 `CurlResponse` lanza una `TransportException` genérica con el texto de libcurl, sin subclase ni código propio; depender de ese texto es frágil.

**Límite conocido, aceptado:** la regla de `TIMEOUT` mide hasta el momento de la *recogida*, no del fallo real. Con los tres providers actuales (latencia máxima simulada 1800 ms, techo 2000 ms) esto no llega a darse, pero un fallo de transporte no-timeout recogido tarde (tras acumular ≥2000 ms desde `start()`) se clasificaría como `TIMEOUT` aunque no lo sea. Lo dejo anotado en vez de complicar la spec para un caso que no ocurre con los providers definidos.

## 3. Tests, lint y cobertura en contenedor

**Specs afectadas:** `testing.md` §4.1, §9, §11

**Decisión:** `make test`, `make lint` y `make coverage` corren dentro de un contenedor (imagen/stage de desarrollo del backend, con PHPUnit, PHPStan, ECS, Rector y PCOV) para que quien evalúe solo necesite Docker y las versiones de herramientas coincidan con las de la app — evita el desajuste que tenía entre PHP 8.5 en mi host y PHP 8.4 en la imagen de la app. `make test-realtime` y `make test-e2e` se quedan en el host: meter Playwright en contenedor añade una imagen grande, red y fallos intermitentes que no compensan a estas alturas del plazo.

**Alternativas descartadas:**
- Host con toolchain declarado (fijar versiones exactas en `composer.json` y en `engines` de Node, y listarlas en el README) — la dejo como plan de reserva si el calendario aprieta, pero no resuelve el desajuste de raíz.
- Exigir ambas vías (host y contenedor) — duplica mantenimiento sin aportar nada.
- Meter también `test-realtime` y `test-e2e` en contenedor — descartado por el mismo motivo de Playwright.

**Confirmación posterior:** al containerizar, `make lint` falló en un contenedor limpio porque PHPStan no encontraba `var/` (mi host lo tenía creado de sesiones anteriores y nunca lo vi fallar ahí). Es justo la clase de diferencia host-contenedor que esta decisión buscaba evitar que llegara a quien evalúa.

## 4. Comportamiento simulado y fecha de referencia

**Specs afectadas:** `providers.md` §3 · `product.md` §1

- Los simuladores de provider devuelven un precio determinista derivado del `QuoteRequest`; la fórmula concreta no forma parte del contrato del puerto. El adapter simulado usa el reloj inyectado, no la fecha del sistema directamente.
- "Hoy" es la fecha UTC del servidor, que es la autoridad; el selector de fecha del frontend usa la hora local del navegador, así que tolero hasta un día de discrepancia entre uno y otro en vez de sincronizarlos server-side.

## 5. Organización de los adapters

**Specs afectadas:** `architecture.md` §7.2

El código común a los tres adapters (clase base, helpers de payload) vive en la raíz de `Infra/ThirdParty`; lo específico de cada provider, en su propia carpeta. Alternativa descartada: una clase base distinta por provider sin raíz compartida — hubiera duplicado lo común tres veces.

## 6. Alcance de Rector

**Specs afectadas:** `testing.md` §4.1

**Decisión inicial:** limité Rector al set `deadCode` únicamente. Sets más amplios reescribían 61 ficheros ya existentes, fuera del alcance de esa iteración.

**Ampliación posterior:** antes de ampliarlo, comprobé manualmente con un `rector.php` temporal (fuera del repo) que sumar `codeQuality`, `typeDeclarations`, `earlyReturn` y modernización a PHP 8.4 afectaría a 35 ficheros, ninguno con cambio de comportamiento. Con ese alcance ya acotado, lo apliqué: promoción a `readonly class` donde ya todas las propiedades lo eran, métodos privados estáticos pasados a métodos de instancia, tipos de retorno en arrow functions, y `null === $x` / `null !== $x` sustituido por `$x instanceof Clase`.

**Alternativa descartada:** quedarme solo con `deadCode` por prudencia de alcance/plazo — la descarté una vez la comprobación manual mostró que el volumen era manejable y sin riesgo funcional.

**Efecto secundario aceptado:** en 6 de las sustituciones por `instanceof`, Rector escribió el nombre completamente cualificado de la clase en vez del nombre corto ya importado (ECS no lo marca; no está en su configuración). Sin riesgo funcional — lo acepto tal cual en vez de abrir una cuarta iteración solo para pulirlo.

## 7. Separación Application/Infra en tests

**Specs afectadas:** `architecture.md` §2 (inversión de dependencias)

Detecté que un test en `Application/Compare` importaba directamente `LazyPendingQuote` de `Infra/ThirdParty` — una violación de capas: los tests de `Application` no deben depender de clases concretas de `Infra`, solo de la interfaz `PendingQuote` (o un doble de test). Lo corregí con un doble de test (`FakePendingQuote`) que sustituye el import directo.

## 8. Aceptado sin cambio de spec

- Symfony 7.4 / PHP 8.4 se mantienen tal como quedaron desde la primera iteración; no hubo motivo para revisarlos después.
- Estilo ECS sin espacios alrededor de `.` (concatenación) — es el estilo por defecto del preset que uso; no vi razón para cambiarlo.
- `collect()` no impide una segunda llamada, aunque la spec diga "exactamente una vez" (`providers.md` §1) — no añadí una guarda en tiempo de ejecución porque ningún camino del código actual llega a invocarlo dos veces; lo dejo anotado como límite conocido, no como algo resuelto.
