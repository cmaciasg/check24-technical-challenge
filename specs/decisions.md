# Decisiones de diseño y alternativas descartadas

Este documento recoge, para cada punto donde el brief o las propias specs dejaban un hueco (una pregunta implícita, una invención posible o varias soluciones razonables), la decisión que tomé, las alternativas que descarté y por qué.

Está ordenado por temas, en el mismo orden que las specs. Cada entrada indica su **origen**:

- **Al escribir la spec**: decisiones tomadas antes de implementar.
- **Revisión de la iteración N**: huecos que aparecieron al revisar lo que produjo el agente de implementación en esa iteración (de la 0 a la 3; los prompts están en `prompts.md`). Cada hueco se cerró en la spec correspondiente.

Cada entrada sigue la misma estructura: **Contexto** (el problema o la pregunta que obligó a decidir), **Decisión** y **Descartado**.

El cuaderno de trabajo completo (hallazgos clasificados como pregunta, invención o contradicción, con su resolución) queda fuera de esta entrega; aquí están solo las decisiones cerradas.

## 1. Producto

### 1.1 Estado del formulario en un store, no en el componente

*Origen: al escribir la spec · Specs: `product.md` §2, `architecture.md` §5*

**Contexto:** el brief pide un formulario simple para la primera versión, pero que más adelante pueda convertirse en un funnel de varias páginas sin rediseñarlo.

**Decisión:** el estado del formulario vive en un store desacoplado del componente de la página, con validación por campo. El backend recibe siempre un `QuoteRequest` completo y no sabe que existen "pasos".

**Descartado:** estado local en el componente. Es lo más directo para un formulario de un solo paso, pero obligaría a refactorizar en cuanto pasara a varias páginas.

### 1.2 Persistencia ante recarga: `sessionStorage`, sin cifrar

*Origen: al escribir la spec · Specs: `product.md` §3*

**Contexto:** el brief pide que el cliente no sienta que pierde lo que ya había rellenado si recarga la página. No dice dónde guardarlo ni cuánto tiempo, y uno de los datos es la fecha de nacimiento.

**Decisión:** el input crudo se guarda en `sessionStorage` y se borra solo cuando `POST /comparison` responde `200` (nunca con `400` o `500`, para no hacer perder al usuario lo que aún no ha enviado con éxito).

**Descartado:**
- `localStorage`: sobrevive días después. Resuelve algo que el brief no pide y deja la fecha de nacimiento guardada sin límite de tiempo.
- Cifrar lo guardado: un sistema real con datos personales necesitaría cifrado, retención y consentimiento, pero queda fuera del alcance de esta prueba. Guardar la fecha en claro es un límite de alcance consciente, no un descuido.

### 1.3 "Hoy" es la fecha UTC del servidor

*Origen: revisión de la iteración 0 · Specs: `product.md` §1*

**Contexto:** `product.md` validaba la edad contra "hoy" sin decir qué reloj manda. Backend y frontend lo calculan cada uno por su lado (servidor en UTC, navegador en hora local) y pueden diferir en un día. Salió al revisar la iteración 0.

**Decisión:** para validar la edad, "hoy" es la fecha UTC del servidor, que es la autoridad. La validación del frontend usa la fecha local del navegador, y tolero hasta un día de discrepancia entre ambos.

**Descartado:** sincronizar la fecha del frontend con la del servidor. Complica el cliente para un caso límite de un día que el backend ya resuelve al validar.

## 2. Providers

### 2.1 Providers simulados sin datos reales

*Origen: al escribir la spec · Specs: `providers.md` §0, §3*

**Contexto:** el brief pide que los partners simulados se comporten como partners reales y se especifiquen como sistemas externos. La forma más rápida de darles realismo era partir de integraciones reales que conozco.

**Decisión:** los formatos, nombres y campos de los tres providers simulados son inventados. Están inspirados en el tipo de inconsistencias que he visto en integraciones B2B reales de seguros (formatos distintos, timeouts, errores `500`, respuestas mal formadas), pero sin copiar nada concreto.

