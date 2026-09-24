# Testing

## 0. Alcance de este documento

Este documento no fija ningún comportamiento nuevo del sistema. Da forma ejecutable a las consecuencias testables que ya dejaron definidas los otros documentos de `/specs`:

- Las siete de `providers.md` §5.3 (TC-1 a TC-7).
- Las siete de `product.md` §4.5 (TC-P1 a TC-P7).
- Las cuatro de `observability.md` §10 (TC-O1 a TC-O4).

Cada TC (Test Case) es una consecuencia observable de una decisión de producto o arquitectura. Este documento decide, sin pedir aclaraciones a nadie:

- En qué nivel se prueba cada TC (unitario, integración, end-to-end) y por qué.
- Cómo se prueba el aislamiento de fallos y los timeouts de providers sin depender de aleatoriedad real, y dónde sí se acepta tiempo real.
- Qué herramientas se usan en backend y en frontend.
- La estructura de carpetas de test.
- Cómo se prueban en frontend la persistencia en `sessionStorage` y el store del formulario.
- Qué se considera "suficientemente testeado" y qué se deja fuera a propósito.
- Cómo se ejecuta cada nivel, en local y en CI.

Fuera de alcance: qué métricas, logs, dashboards y alertas existen y por qué (`observability.md`; aquí solo se prueba que existen y se comportan como se especifica, TC-O1 a TC-O4) y las alternativas descartadas de cada decisión (`decisions.md`).

## 1. Criterio general

Tres reglas guían todo lo que sigue:

1. **Cada TC se prueba en el nivel más bajo en el que sigue teniendo sentido.** Se sube de nivel solo cuando el comportamiento no existe en el inferior: el timeout real del transporte, el cableado HTTP, la recarga real de un navegador.
2. **Los tests son deterministas por defecto.** Ninguno depende de aleatoriedad real ni, salvo los marcados expresamente como de tiempo real (§3.4), del reloj.
3. **El tiempo real se acepta solo donde es imprescindible.** Comprobar que el techo de tiempo lo cumple el transporte y que las tres llamadas son concurrentes de verdad no se puede demostrar con mocks, porque un mock no puede dejar de esperar por sí mismo.

## 2. Niveles de test

| Nivel | Qué prueba | Qué no prueba | Herramientas |
|---|---|---|---|
| **Unitario** | Piezas aisladas: value objects, adapters con respuestas de ejemplo, `ComparisonService` con providers falsos, `CampaignPricingService`, picker con generador inyectado, store y componentes de Vue. Sin red, sin kernel de Symfony, sin reloj, sin azar. | Cableado entre piezas, HTTP real. | PHPUnit, Vitest |
| **Integración** | El contrato HTTP de `POST /comparison` con el kernel de Symfony real: códigos 400/200, forma de `offers` y `providerStatuses`, configuración de campaña cableada de verdad. Los providers simulados se contestan dentro del mismo proceso, sin HTTP real (§3.2). | El timeout real del transporte, la concurrencia real. | PHPUnit (`WebTestCase`) |
| **Tiempo real** (suite de integración aparte) | Que el techo de tiempo lo cumple el transporte con las tres llamadas concurrentes (TC-2) y que el margen del Provider B es suficiente en la práctica (TC-4, parte real). Servidor real levantado, llamadas HTTP reales. | Todo lo que ya cubren los niveles anteriores. | PHPUnit contra el stack de `docker-compose.test.yml` |
| **End-to-end (E2E)** | Sistema completo con un navegador real: Vue, Symfony y providers simulados. Solo lo que no existe sin navegador (recarga real de la pestaña) y la prueba de que el conjunto está bien cableado, en móvil y en escritorio. | Lógica ya cubierta abajo. | Playwright |

La lógica de negocio (validación, campaña, traducción de payloads, agregación de resultados) vive y se prueba en el nivel unitario. La integración y el E2E comprueban que las piezas ya probadas se conectan bien, no repiten esa lógica.

## 3. Determinismo: azar, tiempo y providers

### 3.1 El problema

