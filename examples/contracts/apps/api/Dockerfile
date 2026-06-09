FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# pandoc для md→docx, libreoffice для docx→pdf
# curl для healthcheck
# fonts-roboto-unhinted — основной шрифт шаблона (без него Roboto подставляется чем попало в PDF)
# carlito/caladea — метрик-совместимы с Calibri/Cambria (дефолты Word, если юрист правит шаблон)
RUN apt-get update && apt-get install -y --no-install-recommends \
    pandoc \
    libreoffice \
    libreoffice-writer \
    fonts-liberation \
    fonts-dejavu \
    fonts-roboto-unhinted \
    fonts-crosextra-carlito \
    fonts-crosextra-caladea \
    curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY pyproject.toml ./
RUN pip install --upgrade pip && pip install -e .

COPY . .

EXPOSE 8000
CMD ["sh", "-c", "alembic upgrade head && uvicorn app.main:app --host 0.0.0.0 --port 8000 --proxy-headers --forwarded-allow-ips='*'"]
