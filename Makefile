.PHONY: install test test-infra up down logs keys acl
install:
	pip install -e ".[dev,telegram,mt5]"
test-infra:
	./scripts/test-infra.sh
test:
	PS_TEST_DATABASE_URL=$${PS_TEST_DATABASE_URL:-postgresql://postgres@127.0.0.1:55432/postgres} pytest -q
keys:
	@pipstream keys
up:
	docker compose up -d --build
down:
	docker compose down
logs:
	docker compose logs -f --tail=100