Los providers simulados son endpoints HTTP dentro de la propia aplicación (`architecture.md` §3) y el azar de cada uno (¿C falla?, ¿C hace un pico de latencia?) se decide en ese lado, a través del punto de sustitución `ProviderOutcomePicker` (`providers.md` §3.4). Un test necesita poder decir "en esta llamada, C falla" y que ocurra siempre.

### 3.2 Tests unitarios e integración: sin HTTP real

- **Adapters (unitarios):** reciben un `MockHttpClient` de Symfony que devuelve respuestas de ejemplo (`Fixtures/`) o simula un `TimeoutException`/`TransportException` sin esperar nada. Se comprueba que cada adapter traduce bien, y que normaliza todo fallo a `ProviderResult::failure(...)` sin dejar escapar ninguna excepción.
- **`ComparisonService` (unitario):** recibe implementaciones falsas de `ProviderPort` que devuelven de inmediato un éxito o un `ProviderResult::failure(...)` concreto. Se verifica que devuelve un informe por provider y el aislamiento: el fallo de un provider no impide que salgan los otros ni lanza ninguna excepción.
- **Endpoint `POST /comparison` (integración):** en el entorno `test` (`when@test`), el cliente HTTP inyectado en los adapters se sustituye por uno que atiende la petición llamando al código del simulador dentro del mismo proceso. El `ProviderOutcomePicker` es una implementación forzada (`ForcedOutcomePicker`) que fija el resultado exacto de cada provider. Las latencias simuladas (`app.providers.simulation.*`, `providers.md` §3) se ponen a 0, así que ningún test espera. Un resultado de "pico de latencia" se traduce en el cliente en proceso en una `TimeoutException` inmediata.

Este modo no exige ningún cambio en el código de la aplicación: usa el cliente HTTP inyectado (`architecture.md` §2), el picker sustituible (`providers.md` §3.4) y las latencias ya parametrizadas (`providers.md` §3).

### 3.3 Por qué no basta con esto

La decisión de `architecture.md` §3 fue simular los providers con HTTP real de loopback precisamente para que timeout y concurrencia fueran reales. Un cliente en proceso invalida esa propiedad en los tests, y por eso no puede ser lo único que haya. Sin tiempo real, quedarían sin comprobar tres cosas:

- Que el timeout se pasa de verdad al cliente HTTP.
- Que las tres llamadas son concurrentes: si alguien las resolviera una a una, el total sería aproximadamente 150 + 1500 + 2000 ≈ 3,6 s en lugar de ≈ 2 s, y con mocks eso pasaría desapercibido.
- Que el sobrecoste real de una llamada (HTTP, PHP, serialización) cabe en el margen de 200 ms que deja el timeout sobre el Provider B en su caso más lento (1800 ms frente a 2000 ms).

### 3.4 Suite de tiempo real

Se ejecuta contra la aplicación levantada de verdad con `docker-compose.yml` más `docker-compose.test.yml`. Este segundo fichero solo añade la configuración de test: fuerza el resultado de cada provider y, si hace falta, la latencia, mediante variables de entorno que sobrescriben los parámetros `app.providers.simulation.*` (por ejemplo, `SIMULATION_FORCE=provider-c=spike`). El nombre exacto de las variables es un detalle de implementación; el requisito es que exista una forma de fijar por variable de entorno el resultado y la latencia de cada provider.

Requisito del stack de test: el servidor de PHP debe poder atender al menos **cuatro** peticiones concurrentes (la de `POST /comparison`, que espera, más las tres de los providers simulados). Con menos, las llamadas a los simulados se serializarían y el test mediría un artefacto del entorno y no del sistema.

Contiene exactamente dos casos:

- **TC-2:** se fuerza un pico de C (latencia fija de 3000 ms, por encima del timeout). Se comprueba que el tiempo total de `POST /comparison` es menor que `PROVIDER_TIMEOUT_MS` más un margen; que C aparece como `failed` con razón `timeout`; y que A y B aparecen `ok` con su propio `elapsedMs` dentro de sus rangos documentados, sin haber sido retrasados por C (esto cubre la parte de tiempo de TC-1). El margen es 150 ms por defecto y se puede ajustar con la variable de entorno `TEST_TIMING_MARGIN_MS`, para no producir falsos rojos en un CI lento y compartido.
- **TC-4 (parte real):** se fija la latencia del Provider B en su máximo documentado (1800 ms) y se hace una única llamada. Se comprueba que B aparece `ok` y no `failed` por `timeout`.

