# Architecture

## 1. Visión general del flujo

```
Vue (formulario; estado en composable/store — product.md §2)
   -> POST /comparison { QuoteRequest }
        -> ComparisonController (UI/Http): construye el QuoteRequest y lo pasa al caso de uso
        -> CompareQuotes (Application/Compare): caso de uso que orquesta los pasos siguientes
             -> ComparisonService
                  -> [ProviderAAdapter, ProviderBAdapter, ProviderCAdapter]  (llamadas concurrentes)
                  -> un ProviderResult por adapter (éxito o fallo), devuelto tal cual
             -> CampaignPricingService (aplica CampaignDiscount si la campaña está activa — product.md §4, architecture.md §6)
             -> resultado: PricedOffer[] + ProviderResult[]
   <- respuesta JSON (el controlador serializa offers[] y providerStatuses[])
Vue (renderiza ofertas / estados de provider / precio tachado si hay campaña activa)
```

El cuerpo de la petición es un `QuoteRequest` serializado (`birthDate`, `vehicleType`, `usageType`) — definición completa y reglas de validación en `product.md` §1. Un `QuoteRequest` inválido responde `400 Bad Request` antes de invocar a ningún provider (contrato completo de la petición en §4.1).

`ComparisonService` es el único punto de la aplicación que conoce los tres providers a la vez. Cada adapter conoce un solo provider. Añadir un cuarto provider (`ProviderDAdapter`) implica implementar `ProviderPort` en su propia carpeta de `Infra/ThirdParty` (§7.2), y no implica tocar `ComparisonService`, la configuración de servicios ni el contrato de respuesta. Cada adapter declara su propio `ProviderId` y su `ProviderName` legible (`providers.md` §1). Los providers se registran solos: `ProviderPort` lleva un atributo de autoconfiguración (`#[AutoconfigureTag('app.comparator.provider')]`) que etiqueta todo servicio que la implemente, y `ComparisonService` recibe todos los servicios etiquetados como un iterable (`#[AutowireIterator('app.comparator.provider')]`) en lugar de una lista fija de adapters. `ProviderId` es un value object abierto y no un enum (`providers.md` §1), así que añadir un provider tampoco obliga a editar ningún fichero del dominio. Un agente de IA implementador no debe inyectar los tres adapters a mano en `ComparisonService`. `CampaignPricingService` es un paso independiente que tampoco conoce los providers — solo conoce `ComparisonOffer[]` ya construido.

## 2. Principios de implementación

Toda la implementación de este proyecto sigue cuatro principios, explícitos y vinculantes para el conjunto del código — no solo para la frontera de providers, donde se ven más claramente en los ejemplos de este documento y de `providers.md`:

- **Arquitectura hexagonal (puertos y adaptadores)** — justificación detallada en §2.1.
- **Domain-Driven Design**: value objects para cualquier dato de dominio con reglas propias, nunca primitivos sueltos (`DateOfBirth`, `VehicleType`, `UsageType`, `Price`, `CampaignDiscount` — `product.md` y `providers.md`); lenguaje ubicuo del negocio real, nunca abreviaturas o nombres inventados para conceptos que ya tienen nombre en el dominio de seguros de auto (p. ej. `HATCHBACK`, no un sinónimo casero); servicios de aplicación con una única responsabilidad clara (`ComparisonService`, `CampaignPricingService`).
- **SOLID**: responsabilidad única por clase (cada adapter tiene exactamente las cuatro responsabilidades de `providers.md` §2, ni una más; declarar su identidad forma parte de implementar `ProviderPort`); abierto/cerrado (añadir un provider nuevo no modifica `ComparisonService` ni el contrato de `ProviderPort`); inversión de dependencias (los adapters reciben el cliente HTTP inyectado, nunca lo instancian ellos mismos).
- **Clean Code**: nombres que dicen lo que hacen, sin abreviaturas crípticas; funciones cortas con un único nivel de abstracción; validación explícita de cada dato de entrada en vez de confiar en que un parseo sin errores implica datos válidos; ninguna excepción sin capturar y normalizar en la frontera correspondiente.

Esto aplica también a las piezas de código que estos documentos no detallan de forma explícita (el controlador HTTP, la configuración de Symfony, los tests) — un agente de IA implementador no debe relajar estos principios solo porque un fichero de `/specs` no puso un ejemplo literal para esa pieza concreta.

