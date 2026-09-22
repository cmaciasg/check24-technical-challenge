# Comandos del proyecto (testing.md §11). `test`, `lint` y `coverage` solo necesitan Docker: se
# ejecutan en la imagen de desarrollo del backend y en la etapa `build` del frontend (testing.md §4.1).
COMPOSE      := docker compose
COMPOSE_TEST := docker compose -f docker-compose.yml -f docker-compose.test.yml
COMPOSE_DEV  := docker compose -f docker-compose.dev.yml
PROMTOOL     := docker run --rm --entrypoint promtool -v "$(CURDIR)/observability/prometheus:/etc/prometheus:ro" -w /etc/prometheus prom/prometheus:v3.5.0
TEST_URL     ?= http://localhost:8080

.PHONY: help install up down test test-backend test-frontend test-realtime test-e2e test-all lint test-alerts demo-traffic coverage

help:
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-16s %s\n", $$1, $$2}'

install: ## Instala las dependencias de backend, frontend y e2e
	cd backend && composer install
	cd frontend && npm ci
	cd e2e && npm ci && npx playwright install chromium

up: ## Levanta el sistema completo (app :8080, Grafana :3000, Prometheus :9090)
	$(COMPOSE) up --build -d

down: ## Para y borra el sistema
	$(COMPOSE) down -v

test-backend: ## Unitarios e integración del backend (en contenedor)
	$(COMPOSE_DEV) run --rm --build backend-dev vendor/bin/phpunit --testsuite unit,integration

test-frontend: ## Tests de Vitest (store, validación y componentes; en contenedor)
	$(COMPOSE_DEV) run --rm --build frontend-dev npm test

test: test-backend test-frontend ## Unitarios e integración (backend y frontend)

# El stack de test fuerza el resultado y la latencia de los providers por variable de entorno.
# Realtime: pico de C (3000 ms, por encima del timeout) y B en su máximo documentado (1800 ms).
test-realtime: ## Suite de tiempo real (levanta el stack de test)
	SIMULATION_FORCE=provider-c=spike SIMULATION_LATENCY_MS=provider-b=1800,provider-c=3000 \
		$(COMPOSE_TEST) up --build -d --force-recreate --wait backend frontend
	(cd backend && REALTIME_BASE_URL=$(TEST_URL) vendor/bin/phpunit --testsuite realtime); \
		status=$$?; $(COMPOSE_TEST) down -v; exit $$status

# E2E: Provider C forzado a HTTP 500 para que el resultado sea determinista.
test-e2e: ## E2E con Playwright (levanta el stack de test)
	$(COMPOSE_TEST) up --build -d --force-recreate --wait backend frontend
	(cd e2e && BASE_URL=$(TEST_URL) npx playwright test); \
		status=$$?; $(COMPOSE_TEST) down -v; exit $$status

test-all: test test-alerts test-realtime test-e2e ## Todo lo anterior

lint: ## PHPStan, ECS, Rector, vue-tsc y ESLint (en contenedor)
	$(COMPOSE_DEV) run --rm --build backend-dev sh -c "vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/ecs check && vendor/bin/rector process --dry-run"
	$(COMPOSE_DEV) run --rm --build frontend-dev sh -c "npm run typecheck && npm run lint"

# `--lint=none`: las reglas por defecto y su excepción de provider-c comparten nombre a propósito
# (observability.md §6.3) y el lint de duplicados de promtool lo rechazaría; la sintaxis se valida igual.
test-alerts: ## Valida y prueba las reglas de alerta con promtool (TC-O4)
	$(PROMTOOL) check rules --lint=none alerts.yml
	$(PROMTOOL) test rules tests/alerts.test.yml

demo-traffic: ## Genera tráfico de ejemplo para el dashboard
	$(COMPOSE) exec backend php bin/console app:observability:demo-traffic

coverage: ## Informe de cobertura del backend (PCOV, en contenedor)
	$(COMPOSE_DEV) run --rm --build backend-dev php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit,integration --coverage-text