## 4. Herramientas

### 4.1 Backend (Symfony/PHP)

- **PHPUnit** para unitarios, integración y tiempo real. Tres suites en `phpunit.xml.dist`: `unit`, `integration` y `realtime`.
- **`WebTestCase` / `KernelTestCase`** de Symfony para la suite de integración.
- **`MockHttpClient` / `MockResponse`** de Symfony para simular respuestas y errores de transporte en los tests de adapters.
- **PCOV o Xdebug** para el informe de cobertura (§9).
- **PHPStan, ECS y Rector** como análisis estático y estilo, igual que en el resto del código. Rector aplica los sets `deadCode`, `codeQuality`, `typeDeclarations` y `earlyReturn`, más la modernización a PHP 8.4 (`withPhpSets(php84: true)`).
- Todas las herramientas de esta sección (PHPUnit, PHPStan, ECS, Rector, PCOV) se instalan en la imagen de desarrollo del backend y se ejecutan en contenedor (§11), no en el host.
- Cada test lleva un atributo `#[Group('TC-x')]` con el identificador del TC que ejercita, para poder ejecutar `phpunit --group TC-1`.

### 4.2 Frontend (Vue)

- **Vitest** como runner de tests unitarios y de componentes. Es el equivalente de PHPUnit en el ecosistema Vite/Vue.
- **Vue Test Utils** para montar componentes en memoria, interactuar con ellos y comprobar lo que pintan.
- **Pinia** como store del formulario (`architecture.md` §5), probado directamente con Vitest.
- **happy-dom** como entorno de test: simula un navegador dentro de Node y aporta un `sessionStorage` real en memoria, por lo que no hace falta escribir ningún mock de `sessionStorage`.
- **Playwright** para los E2E, con un navegador real.
- **`vue-tsc` y ESLint** como análisis estático (comprobación de tipos de TypeScript y reglas de estilo), el equivalente en frontend de PHPStan y ECS.

Convención en los tests de componentes: los elementos se localizan por su etiqueta o su rol visible (el texto que ve el usuario), no por clases CSS, para que los tests sigan siendo válidos si cambia el estilo.

### 4.3 Observabilidad

- **`promtool`**, la herramienta de Prometheus para validar y probar reglas de alerta (`promtool check rules` y `promtool test rules`; es el equivalente de PHPUnit para alertas: se le dan series de entrada sintéticas y se comprueba qué alertas se disparan). Se ejecuta desde la imagen oficial de Prometheus en un contenedor de un solo uso, sin instalar nada ni levantar el stack (`make test-alerts`, §11). Cubre TC-O4.
- Los tests de métricas y de logs usan las herramientas de backend de §4.1: un `ComparisonMetrics` en memoria y un capturador de logs como dobles de test (`Support/`), y el almacén en memoria de la librería de métricas en integración (TC-O1 a TC-O3).

## 5. Estructura de carpetas de test

```
docker-compose.yml
docker-compose.test.yml        # añade la configuración de test: resultado/latencia forzados por variable de entorno

backend/
  src/                          # Comparator/CarInsurance (Application / Domain / Infra / UI) y ProviderSimulation — architecture.md §7
  tests/
    Unit/                       # sin kernel, sin red, sin reloj; refleja la estructura de src/
      Comparator/CarInsurance/
        Domain/                 #   DateOfBirth, Price, CampaignDiscount, ...
        Application/            #   CompareQuotes, ComparisonService, CampaignPricingService
        Infra/ThirdParty/       #   una carpeta por provider (ProviderA, ProviderB, ProviderC), con respuestas de ejemplo
        Infra/Observability/    #   PrometheusComparisonMetrics con almacén en memoria (TC-O1)
      ProviderSimulation/       #   picker real; invariante de configuración de latencias
      Observability/            #   formato de los logs, subscribers de request_id y de excepciones
    Integration/                # WebTestCase; simulador atendido dentro del mismo proceso
    RealTime/                   # servidor real levantado (TC-2, TC-4 parte real)
    Fixtures/                   # respuestas crudas de ejemplo, una carpeta por provider: éxito, malformada, error
    Support/                    # dobles de test: ProviderPort falso, ForcedOutcomePicker, cliente HTTP en proceso,
                                # ComparisonMetrics en memoria, capturador de logs
  phpunit.xml.dist              # suites: unit, integration, realtime

frontend/
  src/
  tests/
    unit/                       # Vitest: store, validación por campo y componentes

e2e/                            # Playwright: escenarios E2E y su configuración

observability/
  prometheus/tests/
    alerts.test.yml             # tests de las reglas de alerta con promtool (TC-O4)
```