### 2.1 Por qué hexagonal aquí

El patrón puerto/adapter no se adopta por convención, sino porque responde a una necesidad real del dominio: los tres partners son heterogéneos por diseño (formatos de payload, nomenclatura de campos, representación del importe, vocabulario de nivel de cobertura — ver `providers.md` §3) y se espera que ese conjunto de partners crezca o cambie sin que el resto del sistema se entere. El puerto `ProviderPort` (contrato completo en `providers.md` §1) es la frontera estable; toda la variabilidad de cada partner concreto queda contenida en su adapter.

## 3. Mecanismo de concurrencia y simulación de providers

Los tres providers no son servicios externos reales, pero se simulan como si lo fueran: cada uno se expone como un endpoint HTTP interno propio (`POST /_internal/simulated-providers/{providerId}/quote`, con `provider-a`, `provider-b` y `provider-c` como ids), separado del código de dominio en un módulo propio (`src/ProviderSimulation/`), y cada adapter les llama con Symfony HttpClient como si fuera cualquier cliente hacia un partner real.

Esta decisión (simulación vía HTTP real de loopback, en lugar de un fake in-process con `usleep`) se toma para que el timeout y la concurrencia sean reales, no simulados a su vez:

- El timeout se aplica como opción del cliente HTTP (`timeout` en `HttpClientInterface::request()`), y lo hace cumplir el propio motor de transporte (curl), no un `if` de la aplicación comparando marcas de tiempo.
- La concurrencia entre los tres adapters se consigue con el soporte asíncrono nativo de Symfony HttpClient: las tres peticiones se lanzan sin bloquear (`request()` sin resolver la respuesta) y se resuelven a medida que cada una completa o expira, en vez de esperarlas una a una.
- Un partner lento o caído se comporta exactamente igual, desde el punto de vista del adapter, tanto si es simulado como si fuera real: una excepción de timeout o de transporte que el adapter captura y normaliza a `ProviderResult::failure(...)` (contrato completo en `providers.md` §1–2).

Nota operativa: para que las tres llamadas se solapen de verdad en tiempo real (y no queden serializadas por un único worker PHP ocupado), el entorno de ejecución (PHP-FPM u otro runtime) debe poder atender al menos tantas peticiones concurrentes como providers simulados activos más una: la propia petición de `POST /comparison` mantiene ocupado un worker mientras espera a los providers. Con los tres providers actuales, son al menos 4 workers simultáneos. Con menos, las llamadas a los providers simulados se encolan y la latencia medida de alguno de ellos incluye tiempo de espera que no es suyo; con un solo worker, la petición se bloquea a sí misma y las tres llamadas acaban en timeout. Esto se documenta como requisito de `docker-compose.yml`, no como parte del contrato de dominio.

Los endpoints de simulación viven fuera del dominio de comparación y no representan arquitectura de producción: son un artefacto exclusivo de este ejercicio para poder ejercitar timeouts y concurrencia reales sin depender de partners externos de verdad.

## 4. Contrato de `POST /comparison`

### 4.1 Petición

```json
{
  "birthDate": "1990-05-14",
  "vehicleType": "SEDAN",
  "usageType": "PRIVATE"
}
```

Los tres campos y sus reglas de validación completas están definidos como `QuoteRequest` en `product.md` §1.

Esta validación ocurre en el **puerto de entrada** de la aplicación (el controlador de `POST /comparison`, al intentar construir el `QuoteRequest` a partir del cuerpo de la petición) — no debe confundirse con `ProviderPort` (`providers.md` §1), que es el puerto de salida hacia los tres providers y mira en la dirección contraria. Si la construcción del `QuoteRequest` falla (lanza `InvalidQuoteRequest`, `product.md` §1.5), el flujo nunca llega a `ComparisonService` ni a ningún adapter: ningún provider llega a enterarse de que hubo una petición.

