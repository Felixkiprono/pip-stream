# PipStream engine: one image, one container. `pipstream run engine` starts the intake, risk
# and executor workers as child processes (see src/pipstream/apps/engine.py).
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1
RUN useradd --system --uid 10001 --no-create-home app
WORKDIR /app
# dependencies first so code-only changes rebuild in seconds
COPY pyproject.toml ./
RUN mkdir -p src/pipstream && touch src/pipstream/__init__.py \
 && pip install ".[telegram,mt5]" && pip uninstall -y pipstream
COPY src ./src
RUN pip install --no-deps .
COPY config ./config
USER 10001
EXPOSE 8001
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s CMD ["pipstream", "health", "engine"]
CMD ["pipstream", "run", "engine"]
