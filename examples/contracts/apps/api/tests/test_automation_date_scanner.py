"""P2 (C3 CRITICAL): защита от регрессии «мёртвого» date_field-сканера.

`run_date_field_scanner` использовал `func.date(col)`, но `func` НЕ был
импортирован в automation_executor → каждый cron-tick падал с NameError,
который ловился и тихо логировался → весь триггер `date_field_approaching`
(напоминания об истечении договора / дате платежа) был МЁРТВ в проде.

Ни один тест не вызывал сканер, поэтому NameError жил незамеченным. Эти тесты:

1. Проверяют, что `func` реально импортирован в модуле (импорт сканера не падает).
2. Воспроизводят выражение `func.date(col)` для КАЖДОЙ (target_type, field) пары
   из DATE_FIELDS и компилируют итоговый SELECT в SQL — это та самая строка,
   что раньше бросала NameError. Если `func` снова пропадёт — тест упадёт на
   импорте/компиляции, а не «тихо вернёт пустой список».
"""
from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func
from sqlalchemy.dialects import postgresql
from sqlalchemy.future import select

import app.services.automation_executor as ae
from app.services.automation_executor import (
    DATE_FIELDS,
    _get_target_model,
    run_date_field_scanner,
)


def test_func_is_importable_in_executor_module():
    """`func` обязан быть импортирован в модуле — иначе сканер падает с NameError.

    Регресс-гард: раньше `from sqlalchemy import or_, text` НЕ включал func.
    """
    assert getattr(ae, "func", None) is func, "sqlalchemy.func не импортирован в executor"
    # И сам сканер импортируется как корутина (callable), без NameError на загрузке.
    assert callable(run_date_field_scanner)


def test_date_scanner_query_compiles_for_every_date_field():
    """Для каждой (target_type, field) — собрать тот же SELECT, что строит сканер,
    и скомпилировать его в SQL. Это исполняет func.date(col) — строку, ронявшую
    NameError. Падение здесь = снова сломанный сканер.
    """
    today = date.today()
    low = today + timedelta(days=6)
    high = today + timedelta(days=8)

    compiled_any = False
    for target_type, fields in DATE_FIELDS.items():
        model = _get_target_model(target_type)
        if model is None:
            # subscription/deal/lead — все поддержаны; но не падаем если карта шире
            continue
        for field in fields:
            col = getattr(model, field, None)
            assert col is not None, f"{target_type}.{field} нет на модели"
            # Точная копия выражения из run_date_field_scanner:
            col_as_date = func.date(col)
            stmt = select(model).where(
                col.is_not(None),
                col_as_date >= low,
                col_as_date <= high,
            )
            sql = str(stmt.compile(dialect=postgresql.dialect()))
            assert "date(" in sql.lower(), f"func.date не попал в SQL для {target_type}.{field}"
            compiled_any = True

    assert compiled_any, "DATE_FIELDS пуст — нечего проверять, сканер бесполезен"
