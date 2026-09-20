# Product

## 0. Alcance de este documento

Este documento traduce las notas de producto deliberadamente ambiguas del brief (sección 1.2 de `Technical_case_semotor.pdf`) en decisiones concretas y testables, sin pedir aclaraciones a CHECK24 — tal y como exige el reto. Cubre:

- La definición completa de `QuoteRequest`: campos, tipos y validaciones.
- Cómo el formulario simple de hoy queda preparado para evolucionar a un funnel multi-página sin bloquear ese futuro.
- El mecanismo y alcance de la persistencia del formulario ante recarga de página.
- Cómo se modela la campaña de marketing que subvenciona parte del precio, y en qué punto del flujo se aplica.
- Por qué el requisito de mobile/desktop no tiene ninguna implicación de producto más allá de diseño responsive en el frontend.

Fuera de alcance de este documento (se referencian pero se definen en otro fichero de `/specs`):

- El contrato del puerto `ProviderPort` y el comportamiento simulado de cada provider — `providers.md`.
- El mecanismo de concurrencia, la estructura de carpetas y el contrato completo de `POST /comparison` (incluyendo cómo se integran las decisiones de este documento en la petición/respuesta) — `architecture.md`.
- La organización de la suite de tests — `testing.md`.
- Las métricas y dashboards de observabilidad — `observability.md`.
- Las alternativas consideradas y descartadas para cada decisión de este documento, con su razonamiento — `decisions.md`.

## 1. `QuoteRequest` — definición completa

`QuoteRequest` es el objeto de dominio que agrupa los tres datos que el brief pide explícitamente al cliente: fecha de nacimiento, tipo de coche, y uso privado/comercial. Es la entrada ya construida y validada que consumen los tres adapters de `providers.md` — este documento fija su forma completa; `providers.md` la usa como entrada dada, sin volver a fijarla.

```php
final class QuoteRequest
{
    public function __construct(
        public readonly DateOfBirth $birthDate,
        public readonly VehicleType $vehicleType,
        public readonly UsageType $usageType,
    ) {
    }
}
```

Deliberadamente no tiene más campos que estos tres. No se añade marca/modelo/año del vehículo, código postal, años de experiencia como conductor ni ningún otro dato habitual en un comparador de seguros real — el brief solo pide estos tres, y ampliarlos sería resolver un producto de tarificación real que el propio brief dice explícitamente que no pretende modelar (§1.1 del PDF: *"Esto no pretende modelar tarifas reales de seguros ni requisitos legales"*). Un identificador de borrador o de correlación para el funnel/persistencia no forma parte de este objeto de dominio ya validado — se trata como una preocupación de formulario/sesión en las secciones 2 y 3, no como un campo de `QuoteRequest`.

### 1.1 `DateOfBirth`

```php
final class DateOfBirth
{
    private const MIN_AGE = 18;
    private const MAX_AGE = 99;

    private function __construct(
        public readonly \DateTimeImmutable $value,
    ) {
    }

    public static function fromIsoString(string $isoDate): self
    {
        // Valida formato "YYYY-MM-DD" (ISO-8601), que la fecha sea real y pasada,
        // y que la edad resultante esté entre MIN_AGE y MAX_AGE (ambos inclusive).
        // Fecha inválida, mal formada o edad fuera de rango -> InvalidQuoteRequest (§1.5),
        // que el controlador traduce a 400 Bad Request (ver architecture.md §4.1).
    }
}
```

- **Formato: ISO-8601 (`YYYY-MM-DD`)**. No es una decisión de negocio, es la convención estándar por defecto para fechas en cualquier API — evita la ambigüedad de formatos regionales (`03/04/2026`), ordena bien como string, y la entienden de forma nativa tanto Symfony/PHP (`DateTimeImmutable`) como Vue/JS (`Date`). Se usa por ser la opción por defecto correcta, no por ningún motivo específico de este dominio.
- **Edad mínima: 18 años.** Regla de negocio razonable para poder asegurar un coche.
- **Edad máxima: 99 años.** No es una regla de negocio real (no hay un límite de edad para tener seguro), es una guarda de sanidad de datos para detectar fechas de nacimiento erróneas (p. ej. un typo de año). Se documenta como tal para que no se lea como una decisión de tarificación.

### 1.2 `VehicleType`