**Descartado:** reutilizar payloads reales de integradores con los que trabajé en un empleador anterior, para dar más realismo. Son contratos de terceros cubiertos por confidencialidad, y publicarlos, aunque sea en una prueba, es un riesgo legal y reputacional que no compensa.

### 2.2 Precio determinista en los simuladores

*Origen: revisión de la iteración 0 · Specs: `providers.md` §3*

**Contexto:** `providers.md` fijaba el formato de la respuesta de cada provider, pero no de dónde sale el precio. En la iteración 0 el agente inventó una tarifa.

**Decisión:** cada simulador devuelve un precio determinista derivado del `QuoteRequest`; la fórmula no forma parte del contrato del puerto.

**Descartado:** dejar la tarifa sin especificar: otro agente inventaría otra distinta. Fijar que es determinista hace que los tests sean reproducibles sin atarse a una fórmula concreta.

### 2.3 Puerto de provider en dos pasos (concurrencia)

*Origen: revisión de la iteración 0 · Specs: `providers.md` §1, §2, §4, `architecture.md` §1, §3, §6, §7.2*

**Contexto:** el puerto se diseñó desde el dominio, con un `quote()` que esperaba la respuesta (`providers.md` §4). La concurrencia se diseñó desde la infraestructura: lanzar los tres providers a la vez (`architecture.md` §3). Nadie comprobó que las dos cosas encajaran: con `quote()`, el orquestador solo puede llamarlos en serie. Salió al implementar, y en la iteración 0 el agente lo resolvió por su cuenta con Fibers.

**Decisión:** el puerto tiene dos pasos. `ProviderPort::start(QuoteRequest): PendingQuote` lanza la consulta sin bloquear, y `PendingQuote::collect(): ProviderResult` la resuelve. `ComparisonService` llama a `start()` en todos los adapters (quedan en vuelo a la vez) y recoge con `collect()` en el orden en que los lanzó.

Ni `start()` ni `collect()` lanzan excepciones por fallos esperados: un fallo al lanzar la consulta devuelve directamente un `PendingQuote` ya resuelto a `failure(...)`. Solo los errores de programación se propagan como excepción.

**Descartado:**
- Fibers de PHP como mecanismo: añaden un scheduler propio que no hace falta para tres llamadas HTTP en paralelo. La concurrencia ya la da el cliente HTTP.
- Dejar el mecanismo libre en la spec: otro agente lo rellenaría de otra forma.
- Una interfaz asíncrona separada, conviviendo con el `quote()` síncrono original: dos contratos para un mismo concepto.
- Que los errores de `start()` los capture el orquestador, o que se difieran hasta `collect()`: prefiero que cada `PendingQuote` sea autocontenido y ya sepa si su lanzamiento falló.

### 2.4 Timeout único para todos los providers

*Origen: al escribir la spec · Specs: `providers.md` §5.1, `observability.md` §6, §7*

**Contexto:** el brief dice que el cliente no debe esperar eternamente, y los partners tienen latencias muy distintas: B es siempre lento y C tiene picos. Hay que decidir cuánto se espera a cada uno.

**Decisión:** un único techo de 2000 ms, configurable e igual para todos los providers. El valor está elegido contra los perfiles simulados: queda por encima del máximo de B (1800 ms) y por debajo del mínimo de un pico de C (2500 ms), así que no hay casos límite ambiguos.

**Descartado:** un timeout por partner según su SLA contractual. Es lo que tendría un sistema real, pero aquí no hay SLAs que respetar y solo complicaba la configuración y los tests.

**Consecuencia asumida:** subir el timeout (por ejemplo, porque B se degrada) sube la espera máxima para todos los clientes. `observability.md` §7 lo deja como decisión a vigilar.

### 2.5 Techo de tiempo, `TIMEOUT` y `elapsedMs`

*Origen: revisión de la iteración 0 · Specs: `providers.md` §1.1, §1.2, §5.1*

**Contexto:** la spec daba por hecho que la opción `timeout` de HttpClient limita la duración total, pero es de inactividad. La spec no bajaba al nivel de la librería, y por eso tampoco decía de dónde sale `elapsedMs` ni cómo distinguir un timeout de otro fallo de transporte. Salió al revisar la iteración 0.