Los tests unitarios reflejan la estructura de `backend/src/` (bounded context `Comparator/CarInsurance` con sus capas, más `ProviderSimulation`), definida en `architecture.md` §7. Los tests de `UI/Http` no tienen carpeta unitaria propia: el controlador se prueba en la suite de integración, que llama al endpoint de verdad.

El E2E vive en `e2e/` en la raíz y no dentro de `frontend/` porque prueba el sistema completo, no solo el frontend. Los tests de Vue viven en `frontend/tests/unit/` en lugar de junto a cada fichero fuente, por simetría con el backend.

## 6. Trazabilidad: TC → tests

Cada TC tiene al menos un test cuyo nombre lleva su identificador. Un mismo TC puede tener tests en más de un nivel cuando cada nivel comprueba algo distinto.

| TC | Qué comprueba | Nivel y qué se verifica ahí |
|---|---|---|
| **TC-1** aislamiento de fallo de un provider | Si C falla, A y B siguen saliendo | **Unitario:** `ComparisonService` con `ProviderPort` falsos devuelve tres `ProviderResult`: A y B con su oferta, C fallido con la razón forzada; sin excepción. **Integración:** `POST /comparison` → 200, ofertas de A y B, `providerStatuses` con C `failed` y la razón forzada. **Tiempo real:** la parte de tiempo (A y B no retrasados) se comprueba dentro de TC-2. |
| **TC-2** techo de tiempo | El total no supera el timeout más un margen | **Tiempo real** (§3.4). |
| **TC-3** fallo total | Los tres fallan → 200 con `offers: []` | **Unitario:** `ComparisonService` no lanza y devuelve tres `ProviderResult` fallidos; `CompareQuotes` produce `offers` vacío. **Integración:** 200, `offers: []`, tres `failed` en `providerStatuses`. |
| **TC-4** el Provider B no da timeout falso | `1800 < 2000 < 2500` y margen real suficiente | **Unitario:** invariante de configuración que recorre todos los providers configurados: ninguna latencia normal supera `app.providers.timeout_ms` y ninguna latencia de pico queda por debajo de él (con la configuración actual: Provider B ≤ 1800 ms < 2000 ms < 2500 ms ≤ pico del Provider C); cubre automáticamente providers nuevos. **Tiempo real:** una llamada con el Provider B a 1800 ms → `ok`. |
| **TC-5** traducción del adapter | `QuoteRequest → payload` y `payload crudo → ComparisonOffer` o `failure` | **Unitario**, por cada adapter, con respuestas de `Fixtures/`: éxito, malformada, error HTTP y timeout simulado; tabla de mapeo de `CoverageLevel` completa; conversión del importe a céntimos; clasificación de fallos de transporte con un reloj inyectado: con al menos `timeout_ms` transcurridos es `TIMEOUT`, con menos es `UNKNOWN_ERROR`. |
| **TC-6** determinismo del simulador | El picker permite forzar un resultado concreto | **Unitario:** el picker real, con generador de números inyectado, devuelve el resultado esperado para valores situados en cada frontera entre resultados de cada provider. Además, todos los tests de integración lo ejercitan al usar `ForcedOutcomePicker`. |
| **TC-7** la latencia no depende del orden de recogida | Un provider rápido recogido tras uno lento conserva su propio `elapsedMs` | **Unitario:** un adapter con `MockResponse` cuyo `total_time` está fijado (por ejemplo, 0,3 s) se recoge después de otro cuyo `total_time` es 1,5 s; su `elapsedMs` es ≈ 300 ms y no ≈ 1500 ms. Un segundo test sin `total_time` comprueba el respaldo: `elapsedMs` sale de la diferencia entre `start()` y la resolución según el reloj inyectado. |
| **TC-P1** `QuoteRequest` inválido | 400 y ningún provider invocado | **Unitario:** `DateOfBirth`, `VehicleType`, `UsageType` (casos límite en §8). **Integración:** `POST /comparison` con entrada inválida → 400, y un adapter espía confirma cero llamadas. |
| **TC-P2** persistencia sobrevive a recarga | Lo escrito (incluso inválido) se recupera | **Unitario (frontend):** el store se rellena y se crea un store nuevo desde cero, que es lo que ocurre al recargar; recupera exactamente lo escrito. **E2E:** recarga real de la pestaña. |
| **TC-P3** persistencia se limpia solo en éxito | 200 limpia; 400 y 500 conservan | **Unitario (frontend):** el store con una llamada a la API falsa que responde 200, 400 o 500. |
| **TC-P4** campaña inactiva | Ninguna oferta con precio rebajado | **Unitario:** `CampaignPricingService`. **Integración:** la respuesta no contiene `discountedPriceAmountCents` ni `campaignApplied`. **Componente (frontend):** sin precio tachado. |
| **TC-P5** campaña `PERCENTAGE` | 4250 con el 10 % → 3825 | **Unitario:** `CampaignDiscount` y `CampaignPricingService`. **Integración:** una prueba de campaña de punta a punta con la configuración de Symfony sobrescrita. **Componente:** precio tachado junto al final. |
| **TC-P6** `FIXED_AMOUNT` con suelo en 0 | Nunca negativo | **Unitario:** descuento fijo mayor que el precio → 0. |
| **TC-P7** la campaña no distingue provider | Misma configuración para las tres ofertas | **Unitario:** `CampaignPricingService` con ofertas de los tres providers. |
| **TC-O1** cada resultado incrementa la métrica correcta | `outcome`, llamadas y duración por provider, overhead, campaña | **Unitario:** `CompareQuotes` con `ComparisonMetrics` en memoria, providers falsos (`complete`, `partial`, `empty`) y reloj fijo; con la campaña activa suma `campaign_discount_cents_total`. Los `400` y `500` incrementan `invalid` y `error`. |
| **TC-O2** ningún dato personal en logs | Ninguna línea contiene fecha de nacimiento ni tipos del `QuoteRequest` | **Integración:** capturador de logs; `POST /comparison` con una fecha reconocible válida, con otra inválida (`400`) y con un `500` forzado. Las líneas `comparison.rejected` llevan `field` y `rule`, no el valor. |
| **TC-O3** `/metrics` y cardinalidad | Series iniciales a 0; cambian tras una petición; etiquetas dentro de su conjunto cerrado | **Integración:** `GET /metrics` con almacén en memoria y el cliente con `disableReboot()`, para conservar el contenedor entre la petición y la lectura. |
| **TC-O4** alertas válidas y que saltan cuando deben | Reglas de `alerts.yml` | **`promtool`** (`make test-alerts`): validación de sintaxis y, por cada alerta, un caso que la dispara y otro que no; además la guarda de tráfico mínimo y el umbral por defecto frente a la excepción de `provider-c`. Se identifica por un comentario `# TC-O4` en cada caso de `alerts.test.yml`, no por `#[Group]`. |