```php
enum VehicleType: string
{
    case SEDAN = 'SEDAN';
    case HATCHBACK = 'HATCHBACK';
    case SUV = 'SUV';
    case VAN = 'VAN';
}
```

Catálogo cerrado y deliberadamente pequeño de carrocerías reales del sector seguros de automoción (no inventadas para el ejercicio) — el mismo vocabulario que usaría una aseguradora real. No incluye marca, modelo ni año: el brief pide "tipo de coche", no una ficha técnica completa, y ampliarlo sería scope creep no pedido. `SEDAN` es obligatorio en el catálogo porque ya aparece en los tres payloads de ejemplo de `providers.md` §3; los otros tres valores completan un catálogo no trivial para que cada adapter deba demostrar su tabla de mapeo completa (ver `providers.md` §3.1–3.3 para el mapeo de cada provider a este enum).

### 1.3 `UsageType`

```php
enum UsageType: string
{
    case PRIVATE = 'PRIVATE';
    case COMMERCIAL = 'COMMERCIAL';
}
```

Se modela como enum de dos valores en vez de un booleano (`isCommercial`) para que el vocabulario de un provider concreto no se filtre al dominio. El propio Provider B representa este dato como `commercial_use: boolean` (`providers.md` §3.2) — si el dominio también fuera un booleano, sería indistinguible cuál de los dos "inventó" el booleano, y el adapter de B dejaría de necesitar traducir nada en este campo. Con el enum, cada adapter traduce en su dirección explícitamente: `PRIVATE`/`COMMERCIAL` → `false`/`true` para B, y a los vocabularios de A y C respectivamente.

### 1.4 Resumen de validación

| Campo | Regla |
|---|---|
| `birthDate` | Parseable como ISO-8601 `YYYY-MM-DD`; fecha real y pasada; edad entre 18 y 99 años (inclusive). |
| `vehicleType` | Debe ser uno de `SEDAN \| HATCHBACK \| SUV \| VAN`. |
| `usageType` | Debe ser uno de `PRIVATE \| COMMERCIAL`. |

Un `QuoteRequest` que incumpla cualquiera de estas reglas nunca llega a construirse — el intento de construcción lanza `InvalidQuoteRequest` (§1.5), que el controlador de `POST /comparison` traduce a `400 Bad Request`, sin invocar a ningún adapter de provider (contrato completo de la petición en `architecture.md` §4.1).

### 1.5 `InvalidQuoteRequest` y códigos de regla

Todo incumplimiento de §1.4 se señala con una única excepción de dominio, `InvalidQuoteRequest` (en `Domain/Exception`, `architecture.md` §7.2), que lleva dos datos:

- `field`: el nombre del campo tal y como aparece en el cuerpo de la petición (`birthDate`, `vehicleType`, `usageType`), o `body` si el cuerpo entero no es un JSON válido.
- `ruleCode`: un código estable de la regla incumplida (tabla siguiente).

Ni el mensaje de la excepción ni ninguno de sus campos incluyen el **valor** recibido: el `field` y el `ruleCode` bastan para diagnosticar, y así el `400` se puede registrar en logs sin datos personales (`observability.md` §4.5). Si varias reglas se incumplen a la vez, se señala la primera en el orden `birthDate`, `vehicleType`, `usageType`. El cuerpo de la respuesta `400` no forma parte del contrato (el frontend no lo muestra, `architecture.md` §5.3).

| `field` | `ruleCode` | Cuándo |
|---|---|---|
| `body` | `invalid_json` | El cuerpo no es JSON o no es un objeto. |
| cualquiera de los tres | `missing` | El campo no viene en el cuerpo. |
| cualquiera de los tres | `invalid_type` | El campo no es un texto. |
| `birthDate` | `invalid_format` | No cumple `YYYY-MM-DD`. |
| `birthDate` | `not_a_real_date` | Cumple el formato pero la fecha no existe (30 de febrero). |
| `birthDate` | `not_in_the_past` | La fecha no es anterior a hoy. |
| `birthDate` | `age_out_of_range` | La edad resultante es menor de 18 o mayor de 99. |
| `vehicleType`, `usageType` | `not_in_catalog` | El valor no pertenece a su catálogo cerrado. |

## 2. Formulario simple preparado para un futuro funnel multi-página