| Código | Cuándo |
|---|---|
| `200` | El `QuoteRequest` es válido. Incluye el caso en que los tres providers fallan (`offers: []`) — ver §4.2. |
| `400` | El `QuoteRequest` no se pudo construir (formato inválido, edad fuera de 18–99, o `vehicleType`/`usageType` fuera de su catálogo cerrado) — nunca se llega a invocar ningún provider (`product.md` TC-P1). |
| `500` | Fallo no controlado propio de nuestro código — nunca causado por un provider, que por contrato no puede hacer que nuestra API falle (`providers.md` §5.2). |

Ningún fallo o intermitencia de los providers cambia nunca el código de estado de la respuesta — ver §4.2.

### 4.2 Respuesta

```json
{
  "offers": [
    {
      "providerId": "provider-a",
      "providerName": "Provider A",
      "providerQuoteReference": "A-7f3c1e",
      "priceAmountCents": 4250,
      "discountedPriceAmountCents": 3825,
      "campaignApplied": true,
      "currencyCode": "EUR",
      "coverageLevel": "STANDARD"
    }
  ],
  "providerStatuses": [
    { "providerId": "provider-a", "providerName": "Provider A", "status": "ok", "elapsedMs": 132 },
    { "providerId": "provider-b", "providerName": "Provider B", "status": "ok", "elapsedMs": 1543 },
    { "providerId": "provider-c", "providerName": "Provider C", "status": "failed", "reason": "timeout", "elapsedMs": 2001 }
  ]
}
```

`priceAmountCents` y `currencyCode` provienen del `Price` VO de cada `ComparisonOffer` (`providers.md` §1.3, `product.md` §4.2) — siempre son el precio real del provider, sin campaña aplicada. `discountedPriceAmountCents` y `campaignApplied` solo aparecen en una oferta cuando `app.marketing.campaign.active = true` (`product.md` §4.1); si la campaña está inactiva, ninguna oferta los incluye (`product.md` TC-P4). El cálculo y la aplicación de la campaña se especifican en §6.

`providerName` es el nombre legible que declara cada adapter (`providers.md` §1) y es el único texto de provider que el frontend muestra al usuario: nunca se deriva ni se infiere a partir de `providerId`. Cada adapter lo incorpora a la `ComparisonOffer` y al `ProviderResult` que construye, y de ahí llega tanto a cada oferta como a cada entrada de `providerStatuses`.

`providerStatuses` se construye recorriendo los `ProviderResult` de los tres adapters (uno por provider, siempre presente, éxito o fallo — ver `providers.md` §1.2). Es la pieza que permite al frontend mostrar "Provider C no disponible ahora mismo" en lugar de que la ausencia de una oferta se sienta como un error sin explicación, y es también la fuente de datos base para las métricas de `observability.md`.

Un fallo parcial o total de providers nunca cambia el status code de la respuesta: `POST /comparison` devuelve `200` siempre que la petición en sí sea válida, independientemente de cuántos providers hayan fallado (`offers: []` es una respuesta válida, no un error — ver `providers.md` TC-3).

## 5. Frontend: pantallas, estados y estructura

### 5.1 Formulario, funnel futuro y persistencia ante recarga

Estas dos decisiones son de producto y están especificadas con su razonamiento completo en `product.md` §2 y §3 — aquí solo se fija la pieza de arquitectura que les corresponde:

- El estado del formulario (los tres campos de `QuoteRequest`, como input crudo sin validar todavía) vive en un composable/store (p. ej. Pinia) desacoplado de cualquier componente de página concreto, no como estado local del componente que hoy renderiza el formulario de una sola página. Esto es lo que permite que un futuro funnel multi-página añada pasos/rutas sobre el mismo estado sin refactor (`product.md` §2).
- Ese mismo store se serializa a `sessionStorage` en cada cambio y se hidrata desde ahí al arrancar, para sobrevivir a una recarga de la pestaña. Se limpia únicamente cuando `POST /comparison` responde `200` (`product.md` §3, TC-P2, TC-P3).

### 5.2 Pantalla

Una única vista, `ComparisonView`, en la ruta `/`: el formulario arriba y, debajo, el panel de resultados. Es el funnel de un solo paso de `product.md` §2; un futuro funnel añade rutas antes de esta vista sin tocarla. No hay ruta de resultados: los resultados no se persisten (`product.md` §3), así que una URL propia no tendría datos tras recargar.

Formulario (validación por campo, con las reglas de `product.md` §1):