## 7. Tests de frontend

### 7.1 Store del formulario y persistencia (TC-P2, TC-P3)

Con Vitest y el `sessionStorage` en memoria de happy-dom:

- **TC-P2:** se rellenan campos del store, incluido uno inválido o incompleto (una fecha a medias). Se crea un store nuevo desde cero, que es lo que ocurre al recargar la página, y se comprueba que recupera exactamente lo escrito, inválido incluido.
- **TC-P3:** la llamada a `POST /comparison` del store se sustituye por una falsa que responde 200, 400 o 500 sin tocar la red. Tras un 200, `sessionStorage` queda vacío para este formulario. Tras un 400 o un 500, los datos siguen ahí.
- **Validación por campo** (`product.md` §2): se prueba como funciones puras con los casos límite de §8, sin store ni navegador.

Lo que estos tests no demuestran es que `sessionStorage` sobreviva a una recarga real del navegador. Eso lo cubre el E2E (§8.2).

### 7.2 Componentes de la pantalla de resultados

Con Vue Test Utils, en memoria y sin navegador:

- Con la campaña activa se ve el precio original tachado junto al precio rebajado (`product.md` §4.4). Con la campaña inactiva, solo el precio normal.
- Un provider con estado `failed` en `providerStatuses` muestra un aviso de "no disponible" con su `providerName` (nunca un texto derivado del `providerId`) en lugar de desaparecer sin explicación (`architecture.md` §4.2).
- Con `offers: []` (los tres providers caídos) se ve un estado vacío comprensible, no una pantalla en blanco ni un error.
- Los estados de carga y de error de la API (400, 500) muestran un mensaje claro y no dejan la pantalla colgada.
- Con varias ofertas, se muestran ordenadas por precio efectivo ascendente (el rebajado si existe), con desempate por `providerName` (`architecture.md` §5.4). La función `sort-offers` se prueba además como función pura, con empates y con y sin campaña.
- Mientras la petición está en curso, el botón de envío está deshabilitado y los resultados anteriores no se ven (`state-loading`).
- Un `400` y un `500` o fallo de red muestran mensajes distintos: el `400` sin botón de reintento, el otro con "Reintentar" (`architecture.md` §5.3).
- El estado vacío incluye el botón "Volver a comparar" y los avisos de cada provider con su `providerName`.