El brief pide explícitamente no diseñar el formulario simple de hoy de una forma que bloquee su evolución futura a un funnel de varias páginas. La decisión concreta es desacoplar tres cosas que, mezcladas, es justo lo que bloquearía ese futuro:

- **Estado del formulario, independiente de cualquier componente de página.** Los tres campos de `QuoteRequest` (como input crudo, antes de validar) viven en un composable/store (p. ej. Pinia) desacoplado del componente Vue que hoy renderiza el formulario de una sola página — no como estado local dentro de ese componente. Un componente Vue solo vive mientras está montado en pantalla; al navegar a otra página, Vue lo destruye junto con cualquier estado declarado dentro de él. Si el estado viviera en el componente de hoy, convertir el formulario en varios pasos obligaría a un refactor para sacar ese estado de ahí. Con el estado ya desacoplado desde el principio, añadir pasos en el futuro es solo añadir componentes/rutas que leen y escriben porciones de ese mismo estado — sin migración de datos ni refactor.
- **Validación por campo, no por formulario completo.** Cada campo se valida de forma independiente (misma lógica que definen los VOs de la sección 1), no como una validación monolítica de "todo el formulario es válido o no". Así, un futuro paso del funnel puede validar solo los campos que le corresponden sin depender de que el resto del formulario ya esté completo.
- **El contrato con el backend no sabe que existen "pasos".** `POST /comparison` sigue recibiendo un único `QuoteRequest` completo y ya válido, sin importar en cuántas páginas se construyó en el frontend. Esto es lo que garantiza que ampliar el funnel en el futuro no requiera ningún cambio en el backend.

En resumen: el formulario de una sola página de hoy es, técnicamente, un funnel de un solo paso. Añadir pasos después es añadir componentes/rutas sobre el mismo estado y la misma validación, no un rediseño.

**Alternativa considerada y descartada:** mantener el estado como propiedades locales del único componente de la página. Es la opción más directa para un formulario de un solo paso y funcionaría igual de bien hoy, pero obligaría a un refactor en cuanto el formulario creciera a varias páginas — que es exactamente lo que el brief pide evitar. Registrada también en `decisions.md`.

## 3. Persistencia del formulario ante recarga de página

El brief pide específicamente que una **recarga de página** (no un regreso días después) no haga sentir al cliente que ha perdido lo ya introducido. Eso acota el alcance:

- **Mecanismo: `sessionStorage`, no `localStorage`.** `sessionStorage` sobrevive exactamente a una recarga de la pestaña y se borra sola al cerrar la pestaña/navegador — cubre el requisito tal cual está escrito, ni más ni menos. `localStorage` sobreviviría indefinidamente, incluso días después, resolviendo un problema que no se ha pedido y dejando datos personales (la fecha de nacimiento) guardados sin límite de tiempo sin necesidad real.
- **Qué se persiste: el input crudo tal cual lo ha escrito el usuario, no solo los campos ya válidos.** Si el usuario recarga a mitad de escribir la fecha de nacimiento, debe recuperar exactamente lo que había escrito, sea o no todavía válido — la validación de dominio (sección 1) se sigue aplicando solo al construir el `QuoteRequest` para enviarlo, no antes. La implementación es un observador sobre el store descrito en la sección 2 que serializa a `sessionStorage` en cada cambio, y una hidratación desde `sessionStorage` al crear el store — no requiere tocar ningún componente.
- **Cuándo se borra: únicamente cuando `POST /comparison` responde `200`.** Una respuesta `200` significa que la comparación se completó con éxito (con independencia de cuántos providers hayan fallado dentro de ella — ver `architecture.md` §4.2). Una respuesta `400` (dato inválido) o `500` (fallo propio del backend) **no** limpia `sessionStorage`: el cliente todavía no ha completado nada, y borrarle los datos le obligaría a volver a teclearlos todos.

**Nota de alcance, documentada explícitamente:** guardar la fecha de nacimiento en claro en `sessionStorage` es aceptable para el alcance de este ejercicio. Un sistema real con datos personales de verdad requeriría medidas adicionales (cifrado, políticas de retención, consentimiento) que quedan fuera del alcance de esta prueba — es una decisión consciente de límite de alcance, no un descuido, con el mismo criterio que la decisión de no usar payloads reales de integradores de un empleador anterior (`decisions.md`).

## 4. Campaña de marketing — subvención de precio

### 4.1 Cómo se activa

