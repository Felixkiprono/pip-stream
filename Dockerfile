# One image, many services: each compose service runs `pipstream run <service>`.
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1
RUN useradd --system --uid 10001 --no-create-home app
WORKDIR /app
COPY pyproject.toml README.md ./
COPY src ./src
RUN pip install ".[telegram,mt5]"
COPY config ./config
USER 10001
CMD ["pipstream", "--help"]