## 8. Casos límite obligatorios y E2E

### 8.1 Casos límite

Además del camino feliz, los tests unitarios cubren siempre:

- **`DateOfBirth`:** edad de 17, 18, 99 y 100 años; cumpleaños exactamente hoy; fecha futura; formato distinto de ISO-8601; fecha inexistente (30 de febrero). Cada fallo lanza `InvalidQuoteRequest` con el `field` y el `ruleCode` esperados (`product.md` §1.5).
- **`QuoteRequest` (integración):** cuerpo con campos ausentes o de tipo incorrecto → 400 (`architecture.md` §4.1, "formato inválido"); `vehicleType` o `usageType` fuera de catálogo.
- **`CampaignDiscount`:** porcentaje del 0 y del 100 %; descuento fijo mayor que el precio; redondeo half-up cuando el resultado cae en medio céntimo.
- **Conversión de importes a céntimos en los adapters:** importes con decimales que en coma flotante no son exactos (p. ej. 19.99), para comprobar que se redondea y no se trunca.
- **Los tres tipos de fallo** (`timeout`, `http_error`, `malformed_response`) en cada adapter que pueda sufrirlos, incluidos los casos de `quotes: []`, campo `amount` ausente y JSON truncado del Provider C.

### 8.2 Escenarios E2E

Playwright, contra el stack de `docker-compose.test.yml`, con el Provider C forzado a `http_error` para que el resultado sea determinista y nunca dependa del azar:

1. **Flujo completo con un provider caído.** Rellenar el formulario, enviarlo, ver las ofertas de A y B y el aviso de que C no está disponible. Se ejecuta dos veces, con un viewport de escritorio y con un dispositivo móvil emulado. Es la única comprobación automática del requisito de mobile/desktop (`product.md` §5) y de que todo el sistema está bien cableado.
2. **Recarga real.** Rellenar el formulario a medias, recargar la pestaña y comprobar que lo escrito se recupera (TC-P2 en un navegador real). Solo en escritorio.

No se escribe ningún otro E2E: todo lo demás ya lo cubren los niveles inferiores.

## 9. Cobertura y criterio de suficiencia

Se considera suficientemente testeado el sistema cuando:

- Cada uno de los 18 TC tiene al menos un test identificado por su TC (§6).
- Se cubren los casos límite de §8.1, no solo el camino feliz.
- El informe de cobertura no muestra lógica de negocio sin ningún test.

