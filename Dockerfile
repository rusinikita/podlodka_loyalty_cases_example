FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /app
COPY pyproject.toml ./
COPY loyalty ./loyalty
RUN pip install .
COPY config ./config
RUN useradd --uid 10001 --no-create-home loyalty
USER loyalty
EXPOSE 8080
ENTRYPOINT ["loyalty"]