- `birthDate`: `<input type="date">` con `max` = hoy. Valor ISO nativo, selector nativo en móvil.
- `vehicleType`: `<select>` con una opción vacía inicial y las cuatro del catálogo.
- `usageType`: grupo de dos radios.
- Se valida cada campo al perder el foco y todos al enviar. El error se muestra bajo el campo. Con algún campo inválido no se envía nada al backend y el foco va al primer campo con error. El botón solo se deshabilita mientras se espera la respuesta, nunca por validez.

El frontend replica las reglas de validación (edad 18–99, catálogos) solo por comodidad de UX, en una única fuente (`domain/quote-request.ts`). La autoridad sigue siendo el backend (`400`, §4.1).

### 5.3 Estados de la vista

El store expone un único estado de vista:

| Estado | Cuándo | Qué se ve |
|---|---|---|
| `idle` | Antes del primer envío | Solo el formulario. |
| `loading` | Petición en curso | Botón deshabilitado con texto de espera e indicador de progreso en el panel de resultados. Los resultados anteriores se limpian al enviar. Sin skeleton: el número de ofertas no se conoce de antemano. |
| `success` con ofertas | `200` y `offers` no vacío | Tarjetas ordenadas (§5.4) y, debajo, un aviso por cada provider con estado `failed`. |
| `success` vacío | `200` con `offers: []` | Estado vacío con mensaje claro, los avisos de cada provider y un botón "Volver a comparar" (reenvía el formulario actual). |
| `invalid-data` | `400` | Mensaje de revisar los datos, sin botón de reintento. No se muestra el cuerpo del error del servidor. |
| `unavailable` | `500` o fallo de red | Mensaje de fallo temporal con botón "Reintentar". |

Reglas comunes:

- El formulario conserva siempre lo escrito, en cualquier estado (`product.md` §3).
- Tras un `200`, `sessionStorage` se vacía (`product.md` §3, TC-P3) pero el store en memoria conserva los valores, así que el formulario sigue editable para volver a comparar. Solo una recarga real deja el formulario vacío; es consecuencia consciente de `product.md` §3, no un defecto.
- Un provider caído nunca se explica con su `reason` ni con `elapsedMs`; esos datos son de observabilidad (`observability.md`). El aviso usa solo `providerName`: "<providerName> no está disponible ahora mismo".

### 5.4 Ofertas

- Una `OfferCard` por oferta: `providerName`, nivel de cobertura, referencia del provider y precio. Con campaña activa (`campaignApplied`), precio original tachado junto al precio final (`product.md` §4.4); sin campaña, solo el precio.
- Orden en el frontend (función pura en `domain/sort-offers.ts`): por precio efectivo ascendente (`discountedPriceAmountCents` si existe, si no `priceAmountCents`); empate por `providerName`. El contrato de §4.2 no garantiza ningún orden.
- Layout mobile-first: una columna por defecto, hasta tres tarjetas en cuadrícula desde 768 px.

### 5.5 Textos e idiomas (preparado, no implementado)

Hoy la interfaz tiene un único idioma, inglés. El multiidioma no lo pide el brief y no se implementa, pero el diseño queda listo para añadirlo sin tocar componentes:

- Ningún componente contiene literales de texto. Todo texto sale de `t(key, params?)` (`ui/copy/`), que es la única puerta de acceso a los textos.
- Las claves están tipadas: `messages.ts` define el tipo `Messages` y `messages.en.ts` lo implementa. Añadir un idioma es crear `messages.<código>.ts` que implemente `Messages`: si falta una clave, `vue-tsc` falla. Cambiar el idioma activo es tocar una sola constante (`activeLocale`).
- Los importes se muestran con `formatPrice()` (`Intl.NumberFormat` con `activeLocale`), nunca concatenando símbolos a mano. Los nombres de provider no se traducen: vienen del backend (`providerName`).
- Si algún día hiciera falta un selector de idioma o traducciones dinámicas, el cuerpo de `t()` se sustituye por `vue-i18n` sin cambiar a sus llamadores. Registrado en `decisions.md`.

### 5.6 Estilos y conexión con la API