**Decisión:** el techo total es `max_duration`, contado desde `start()`, no el `timeout` de `request()`. `elapsedMs` sale de `total_time` del transporte; si no está disponible, se mide con el `ClockInterface` inyectado entre `start()` y la resolución. Un fallo de transporte es `TIMEOUT` si llega habiendo pasado al menos `timeout_ms` desde `start()` según el reloj de la aplicación; cualquier otro fallo de transporte es `UNKNOWN_ERROR`.

**Descartado:**
- Medir `elapsedMs` solo con el reloj de la aplicación: menos preciso teniendo a mano el dato del transporte.
- Recoger por orden de llegada (`stream()`): cambia la semántica de "todas en vuelo, resultados en orden estable".
- Clasificar `TIMEOUT` por el texto de la excepción de curl: en Symfony 7.4.19 `CurlResponse` lanza una `TransportException` genérica con el texto de libcurl, sin subclase ni código propio. Depender de ese texto es frágil.

**Límite aceptado** *(revisión de la iteración 1)*: la regla mide hasta la *recogida*, no hasta el fallo real. Un fallo de transporte que no sea timeout, recogido tras acumular 2000 ms desde `start()`, se clasificaría como `TIMEOUT`. Con los providers definidos no ocurre (el máximo simulado es 1800 ms), así que lo dejo anotado en vez de complicar la spec.

## 3. Backend

### 3.1 Estructura por módulos

*Origen: al escribir la spec · Specs: `architecture.md` §1, §7*

**Contexto:** el brief pide specs lo bastante precisas para que el agente no invente la arquitectura, y valora la extensibilidad de partners. Había que fijar la estructura del backend y cómo se añade un provider nuevo.

**Decisión:**
- Un módulo por bounded context, con `Application` / `Domain` / `Infra` / `UI`: `App\Comparator\CarInsurance\...`. `Comparator` es la plataforma, no un contexto, así que otra categoría (por ejemplo, hoteles) sería otro módulo al lado de `CarInsurance`: `App\Comparator\Hotels\...`.
- Sin carpeta `Shared`: acaba siendo un cajón de sastre. Si dos contextos comparten algo, se decide entonces, con un módulo de nombre propio.
- "Port" solo para interfaces de salida (`ProviderPort`). La entrada HTTP es un controlador en `UI/Http`.
- Providers registrados por tagged iterator: añadir un provider D es añadir un adapter, sin tocar el orquestador.

**Descartado:** llamar "Port" también a los controladores, como hago en mis proyectos: un revisor externo no lo entendería sin contexto.

### 3.2 Código común de los adapters en la raíz de `Infra/ThirdParty`

*Origen: revisión de la iteración 0 · Specs: `architecture.md` §7.2*

**Contexto:** la estructura de `Infra/ThirdParty` se pensó para lo propio de cada provider (una carpeta por provider), no para lo compartido. En la iteración 0 el agente extrajo una clase base y helpers comunes, y la spec no decía dónde ponerlos.

**Decisión:** lo común a todos los adapters (clase base, helpers de payload) vive en la raíz de `Infra/ThirdParty`; lo específico de cada provider, en su carpeta.

**Descartado:** repetir lo común dentro de la carpeta de cada provider: se duplicaría una vez por provider.

### 3.3 Las capas también se respetan en los tests

*Origen: revisión de la iteración 1 · Specs: `architecture.md` §2*

**Contexto:** `architecture.md` §2 extendía los principios a los tests de forma genérica, pero no concretaba la regla de dependencias entre capas para ellos. En la iteración 1 apareció un test de `Application/Compare` que importaba `LazyPendingQuote`, una clase concreta de `Infra/ThirdParty`.

**Decisión:** los tests de `Application` dependen solo de tipos de `Application` y `Domain`, nunca de clases concretas de `Infra`. En la iteración 2 se sustituyó por un doble de test (`FakePendingQuote`) de la interfaz `PendingQuote`.

## 4. Frontend

### 4.1 Una sola vista y sin skeleton

*Origen: al escribir la spec · Specs: `architecture.md` §5*