**La cobertura se genera como informe, no como puerta.** No bloquea nada un porcentaje global, porque en un ejercicio de esta duración fomentaría tests escritos solo para subir el número. Como referencia orientativa, se espera un 90 % de líneas o más en las capas `Domain` y `Application` del backend, donde está la lógica de negocio; bajar de ahí es una señal para revisar, no un fallo. La cobertura mide qué líneas se ejecutan, no si los tests comprueban algo útil, así que no sustituye a los criterios anteriores.

En un equipo real con muchos colaboradores se pasaría a una puerta sobre el código nuevo de cada PR (no un porcentaje global). Aquí no se aplica: hay un solo autor, el plazo es corto y no se entrega un fichero de CI concreto (§11).

## 10. Qué no se prueba, y por qué

- Getters triviales y configuración del framework: sin lógica propia.
- Los internos del simulador, salvo el picker: son un artefacto del ejercicio, no arquitectura de producción (`architecture.md` §3).
- Regresión visual de píxeles y auditorías de accesibilidad: el brief no las pide.
- Componentes que solo pintan texto sin lógica.
- Que `sessionStorage` esté disponible en el navegador (modo privado bloqueado): el brief no lo pide y sería sobre-testear.
- Pruebas de mutación (Infection): desproporcionadas para este alcance.

## 11. Ejecución: comandos y CI

Cada nivel se lanza con un comando:

| Comando | Qué ejecuta | Cuándo |
|---|---|---|
| `make test` | Unitarios (backend y frontend) e integración, en contenedor | Constantemente en local; en CI, en cada push |
| `make test-realtime` | Suite de tiempo real (requiere el stack de test levantado) | En local cuando se toque el timeout o la concurrencia; en CI, en cada PR hacia la rama principal |
| `make test-e2e` | E2E con Playwright (requiere el stack de test levantado) | Igual que el anterior |
| `make test-all` | `test` + `test-alerts` + `test-realtime` + `test-e2e` (no incluye `lint` ni `coverage`) | Antes de entregar |
| `make lint` | PHPStan, ECS, Rector (solo comprobación), `vue-tsc` y ESLint, en contenedor | En local y en CI, en cada push |
| `make coverage` | Informe de cobertura del backend (§9), en contenedor con PCOV | Antes de entregar |
| `make test-alerts` | `promtool check rules` y `promtool test rules` sobre las reglas de alerta (TC-O4), en un contenedor de un solo uso; no necesita el stack levantado | Igual que `make lint` |

`make test` y `make lint` solo requieren Docker. `make test-realtime` y `make test-e2e` se ejecutan en el host (necesitan `make install`), porque Playwright en contenedor añade una imagen grande y fallos intermitentes de red que no compensan; queda como decisión consciente registrada en `decisions.md`.

### 11.1 Ejecución en CI (independiente de la plataforma)

La estrategia es "lo barato siempre, lo caro cuando toca", en dos etapas:

1. **En cada push:** `make lint`, `make test` y `make test-alerts`. Duran segundos y no necesitan el stack levantado.
2. **En cada PR hacia la rama principal:** levantar el stack de test (`docker compose -f docker-compose.yml -f docker-compose.test.yml up -d`) y ejecutar `make test-realtime` y `make test-e2e`.

Estos pasos se traducen tal cual a GitHub Actions, GitLab CI o Bitbucket Pipelines: solo cambia la sintaxis del fichero, los comandos son los mismos. Como el brief no especifica plataforma de repositorio, este documento no incluye un fichero de CI concreto (sería arbitrario y no verificable desde aquí).

## 12. Referencia cruzada

- Las TC-1 a TC-7, el `ProviderOutcomePicker` y las latencias parametrizadas están en `providers.md` §3.4 y §5.3.
- Las TC-P1 a TC-P7 están en `product.md` §4.5; el store desacoplado y la persistencia, en `product.md` §2–3 y `architecture.md` §5.
- El mecanismo de simulación por HTTP de loopback y el requisito de concurrencia del servidor, en `architecture.md` §3.
- Los TC-O1 a TC-O4, el formato de logs, las métricas y las reglas de alerta están en `observability.md` §3, §4, §6 y §10.
- Las alternativas consideradas y descartadas de las decisiones de este documento se registran en `decisions.md`.