- CSS propio, mobile-first, sin librería de UI ni de utilidades. Colores y espaciados como variables CSS en `styles/tokens.css`; estilos de componente con `<style scoped>`.
- El frontend llama a la ruta relativa `/comparison`; no conoce ninguna URL absoluta. En desarrollo la reenvía el proxy de Vite al backend; en `docker-compose` la reenvía el servidor web. El backend no necesita CORS.
- Vue 3 con Composition API (`<script setup lang="ts">`) y TypeScript estricto.
- Accesibilidad mínima: cada error de campo enlazado a su campo (`aria-describedby`) y el panel de resultados como región `aria-live="polite"`.

### 5.7 Estructura de `frontend/src`

```
frontend/
  vite.config.ts                  # proxy de /comparison hacia el backend
  src/
    main.ts
    App.vue                       # solo monta ComparisonView
    styles/
      tokens.css
    comparator/                   # mismo nombre que en backend (§7.2)
      car-insurance/              # un módulo por categoría; sin carpeta shared
        domain/                   # TypeScript puro: sin Vue, Pinia ni APIs del navegador
          quote-request.ts        #   tipos y reglas de validación por campo
          offer.ts                #   tipos de la respuesta (§4.2)
          sort-offers.ts
        infra/
          comparison-api.ts       #   POST /comparison
          form-storage.ts         #   sessionStorage
        store/
          comparison-store.ts     #   Pinia: formulario + estado de la vista (§5.3)
        ui/
          ComparisonView.vue
          QuoteForm.vue
          OfferCard.vue
          ProviderNotices.vue
          states/                 #   LoadingState, ErrorState, EmptyState
          copy/                   #   messages.ts, messages.en.ts, index.ts (t, activeLocale)
          format-price.ts
```

Dependencias: `domain` no importa nada del resto. `store` recibe `comparison-api` y `form-storage` por parámetro (con la implementación real por defecto) para poder probar TC-P2/P3 con dobles (`testing.md` §7.1). `ui` usa el store y `domain`, nunca `infra` directamente.

### 5.8 Selectores de test

Los tests de componentes y el E2E usan `data-testid`, definidos aquí para que todos coincidan: `field-birth-date`, `field-vehicle-type`, `field-usage-type`, `submit`, `results-panel`, `offer-card` (con `data-provider-id`), `offer-price-original`, `offer-price-final`, `provider-notice`, `state-loading`, `state-error`, `state-empty`.

## 6. Aplicación de la campaña de marketing

`CampaignPricingService` es un servicio de aplicación independiente de `ComparisonService` y de los adapters de providers — no implementa `ProviderPort` ni es conocido por ellos. Se ejecuta dentro del caso de uso `CompareQuotes` (`Application/Compare`, §7.2), que orquesta primero `ComparisonService` y después `CampaignPricingService`. El controlador (`ComparisonController`, `UI/Http`) solo traduce HTTP: construye el `QuoteRequest`, invoca el caso de uso y serializa su resultado. El flujo es:

```
ComparisonController (UI/Http): construye el QuoteRequest -> invoca el caso de uso
        |
        v
CompareQuotes (Application/Compare)
        |
        +-> ComparisonService::compare(QuoteRequest $request): ProviderResult[]
        |
        +-> CampaignPricingService::apply(ComparisonOffer[] $offers): PricedOffer[]
        |
        v
ComparisonController: serialización a la respuesta JSON de §4.2
```

`PricedOffer` (en `Application/Campaign`) es el resultado de este paso: conserva la `ComparisonOffer` completa y le añade `discountedPrice` (`?Price`, `null` si la campaña está inactiva). Así, al serializar la respuesta de §4.2, el controlador dispone de todos los datos de cada oferta junto a su precio con descuento, sin tener que reconstruirlos.

`CampaignPricingService` lee la configuración de `product.md` §4.1 (`app.marketing.campaign.*`). Si `active = false`, no hace nada y ninguna oferta lleva `discountedPriceAmountCents`. Si `active = true`, construye un único `CampaignDiscount` (`product.md` §4.2) a partir de `discount_type`/`discount_value`, y lo aplica por igual a las tres ofertas presentes en la respuesta llamando a `Price::withDiscount()` sobre cada una — sin distinguir por `providerId` (`product.md` §4.3, TC-P7). Ni `ProviderPort` ni ningún adapter necesita cambiar para que esto funcione: el `ComparisonOffer[]` que reciben ya está completamente resuelto antes de que este servicio actúe.