Una única campaña activa a la vez — literalmente lo que dice el brief ("Marketing puede activar **una** campaña"), no un sistema de gestión de campañas con múltiples campañas, fechas de vigencia ni panel de administración: eso sería resolver un producto de gestión de campañas que nadie ha pedido. Se modela como parámetros de configuración de Symfony, en la misma línea que `app.providers.timeout_ms` de `providers.md` §5.1:

| Parámetro | Tipo | Descripción |
|---|---|---|
| `app.marketing.campaign.active` | bool | Si hay una campaña activa ahora mismo. |
| `app.marketing.campaign.discount_type` | enum `PERCENTAGE \| FIXED_AMOUNT` | Cómo se calcula la subvención. |
| `app.marketing.campaign.discount_value` | número | Porcentaje (0–100) si el tipo es `PERCENTAGE`; céntimos (≥ 0) si es `FIXED_AMOUNT`. |

### 4.2 `Price` y `CampaignDiscount`

El importe de una oferta se modela como un Value Object `Price` (patrón Money), en vez de los dos primitivos sueltos con los que se definió inicialmente `ComparisonOffer` en `providers.md` §1.3 — ese documento se actualiza en esta misma sesión para usar este VO (ver `providers.md` §1.3 tras esta revisión). Mantener importe y divisa como primitivos sueltos habría sido la única inconsistencia frente al resto del dominio, que ya modela todo como VOs (`DateOfBirth`, `VehicleType`, `UsageType`, y el propio `CampaignDiscount` de este punto).

```php
final class Price
{
    private function __construct(
        public readonly int $amountCents,
        public readonly string $currencyCode,
    ) {
        // Valida amountCents >= 0 y currencyCode soportada (solo "EUR" en este ejercicio).
    }

    public static function ofCents(int $amountCents, string $currencyCode = 'EUR'): self
    {
        return new self($amountCents, $currencyCode);
    }

    public function withDiscount(CampaignDiscount $discount): self
    {
        return self::ofCents($discount->applyTo($this->amountCents), $this->currencyCode);
    }
}
```

```php
enum DiscountType: string
{
    case PERCENTAGE = 'PERCENTAGE';
    case FIXED_AMOUNT = 'FIXED_AMOUNT';
}

final class CampaignDiscount
{
    private function __construct(
        private readonly DiscountType $type,
        private readonly int|float $value,
    ) {
    }

    public static function percentage(float $pct): self
    {
        // Valida 0 <= $pct <= 100.
        return new self(DiscountType::PERCENTAGE, $pct);
    }

    public static function fixedAmount(int $cents): self
    {
        // Valida $cents >= 0.
        return new self(DiscountType::FIXED_AMOUNT, $cents);
    }

    public function applyTo(int $priceAmountCents): int
    {
        $discounted = match ($this->type) {
            DiscountType::PERCENTAGE => (int) round($priceAmountCents * (1 - $this->value / 100)),
            DiscountType::FIXED_AMOUNT => $priceAmountCents - $this->value,
        };

        // Redondeo al céntimo más cercano, mitad hacia arriba (round half up).
        // Suelo en 0: el precio final nunca es negativo (relevante sobre todo
        // para FIXED_AMOUNT cuando el importe fijo supera el precio original).
        return max(0, $discounted);
    }
}
```

Encapsular tipo, valor, validación y cálculo juntos en `CampaignDiscount` evita que dos primitivos sueltos (`discount_type` + `discount_value`) puedan combinarse de forma inconsistente (p. ej., un valor pensado como porcentaje aplicado como si fuera `FIXED_AMOUNT`) — la validación ocurre en el constructor, igual que en `DateOfBirth`.

### 4.3 Dónde se aplica en el flujo

La campaña se aplica en un paso **posterior** a `ComparisonOffer`, fuera del dominio de comparación con providers — así quedó ya anotado en `providers.md` §0. Ni `ProviderPort` ni ningún adapter sabe que existe una campaña; siempre devuelven el precio real del provider. Un servicio de aplicación aparte (`CampaignPricingService`, especificado en `architecture.md` §6) se ejecuta después de que `ComparisonService` ya tiene el `ComparisonOffer[]` normalizado de los tres adapters, y antes de construir la respuesta JSON de `POST /comparison`. La campaña se aplica por igual a las tres ofertas presentes en la respuesta, sin distinción por `providerId` — es CHECK24 subvencionando al cliente, no un acuerdo negociado con un provider concreto.

