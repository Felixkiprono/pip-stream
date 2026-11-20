.PHONY: infra test test-php test-web test-engine build keys
infra:
	./scripts/test-infra.sh
test: test-php test-web test-engine
test-php:
	php artisan test
test-web:
	npm test
test-engine:
	PS_TEST_DATABASE_URL=$${PS_TEST_DATABASE_URL:-postgresql://postgres@127.0.0.1:55432/postgres} pytest -q engine/tests
build:
	npm run build
keys:
	@pipstream keys
