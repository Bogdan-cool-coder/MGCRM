"""DB-trigger «один admin минимум»: запрет на удаление/деактивацию/смену роли
последнего admin.

Аудит майского ревью (Эпик 0): в текущей реализации можно случайно
- удалить последнего admin'а (DELETE FROM users WHERE role='admin'),
- деактивировать его (UPDATE users SET is_active=false),
- сменить роль (UPDATE users SET role='manager').

После такой операции к админскому UI/API не остаётся доступа. Backend deps
проверяют роль на каждом запросе, поэтому никакая последующая команда «вернуть
обратно admin» из системы не пройдёт — нужен ручной фикс в БД.

Решение — Postgres-триггер BEFORE UPDATE/DELETE на users. Проверяет, что после
изменения останется хотя бы один активный admin. Если нет — RAISE EXCEPTION.

Триггер срабатывает на:
- DELETE: проверяем что после удаления останутся другие active admin
- UPDATE: если меняется role с admin на не-admin ИЛИ is_active с true на false
  и текущий пользователь admin → проверяем остаток

Миграция DDL-only (создание function + trigger). Advisory-lock НЕ нужен:
- pg_advisory_xact_lock уже стоит на уровне env.py для всей миграции
- DDL-only без seed'а данных
- CREATE FUNCTION идемпотентен через CREATE OR REPLACE

Revision ID: 0028_ensure_one_admin_trigger
Revises: 0027_epic_4_2_fields
Create Date: 2026-05-31
"""
from typing import Sequence, Union

from alembic import op

revision: str = "0028_ensure_one_admin_trigger"
down_revision: Union[str, None] = "0027_epic_4_2_fields"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


# Функция-проверка: «должен остаться хотя бы один active admin после операции».
# Использует TG_OP для разветвления DELETE/UPDATE; считает оставшихся active
# админов с EXCLUSION текущей строки (для DELETE) или с учётом нового состояния
# (для UPDATE).
_CREATE_FUNCTION_SQL = """
CREATE OR REPLACE FUNCTION ensure_at_least_one_admin()
RETURNS TRIGGER AS $$
DECLARE
    remaining_admins INTEGER;
BEGIN
    IF TG_OP = 'DELETE' THEN
        -- Удаляем строку OLD. Считаем активных админов, исключая её.
        IF OLD.role = 'admin' AND OLD.is_active = true THEN
            SELECT COUNT(*) INTO remaining_admins
            FROM users
            WHERE role = 'admin'
              AND is_active = true
              AND id <> OLD.id;
            IF remaining_admins = 0 THEN
                RAISE EXCEPTION
                  'Нельзя удалить последнего активного администратора (user.id=%)',
                  OLD.id
                USING ERRCODE = 'check_violation';
            END IF;
        END IF;
        RETURN OLD;
    ELSIF TG_OP = 'UPDATE' THEN
        -- Если OLD был active admin, а NEW — нет (роль изменилась ИЛИ деактивирован):
        IF OLD.role = 'admin' AND OLD.is_active = true
           AND (NEW.role <> 'admin' OR NEW.is_active = false) THEN
            SELECT COUNT(*) INTO remaining_admins
            FROM users
            WHERE role = 'admin'
              AND is_active = true
              AND id <> OLD.id;
            IF remaining_admins = 0 THEN
                RAISE EXCEPTION
                  'Нельзя снять права последнего активного администратора (user.id=%)',
                  OLD.id
                USING ERRCODE = 'check_violation';
            END IF;
        END IF;
        RETURN NEW;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
"""

_DROP_FUNCTION_SQL = "DROP FUNCTION IF EXISTS ensure_at_least_one_admin();"

# Триггер BEFORE UPDATE OR DELETE на каждой строке users.
# FOR EACH ROW — обязательно, т.к. функция использует OLD/NEW.
_CREATE_TRIGGER_SQL = """
CREATE TRIGGER trg_ensure_at_least_one_admin
BEFORE UPDATE OR DELETE ON users
FOR EACH ROW
EXECUTE FUNCTION ensure_at_least_one_admin();
"""

_DROP_TRIGGER_SQL = "DROP TRIGGER IF EXISTS trg_ensure_at_least_one_admin ON users;"


def upgrade() -> None:
    op.execute(_CREATE_FUNCTION_SQL)
    op.execute(_CREATE_TRIGGER_SQL)


def downgrade() -> None:
    # Сначала trigger (зависит от function), потом function
    op.execute(_DROP_TRIGGER_SQL)
    op.execute(_DROP_FUNCTION_SQL)
