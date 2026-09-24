# Prompts usados con el agente de implementación

Dejo constancia aquí de cómo dirigí al agente de codificación (Claude Code, Sonnet 5) durante la implementación: el prompt que lancé en cada iteración, tal cual se lanzó, y con qué alcance. No hay una única sesión "hazlo todo": es una serie de iteraciones cortas, cada una acotada a un objetivo concreto y revisada contra las specs antes de decidir la siguiente.

Los commits citados están en `historial.bundle`, en la raíz de la entrega (`git clone historial.bundle`).

## Configuración de las sesiones

Para que la prueba de autocontención de las specs (primera iteración) fuera honesta, ejecuté Claude Code con una carpeta de configuración dedicada, memoria automática desactivada y sin ningún `CLAUDE.md` de proyecto ni skills propias cargadas — solo el conector de Claude Docs de mi cuenta, que no aporta instrucciones de código. Sin esto, cualquier instrucción o hábito mío fuera de `/specs` habría falseado la comprobación de que las specs bastan por sí solas.

## Iteración 0 — prueba de autocontención

Es el prompt que CHECK24 indica que usa para comprobar si sus especificaciones son autocontenidas — lo lancé yo primero, sin cambiar nada, contra una copia de `entrega/` que solo tenía `specs/` dentro:

```
Check the specifications in the specs folder and implement the project specified here.
```

Con lo que produjo esta iteración revisé las specs punto por punto: dónde el agente preguntó, dónde inventó algo sin preguntar y dónde contradijo alguna spec. Esa revisión es la que está detrás de `decisions.md` y de los cambios entre versiones de `/specs` en el historial de commits. A partir de ahí, los prompts siguientes piden traer el código a la altura de la spec ya corregida, con alcance explícito. Los huecos que aparecieron después también se cerraron en la spec: un ajuste de nombres tras la iteración 1 (`architecture.md` §7.2, commit `82e3105`) y, en el pase final de coherencia, la regla de capas en los tests (`architecture.md` §2) y los sets de Rector (`testing.md` §4.1), que en las iteraciones 2 y 3 se habían pasado al agente directamente en el prompt.

## Iteración 1 — puerto de dos pasos, techo de tiempo, TIMEOUT y elapsedMs

```
The specifications in the specs folder have changed since the current implementation was written. Run `git diff 04cbb81 502d1c9 -- specs` to see exactly what changed, and bring the code in line with the updated specs.

Scope of this iteration, and nothing else:
- The provider port contract and the concurrency mechanism (providers.md §1, §2, §4; architecture.md §1, §3, §6, §7.2).
- The timeout ceiling, the TIMEOUT classification and how elapsedMs is measured (providers.md §1.1, §1.2, §5.1).
- The tests these changes require, including the new TC-7 and the extended TC-5 (testing.md §6).

Do not refactor or change anything unrelated to those changes, and do not modify the specs. When you finish, run `make test`, `make lint` and `make test-realtime`, and report the results.
```

Pido explícitamente el `git diff` sobre `specs/` como punto de partida: quiero que el agente trabaje contra lo que cambió en la spec, no contra su propia interpretación de qué falta. El alcance cerrado ("Scope of this iteration, and nothing else") es deliberado, para que una iteración no arrastre cambios fuera de lo que estoy revisando.

## Iteración 2 — tests/lint en contenedor, Rector y separación de capas