### 4.4 Qué ve el cliente

El frontend muestra el precio original tachado junto al precio final rebajado cuando hay campaña activa (más persuasivo comercialmente que solo mostrar el precio ya rebajado, y no supone trabajo adicional relevante). Esto requiere que la respuesta de `POST /comparison` incluya ambos precios por oferta cuando la campaña está activa; el contrato completo se especifica en `architecture.md` §4.2. Cuando la campaña está inactiva, la respuesta no incluye el precio con descuento en ninguna oferta.

### 4.5 Consecuencias testables

- **TC-P1 (QuoteRequest inválido no llega a los providers)**: un `QuoteRequest` con `birthDate` fuera de formato ISO-8601 o con edad fuera de 18–99, o con `vehicleType`/`usageType` fuera de sus catálogos cerrados, hace que `POST /comparison` devuelva `400 Bad Request` sin invocar a ningún adapter.
- **TC-P2 (persistencia sobrevive a una recarga)**: tras rellenar parcialmente el formulario y recargar la pestaña sin cerrarla, los campos ya escritos —incluidos los inválidos o incompletos— se recuperan tal cual desde `sessionStorage`.
- **TC-P3 (persistencia se limpia solo en éxito)**: tras una respuesta `200` de `POST /comparison`, `sessionStorage` queda vacío para este formulario; tras una respuesta `400` o `500`, los datos siguen disponibles.
- **TC-P4 (campaña inactiva no altera la respuesta)**: con `app.marketing.campaign.active = false`, ninguna oferta de la respuesta incluye precio con descuento.
- **TC-P5 (campaña `PERCENTAGE`)**: con `active = true`, `discount_type = PERCENTAGE`, `discount_value = 10`, una oferta con precio 4250 céntimos obtiene un precio final de 3825 céntimos.
- **TC-P6 (campaña `FIXED_AMOUNT` con suelo en cero)**: con `discount_type = FIXED_AMOUNT` y un `discount_value` mayor que el precio de una oferta concreta, el precio final de esa oferta es 0, nunca negativo.
- **TC-P7 (la campaña no distingue provider)**: con la campaña activa, las ofertas de los providers que tuvieron éxito llevan el descuento calculado con la misma configuración, sin ninguna diferencia por `providerId`.

## 5. Mobile y desktop

El requisito de que la experiencia funcione bien en mobile y desktop es exclusivamente de diseño responsive en el frontend (Vue) — layout adaptable y elementos táctiles de tamaño adecuado. Se ha revisado explícitamente cada una de las cuatro decisiones anteriores buscando alguna implicación más allá de CSS/layout, y no hay ninguna:

- El funnel multi-página (sección 2) usa el mismo estado independientemente del dispositivo; solo cambia cuántos campos caben visualmente por pantalla.
- La persistencia con `sessionStorage` (sección 3) es una API del navegador que funciona igual en un navegador de escritorio que en uno móvil.
- El tachado de precio de la campaña (sección 4.4) es puramente visual.
- El timeout único de providers (`providers.md` §5.1) es una decisión de backend/red independiente del dispositivo que hizo la petición — no hay forma fiable de que el backend sepa si un cliente está en una red mala, y no se ha pedido ese matiz.

No hay, por tanto, ninguna decisión de dominio, arquitectura de backend, ni contrato de API que dependa del tipo de dispositivo. Es una única aplicación web responsive, no dos experiencias distintas.

## 6. Referencia cruzada

- El contrato completo de la petición y la respuesta de `POST /comparison` (incluyendo cómo se integran `QuoteRequest` y la campaña de marketing en ese contrato) está en `architecture.md` §4.
- El servicio `CampaignPricingService` que aplica `CampaignDiscount` sobre `ComparisonOffer[]` está especificado en `architecture.md` §6.
- El contrato del puerto `ProviderPort`, el tipo `ComparisonOffer` (que usa el `Price` VO definido aquí) y el comportamiento simulado de cada provider están en `providers.md`.
- Las alternativas consideradas y descartadas para cada decisión de este documento (estado local vs. store, `localStorage` vs. `sessionStorage`, cifrado del formulario, etc.) se registran en `decisions.md`.
- La forma ejecutable de las consecuencias testables de la sección 4.5 (herramientas, estructura de test) se especifica en `testing.md`.