`CompareQuotes` extrae las ofertas de los `ProviderResult` que tuvieron éxito, se las pasa a `CampaignPricingService` y devuelve un `CompareQuotesResult` (en `Application/Compare`) con dos conjuntos: los `PricedOffer[]` (`offers[]` de §4.2) y los `ProviderResult[]` tal cual (`providerStatuses[]` de §4.2). El controlador serializa ambos al JSON de §4.2. `ComparisonService` no traduce nada a la forma de la respuesta: solo lanza las llamadas concurrentes y recoge los informes.

El caso de uso registra además las métricas y los logs de cada comparación a través del puerto `ComparisonMetrics` y del logger estándar (`observability.md` §3.4 y §4.3), sin que la lógica de comparación ni la de campaña dependan de ninguna librería de métricas.

## 7. Estructura del proyecto y del backend

### 7.1 Raíz del proyecto

```
specs/                          # documentación y planificación
backend/                        # Symfony
frontend/                       # Vue.js
e2e/                            # tests end-to-end (Playwright) — testing.md §5
docker-compose.yml
docker-compose.test.yml         # configuración de test con resultado/latencia de providers forzados — testing.md §3.4
observability/                  # Prometheus, Grafana y reglas de alerta — observability.md §8
README.md
```

La organización interna de `frontend/src/` está especificada en §5.7.

### 7.2 `backend/src/`

```
backend/src/
  Comparator/                       # la plataforma: agrupa los bounded contexts; no es un bounded context
    CarInsurance/                   #   bounded context: comparador de seguros de coche
      Application/
        Compare/                    #     CompareQuotes (caso de uso) y CompareQuotesResult, ComparisonService, ProviderPort (puerto de salida),
                                    #     ProviderResult, ProviderFailureReason, ComparisonMetrics (puerto de salida de observabilidad), ComparisonOutcome
        Campaign/                   #     CampaignPricingService, PricedOffer
      Domain/
        Model/                      #     QuoteRequest, ComparisonOffer
        ValueObjects/               #     DateOfBirth, VehicleType, UsageType, Price, CampaignDiscount,
                                    #     DiscountType, CoverageLevel, ProviderId, ProviderName
        Exception/                  #     InvalidQuoteRequest (product.md §1.5)
      Infra/
        ThirdParty/                 #     las APIs externas: una carpeta por provider, con todo lo relacionado con él
          ProviderA/                #       ProviderAAdapter (y, si se extraen, sus mappers y DTOs de payload)
          ProviderB/                #       ProviderBAdapter
          ProviderC/                #       ProviderCAdapter
        Observability/              #     PrometheusComparisonMetrics: única clase con nombres de métrica y etiquetas (observability.md §3.4)
      UI/
        Http/                       #     ComparisonController (controlador invocable de POST /comparison; solo traduce HTTP)
  ProviderSimulation/               # módulo aparte, fuera de la plataforma y del dominio (§3)
    ProviderA/                      #   endpoint simulado y comportamiento del Provider A (providers.md §3.1)
    ProviderB/                      #   ídem, Provider B (providers.md §3.2)
    ProviderC/                      #   ídem, Provider C (providers.md §3.3)
    ProviderOutcomePicker           #   interfaz del punto de sustitución (providers.md §3.4)
    RandomProviderOutcomePicker     #   implementación real, basada en las probabilidades de providers.md §3
  Observability/                    # módulo aparte, fuera de la plataforma y del dominio (observability.md §9)
    MetricsController               #   GET /metrics
    ...                             #   subscribers de request_id y de excepciones, configuración de Monolog y del almacén APCu, comando de tráfico de demo
```

Ejemplo de crecimiento (ilustrativo, no implementado):

```
Comparator/
  CarInsurance/     # hoy
  Hotels/           # ejemplo de futura categoría: carpeta hermana con sus cuatro capas (no existe)
```

`Comparator` es la plataforma: una carpeta que agrupa los bounded contexts, uno por categoría de producto (hoy solo `CarInsurance`), y que no es un bounded context en sí. Cada bounded context es un módulo con cuatro capas:

- **`Application`**: casos de uso y los contratos que estos necesitan del exterior. `ProviderPort` vive junto a su único consumidor, `ComparisonService`, porque es `ComparisonService` quien define qué necesita de un provider. Por el mismo criterio, `ComparisonMetrics`, el puerto de salida de observabilidad, va también aquí, junto a su consumidor, `CompareQuotes` (`observability.md` §3.4).
- **`Domain`**: el modelo y los value objects, sin frameworks ni entrada/salida. `Model` agrupa los objetos de dominio compuestos (`QuoteRequest`, `ComparisonOffer`); `ValueObjects` agrupa los value objects y los enums de dominio. Hoy todos son objetos inmutables: el ejercicio no persiste estado. Las excepciones de dominio (`InvalidQuoteRequest`) van en `Exception`.
- **`Infra`**: adaptadores hacia el mundo exterior. Todo lo relacionado con APIs de terceros va en `Infra/ThirdParty`, con una carpeta por provider que contiene todo lo suyo: su adapter (`providers.md` §2) y, si se extraen, sus mappers y DTOs de payload. Añadir un provider nuevo (§1) es añadir una carpeta nueva, sin tocar las existentes. `Infra/Observability` contiene el adaptador de métricas (`observability.md` §3.4).
- **`UI`**: puntos de entrada de la aplicación. Hoy solo `Http`.

Reglas de la estructura:

- **Crecimiento.** Una categoría nueva (por ejemplo, `Hotels`) es un bounded context nuevo: una carpeta hermana de `CarInsurance` dentro de `Comparator`, con sus cuatro capas. No existe una carpeta común tipo `Shared`: si algún día dos bounded contexts llegaran a compartir un concepto, se decidiría entonces cómo modelarlo, con un módulo de nombre propio por concepto y no con un cajón común.
- **Uso de "Port".** El sufijo `Port` se reserva para las interfaces de salida hacia sistemas externos (`ProviderPort`, `providers.md` §1). El punto de entrada HTTP es un controlador invocable de Symfony con sufijo `Controller` (`ComparisonController`); en términos hexagonales es el adaptador de entrada al que §4.1 llama "puerto de entrada".
- **`ProviderSimulation`.** Es un módulo aparte, al mismo nivel que `Comparator` y no dentro de él, porque no es parte del negocio: simula sistemas externos que en un sistema real serían partners de verdad. Al estar separado, se puede eliminar sin tocar el dominio. Sigue el mismo patrón que `Infra/ThirdParty`: una carpeta por provider simulado, y añadir el Provider D es crear `ProviderSimulation/ProviderD/`, sin tocar lo existente. La ruta del endpoint usa el mismo id que declara el adapter correspondiente (`providers.md` §1).
- **`Observability`.** Módulo aparte, al mismo nivel que `Comparator` y `ProviderSimulation`, por el mismo criterio: contiene lo genérico de la observabilidad (el endpoint `GET /metrics`, la asignación del `request_id`, el registro de los `500`, la configuración de logs y del almacén de métricas, el comando de tráfico de demo), que no es negocio y no debe contaminar el dominio. Lo específico de la comparación (nombres de métrica y etiquetas) vive en `Comparator/CarInsurance/Infra/Observability`.
- Los tests del backend reflejan esta misma estructura (`testing.md` §5).

## 8. Referencia cruzada

- La definición completa de `QuoteRequest`, la decisión de funnel multi-página, la persistencia del formulario, y el modelo completo de la campaña de marketing (`Price`, `CampaignDiscount`, consecuencias testables TC-P1–TC-P7) están en `product.md`.
- El contrato completo del puerto `ProviderPort`, los tipos `ProviderResult` / `ProviderFailureReason` / `ComparisonOffer`, el comportamiento simulado detallado de cada provider y la estrategia de timeout con sus consecuencias testables están en `providers.md`.
- La estrategia de test (niveles, herramientas, estructura de carpetas de test y trazabilidad de los TC) está en `testing.md`.
- La especificación de observabilidad (qué se mide, qué se registra en logs, dashboard, alertas y setup de Prometheus y Grafana) está en `observability.md`.
- Las alternativas consideradas y descartadas (timeout por SLA vs. único, estado local vs. store, etc.) están en `decisions.md`.
