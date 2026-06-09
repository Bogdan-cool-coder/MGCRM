import asyncio
from logging.config import fileConfig

from sqlalchemy import pool
from sqlalchemy.engine import Connection
from sqlalchemy.ext.asyncio import async_engine_from_config

from alembic import context
from app.config import get_settings
from app.db import Base
from app import models  # noqa: F401  важно — чтобы модели зарегистрировались

config = context.config
if config.config_file_name:
    fileConfig(config.config_file_name)

settings = get_settings()
config.set_main_option("sqlalchemy.url", settings.database_url)

target_metadata = Base.metadata

# Стабильный ключ для pg_advisory_xact_lock — сериализует параллельные `alembic upgrade`
# от нескольких api-реплик (при rolling-деплое новые реплики стартуют почти одновременно).
# Вторая реплика блокируется на локе, после коммита первой видит, что апгрейдить нечего.
_MIGRATION_LOCK_KEY = 728_274_001


def run_migrations_offline() -> None:
    url = config.get_main_option("sqlalchemy.url")
    context.configure(
        url=url, target_metadata=target_metadata, literal_binds=True, dialect_opts={"paramstyle": "named"}
    )
    with context.begin_transaction():
        context.run_migrations()


def do_run_migrations(connection: Connection) -> None:
    context.configure(connection=connection, target_metadata=target_metadata, compare_type=True)
    with context.begin_transaction():
        # xact-lock освобождается автоматически при коммите/откате транзакции
        connection.exec_driver_sql(f"SELECT pg_advisory_xact_lock({_MIGRATION_LOCK_KEY})")
        context.run_migrations()


async def run_async_migrations() -> None:
    connectable = async_engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    async with connectable.connect() as connection:
        await connection.run_sync(do_run_migrations)
    await connectable.dispose()


def run_migrations_online() -> None:
    asyncio.run(run_async_migrations())


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