```
The implementation currently runs `make test`, `make lint` and `make coverage` on the host, but testing.md §4.1, §9 and §11 require PHPUnit, PHPStan, ECS, Rector and PCOV to be installed in a backend development image and run in a container instead — the evaluator should only need Docker, and tool versions should match the app's (PHP 8.4). `make test-realtime`, `make test-e2e` and `make test-alerts` are out of scope: they stay exactly as they are (testing.md §11).

Scope of this iteration, and nothing else:
- Add a development build of the backend (image or Dockerfile stage with dev dependencies) and move `make test` (backend + frontend), `make lint` and `make coverage` to run inside it, per testing.md §4.1 and §11.
- Add Rector as a check step in `make lint` — testing.md §4.1 lists PHPStan, ECS and Rector as the backend's static analysis, but the current `lint` target only runs PHPStan and ECS.
- Fix the layering violation flagged after iteration 1: a test in `Application/Compare` currently imports `LazyPendingQuote` from `Infra/ThirdParty` directly. Per architecture.md §2 (dependency inversion), `Application` tests must depend only on `Application`/`Domain` types (the `PendingQuote` interface or a test double), never on a concrete `Infra` class.

Do not refactor or change anything unrelated to those three points, and do not modify the specs. When you finish, run `make test`, `make lint` and `make coverage` (all inside the container), plus a clean-state `docker compose up` check, and report the results.
```

Los tres puntos de esta iteración vienen de sitios distintos: los dos primeros, de una spec (`testing.md`) que ya pedía esto y el código aún no cumplía; el tercero, de un hallazgo que dejé pendiente explícitamente al cerrar la iteración anterior. Los junté en un solo prompt con tres puntos, en vez de tres prompts sueltos, porque ninguno dependía del otro y quería una sola revisión de conjunto al final.

## Iteración 3 — ampliar Rector

```
`backend/rector.php` currently only enables the `deadCode` prepared set (testing.md §4.1); a manual dry-run check (outside this session) confirmed that broadening it to `codeQuality`, `typeDeclarations` and `earlyReturn` (RectorConfig::withPreparedSets), plus PHP 8.4 modernization (RectorConfig::withPhpSets(php84: true)), would affect 35 files, none of them behavior-changing (added type declarations Rector infers, readonly-class promotion, arrow-function return types, and similar mechanical rewrites).

Scope of this iteration, and nothing else:
- Update `backend/rector.php` to enable `deadCode`, `codeQuality`, `typeDeclarations` and `earlyReturn` prepared sets, plus `withPhpSets(php84: true)`.
- Apply Rector with this configuration (not dry-run) to `src/` and `tests/`. If `vendor/bin/rector process` fails with a parallel/child-process error, retry with `--debug` (single-process mode) — this is a known issue with this Rector version under parallel workers, not a code problem.
- After applying, run `make test`, `make lint` and `make coverage` to confirm nothing broke. If any test fails because of a Rector change, fix it by adjusting the test (not by reverting the Rector rule) unless the change looks genuinely wrong, in which case stop and report it instead of guessing.

Do not refactor or change anything beyond what Rector itself applies plus `rector.php`, and do not modify the specs. When you finish, also run `make test-realtime` and a clean-state `docker compose up` check (down -v, then build+up, confirm the four services and a real `POST /comparison` call), and report the results.
```

Antes de escribir este prompt hice yo mismo la comprobación de alcance (el dry-run con un `rector.php` temporal fuera del repo) para no delegarle al agente una decisión de "cuánto es razonable aplicar" sin haber visto antes el volumen de cambio. El número de ficheros afectados (35) y la ausencia de cambios de comportamiento son datos que ya tenía antes de lanzar el prompt, no algo que le pedí que evaluara él.

## Nota sobre el resto de cada sesión

Fuera de estos cuatro prompts (uno por iteración), la interacción con el agente durante cada sesión fue de aprobación de comandos dentro de `entrega/` y, cuando preguntaba algo sobre el proyecto no cubierto por la spec, la instrucción fija era "decide tú lo que consideres razonable y sigue" — cualquier cosa que decidiera así quedaba registrada como hallazgo de tipo invención en mi cuaderno de trabajo y se resolvía en la siguiente revisión, no se aceptaba en silencio.

Después de la iteración 3 hice dos retoques solo visuales del frontend (título, texto, cabecera y alineación del formulario) con ayuda del asistente de chat de Claude, no del agente de implementación. No cambian lógica ni specs (commits `148e491` y `17dbdc8`).