**Contexto:** el brief no dice cuántas pantallas tiene la experiencia ni qué ve el cliente mientras espera a partners que pueden tardar hasta el timeout.

**Decisión:** formulario y resultados en una única vista en `/`. Durante la carga, botón bloqueado e indicador.

**Descartado:**
- Una ruta propia para los resultados: no se guardan, así que esa URL no tendría datos tras recargar.
- Un skeleton de carga: no se sabe cuántas ofertas van a llegar.

### 4.2 Textos preparados para varios idiomas, sin implementarlo

*Origen: al escribir la spec · Specs: `architecture.md` §5.5*

**Contexto:** el brief no dice nada de idiomas. Añadirlos después es caro si los textos están escritos directamente en los componentes.

**Decisión:** la interfaz está solo en inglés, pero todo texto pasa por `t(key)` con un catálogo tipado. Añadir un idioma es añadir un fichero, y si hiciera falta un selector, `vue-i18n` sustituiría el cuerpo de `t()` sin tocar a quien lo llama.

**Descartado:** implementar ya `vue-i18n` y un selector de idioma: el brief no lo pide.

## 5. Testing y calidad

### 5.1 Providers en los tests: simulador en proceso más una suite de tiempo real

*Origen: al escribir la spec · Specs: `testing.md`*

**Contexto:** los tests tienen que demostrar el comportamiento con partners lentos, caídos o que superan el timeout, pero esperar latencias reales en cada test haría la suite lenta e inestable.

**Decisión:** en integración, el simulador se atiende dentro del mismo proceso (sin red y con latencia 0). Aparte hay una suite de tiempo real contra el stack de test, con resultado y latencia de cada provider forzados por variable de entorno.

**Descartado:** quedarse solo con mocks: no demuestran que el techo lo cumple el transporte ni que las llamadas son concurrentes de verdad.

### 5.2 TC-4 como invariante de configuración

*Origen: al escribir la spec · Specs: `providers.md` §5.3, `testing.md` §6*

**Contexto:** TC-4 debe garantizar que el timeout corta los picos pero no la latencia normal de ningún provider. Como las latencias son aleatorias, comprobarlo con ejecuciones reales exigiría muchas repeticiones.

**Decisión:** TC-4 comprueba, para todos los providers configurados, que ninguna latencia normal supera el timeout y que ningún pico queda por debajo, más una única ejecución real de B a 1800 ms.

**Descartado:** repetir muchas ejecuciones reales, que son lentas.

### 5.3 Tests, lint y cobertura en contenedor

*Origen: revisión de la iteración 0 · Specs: `testing.md` §4.1, §9, §11*

**Contexto:** `testing.md` definía los comandos (`make test`, `make lint`), pero no dónde se ejecutan. En la iteración 0 corrían en mi máquina con PHP 8.5, mientras la app usa PHP 8.4: quien evalúe podría obtener resultados distintos según lo que tenga instalado.

**Decisión:** `make test`, `make lint` y `make coverage` corren en contenedor (imagen de desarrollo del backend con PHPUnit, PHPStan, ECS, Rector y PCOV), con las mismas versiones que la app. `make test-realtime` y `make test-e2e` se quedan en el host (necesitan `make install`).

**Descartado:**
- Seguir en el host fijando versiones en `composer.json` y en `engines` de Node: no resuelve el desajuste de raíz.
- Exigir las dos vías, host y contenedor: duplica mantenimiento sin aportar nada.
- Meter también `test-realtime` y `test-e2e` en contenedor: Playwright en contenedor añade una imagen grande, red y fallos intermitentes que no compensan.

**Confirmación** *(iteración 2)*: al pasarlo a contenedor, `make lint` falló en un contenedor limpio porque PHPStan no encontraba `var/`. Mi máquina lo tenía creado de sesiones anteriores y nunca lo vi fallar. Es exactamente la diferencia que esta decisión evita que llegue a quien evalúa.

### 5.4 Sin fichero de CI

*Origen: al escribir la spec · Specs: `testing.md`*

**Contexto:** `testing.md` tenía que describir cómo se ejecutan los tests en integración continua, pero el reto no dice qué plataforma de CI usa el equipo.

**Decisión:** `testing.md` describe la política de ejecución en CI de forma independiente de la plataforma.

**Descartado:** incluir un fichero de CI concreto: habría que suponer una plataforma.

### 5.5 Alcance de Rector

*Origen: revisiones de las iteraciones 2 y 3 · Specs: `testing.md` §4.1*

**Contexto:** `testing.md` nombraba Rector, pero no qué sets aplica. En la iteración 2 el agente lo limitó a `deadCode`, con el argumento de que, según su estimación, sets más amplios reescribirían 61 ficheros.

**Decisión:** Rector aplica los sets `deadCode`, `codeQuality`, `typeDeclarations` y `earlyReturn`, más la modernización a PHP 8.4.

**Cómo se llegó:** lo di por bueno para esa iteración. Antes de ampliarlo comprobé yo el alcance real con un dry-run y un `rector.php` temporal fuera del repo: con esos cuatro sets y PHP 8.4 eran 35 ficheros, ninguno con cambio de comportamiento. Con eso acotado, la iteración 3 lo aplicó.

**Descartado:** quedarse solo con `deadCode` por prudencia: la comprobación mostró que el volumen era manejable y sin riesgo funcional.

## 6. Observabilidad

*Origen de todas las entradas: al escribir la spec · Specs: `observability.md`*

**Contexto común:** el brief pide a los perfiles senior una especificación de observabilidad para saber si el comparador está sano tras el lanzamiento (uso, rendimiento de los partners, errores y latencia), y cómo montar un setup básico, por ejemplo con Grafana.

### 6.1 Tiempo de carga medido en el servidor

**Decisión:** se mide la duración de la comparación en el servidor, con percentiles y heatmap en vez de media. La medición en el navegador queda especificada como evolución (`observability.md` §2.3).

**Descartado:** implementar ya la medición en el navegador: obligaba a reabrir el contrato de la API, el frontend y los tests.

### 6.2 Herramientas

**Decisión:** Prometheus y Grafana. Métricas con promphp y almacén APCu, detrás de un puerto propio (`ComparisonMetrics`). Logs JSON por la salida de errores (`stderr`) con el logger PSR-3 estándar.

**Descartado:**
- Redis como almacén de contadores: solo hace falta con más de una instancia de backend.
- OpenTelemetry: más pieza de la que pide un setup básico.
- Loki: el formato de los logs ya está preparado para añadirlo.
- Un único puerto de telemetría para métricas y logs: los logs no necesitan un puerto propio.

### 6.3 Alertas

**Decisión:** reglas de Prometheus con umbral simple, duración mínima (`for`) y guarda de tráfico mínimo, calibradas contra la línea base de cada provider.

**Descartado:**
- Alertmanager o alertas gestionadas por Grafana: en un ejercicio no hay canal al que enviar notificaciones.
- Burn rate multiventana: no hay SLOs formales.

### 6.4 Etiquetas y privacidad

**Decisión:** `vehicle_type` y `usage_type` sí, como etiquetas agregadas. La fecha de nacimiento nunca, ni en tramos de edad: por privacidad y para mantener acotada la cardinalidad.

### 6.5 Grafana con acceso anónimo de solo lectura

**Decisión:** acceso anónimo para la demo. No es una decisión de producción.

## 7. Aceptado sin cambios

- **Symfony 7.4 y PHP 8.4** *(revisión de la iteración 0)*: se mantienen tal como los eligió el agente; no hubo motivo para cambiarlos.
- **Estilo ECS sin espacios alrededor de `.`** *(revisión de la iteración 0)*: es el estilo por defecto del preset; no vi razón para cambiarlo.
- **`collect()` no impide una segunda llamada** *(revisión de la iteración 1)*, aunque la spec diga "exactamente una vez" (`providers.md` §1). No añadí una guarda porque ningún camino del código lo invoca dos veces; queda como límite conocido.
- **Nombres completamente cualificados en 6 `instanceof`** *(revisión de la iteración 3)*: Rector escribió el nombre largo de la clase en vez del corto ya importado. ECS no lo marca y no tiene riesgo funcional; no merecía una cuarta iteración.
