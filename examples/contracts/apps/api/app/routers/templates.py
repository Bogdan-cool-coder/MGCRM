"""Эндпоинты шаблонов: product/country YAML, master_skeleton.docx.

Чтение и редактирование. Список продуктов/стран для UI выбора.
Для master_skeleton.docx — download/upload .docx-файла.

Эпик 3: категории шаблонов + привязки (продукт/страна/категория клиента/отдел) +
эндпоинты `/variables` (каталог переменных для UI) и `/preview` (генерация .docx
с тестовыми данными — открывается в новой вкладке для проверки).
"""

from io import BytesIO
from pathlib import Path
from typing import Annotated, Any

import httpx
import yaml
from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile, status
from fastapi.responses import FileResponse, Response
from pydantic import BaseModel, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser, LawyerOrAdmin
from app.models import Template, TemplateVariable
from app.security import onlyoffice_verify
from app.services.onlyoffice import (
    build_editor_config,
    normalize_docx_jinja_runs,
    rewrite_ds_download_url,
)
from app.services.render import (
    docx_to_pdf,
    get_master_skeleton_path,
    render_docx,
    save_uploaded_master_skeleton,
)
from app.services.templates import (
    TEMPLATE_CATEGORIES,
    TEMPLATE_CATEGORY_LABELS,
    list_countries,
    list_products,
    load_active_variables,
    load_country,
    load_product,
    master_skeleton_version,
)

settings = get_settings()

DOCX_MEDIA = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
ALLOWED_DOCX_TYPES = {
    DOCX_MEDIA,
    "application/octet-stream",  # некоторые браузеры так шлют
}
MAX_TEMPLATE_SIZE = 20 * 1024 * 1024  # 20 МБ — реальные DOCX-шаблоны с картинками/таблицами выходят за 5

router = APIRouter(prefix="/templates", tags=["templates"])


@router.get("/products")
async def get_products(_: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return await list_products(session)


@router.get("/countries")
async def get_countries(_: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return await list_countries(session)


@router.get("/version")
async def get_version(_: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return {"master_skeleton": await master_skeleton_version(session)}


# ============ Конструктор шаблонов (для юриста/админа) ============


def _template_to_dict(t: Template, include_content: bool = False) -> dict[str, Any]:
    """Сериализация Template — единый формат для list + get-by-code.

    `include_content=False` (для list) экономит трафик на длинных DOCX-blob'ах
    (которые у нас редки, но всё же); `True` (для get-by-code) добавляет content.
    Возвращает category и привязки из Эпика 3.
    """
    out: dict[str, Any] = {
        "id": t.id,
        "code": t.code,
        "kind": t.kind,
        "title": t.title,
        "version": t.version,
        "updated_at": t.updated_at,
        "category": t.category,
        "product_codes": list(t.product_codes or []),
        "country_codes": list(t.country_codes or []),
        "client_category_codes": list(t.client_category_codes or []),
        "department_ids": list(t.department_ids or []),
    }
    if include_content:
        out["content"] = t.content
    return out


@router.get("/categories")
async def list_template_categories(_: LawyerOrAdmin):
    """Whitelist категорий шаблонов с человеко-читаемыми именами (Эпик 3).

    Используется UI на /admin/templates: выпадающий список при редактировании
    Template.category и при создании ApprovalRoute (template_category).
    """
    return [{"code": c, "label": TEMPLATE_CATEGORY_LABELS[c]} for c in TEMPLATE_CATEGORIES]


@router.get("")
async def list_all_templates(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    tpls = (await session.execute(select(Template).order_by(Template.code))).scalars().all()
    return [_template_to_dict(t) for t in tpls]


@router.get("/by-code/{code}")
async def get_template_by_code(
    code: str,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    tpl = (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()
    if not tpl:
        raise HTTPException(404, "Шаблон не найден")
    return _template_to_dict(tpl, include_content=True)


class TemplateUpdate(BaseModel):
    """Patch-схема: контент опционален + привязки/category.

    Поле `content` остаётся опциональным для обратной совместимости — старый UI
    шлёт только content без полей привязок. Новый UI (Эпик 3) шлёт привязки
    отдельным запросом без content.
    """
    content: str | None = None
    category: str | None = None
    product_codes: list[str] | None = None
    country_codes: list[str] | None = None
    client_category_codes: list[str] | None = None
    department_ids: list[int] | None = None


@router.patch("/by-code/{code}")
async def update_template(
    code: str,
    payload: TemplateUpdate,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    tpl = (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()
    if not tpl:
        raise HTTPException(404, "Шаблон не найден")

    data = payload.model_dump(exclude_unset=True)

    # 1) Привязки/category — обновляем без bump version (это метаданные, не контент).
    if "category" in data:
        cat = data["category"]
        if cat is not None and cat not in TEMPLATE_CATEGORIES:
            raise HTTPException(
                400,
                f"Недопустимая категория: {cat}. Допустимы: {', '.join(TEMPLATE_CATEGORIES)}",
            )
        tpl.category = cat
    for field in ("product_codes", "country_codes", "client_category_codes", "department_ids"):
        if field in data:
            setattr(tpl, field, data[field] or [])

    # 2) Контент — bump version, валидация YAML.
    if "content" in data and data["content"] is not None:
        content = data["content"]
        if tpl.kind == "yaml":
            try:
                yaml.safe_load(content)
            except yaml.YAMLError as e:
                raise HTTPException(400, f"YAML ошибка: {e}") from e
        tpl.content = content
        tpl.version += 1
        tpl.updated_by_user_id = current_user.id

    await session.commit()
    await session.refresh(tpl)
    return {
        "id": tpl.id,
        "code": tpl.code,
        "version": tpl.version,
        "category": tpl.category,
        "product_codes": list(tpl.product_codes or []),
        "country_codes": list(tpl.country_codes or []),
        "client_category_codes": list(tpl.client_category_codes or []),
        "department_ids": list(tpl.department_ids or []),
    }


# ============ Variables: каталог переменных доступных в шаблоне (Эпик 3) ============

# Намспейсы переменных. Хардкодим базовые поля по типу: они описывают
# фактический контекст рендера (см. render.py: contract_data + product + country
# + licensor + custom). Custom — динамические из TemplateVariable.
_BASE_VARIABLES: list[dict[str, Any]] = [
    {
        "namespace": "contract",
        "label": "Договор",
        "vars": [
            {"key": "contract.number", "label": "Номер договора", "example": "Д-2026-001"},
            {"key": "contract.date", "label": "Дата договора", "example": "30.05.2026"},
            {"key": "contract.city", "label": "Город заключения", "example": "Алматы"},
            {"key": "contract.total", "label": "Сумма договора", "example": "1 234 567.00"},
            {"key": "contract.currency", "label": "Валюта договора", "example": "KZT"},
            {"key": "contract.total_in_words", "label": "Сумма прописью", "example": "один миллион…"},
        ],
    },
    {
        "namespace": "counterparty",
        "label": "Контрагент (сублицензиат)",
        "vars": [
            {"key": "sublicensee.name", "label": "Краткое наименование", "example": "ТОО «Ромашка»"},
            {"key": "sublicensee.full_legal_form", "label": "Полная форма", "example": "Товарищество с ограниченной ответственностью «Ромашка»"},
            {"key": "sublicensee.director_short", "label": "ФИО директора", "example": "И. И. Иванов"},
            {"key": "sublicensee.director_position", "label": "Должность", "example": "Директора"},
            {"key": "sublicensee.tax_id", "label": "БИН/ИНН", "example": "123456789012"},
            {"key": "sublicensee.address", "label": "Адрес", "example": "Алматы, ул. ..."},
            {"key": "sublicensee.bank", "label": "Банк", "example": "АО «Kaspi Bank»"},
            {"key": "sublicensee.account", "label": "Расчётный счёт", "example": "KZ00..."},
        ],
    },
    {
        "namespace": "licensor",
        "label": "Лицензиар (наша компания)",
        "vars": [
            {"key": "licensor.name", "label": "Название", "example": "MACRO Global"},
            {"key": "licensor.full_legal_form", "label": "Полная форма", "example": "ТОО «MACRO Global»"},
            {"key": "licensor.director_short", "label": "Директор", "example": "Б. Ядыкин"},
            {"key": "licensor.tax_id", "label": "БИН", "example": "..."},
            {"key": "licensor.address", "label": "Адрес", "example": "..."},
            {"key": "licensor.bank", "label": "Банк", "example": "..."},
            {"key": "licensor.account", "label": "Счёт", "example": "..."},
        ],
    },
    {
        "namespace": "product",
        "label": "Продукт",
        "vars": [
            {"key": "product.name", "label": "Название продукта", "example": "MACRO CRM"},
            {"key": "product.implementation_weeks", "label": "Срок внедрения (нед.)", "example": "8"},
            {"key": "product.training_hours_default", "label": "Часов обучения", "example": "12"},
            {"key": "product.has_data_archive_clause", "label": "Архив данных (флаг)", "example": "true"},
            {"key": "product.modules_flat", "label": "Список модулей (для таблицы)", "example": "[...]"},
        ],
    },
    {
        "namespace": "country",
        "label": "Страна",
        "vars": [
            {"key": "country.name_short", "label": "Краткое название", "example": "Казахстан"},
            {"key": "country.name_full", "label": "Полное название", "example": "Республика Казахстан"},
            {"key": "country.currency_name", "label": "Название валюты", "example": "тенге"},
            {"key": "country.currency_code", "label": "Код валюты", "example": "KZT"},
        ],
    },
]

_AVAILABLE_FILTERS: list[dict[str, str]] = [
    {
        "name": "money_in_words",
        "description": "Сумма прописью с валютой (rub/копейки). Пример: {{ contract.total | money_in_words }}",
    },
    {
        "name": "num_in_words",
        "description": "Число прописью без валюты. Пример: {{ product.training_hours_default | num_in_words }}",
    },
]


@router.get("/by-code/{code}/variables")
async def template_variables_catalog(
    code: str,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Каталог переменных, доступных в данном шаблоне (Эпик 3).

    Возвращает namespace-группы (contract / counterparty / licensor / product /
    country) + динамические custom-переменные из `TemplateVariable`, отфильтрованные
    по product_codes/country_codes привязок самого шаблона.

    Фильтрация custom: если у Template указаны product_codes/country_codes —
    показываем только переменные, у которых пересечение непустое (или которые
    глобальные = пустые списки). Это даёт юристу видеть «что я могу использовать
    в этом шаблоне» без шума.
    """
    tpl = (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()
    if not tpl:
        raise HTTPException(404, "Шаблон не найден")

    # Динамические custom-переменные. Если у шаблона привязка к одному продукту —
    # используем его как фильтр; если несколько — берём первый (для simple UI;
    # дизайн расширения — за designer'ом). Если привязок нет — все активные.
    product_code = (tpl.product_codes or [None])[0]
    country_code = (tpl.country_codes or [None])[0]
    custom_vars = await load_active_variables(session, product_code, country_code)
    custom_namespace = {
        "namespace": "custom",
        "label": "Кастомные (юрист)",
        "vars": [
            {
                "key": f"custom.{v.key}",
                "label": v.label,
                "example": v.default_value or "",
                "var_type": v.var_type.value,
            }
            for v in custom_vars
        ],
    }

    return {
        "template": {
            "code": tpl.code,
            "title": tpl.title,
            "category": tpl.category,
        },
        "namespaces": [*_BASE_VARIABLES, custom_namespace],
        "filters": _AVAILABLE_FILTERS,
    }


# ============ Preview: рендер шаблона с тестовыми данными (Эпик 3) ============


class TemplatePreviewIn(BaseModel):
    """Опциональный override продукта/страны для preview.

    Если не передано — берём первый product_code/country_code привязки шаблона,
    fallback — `macrocrm` / `kz` (исторические дефолты для master_skeleton).
    """
    product_code: str | None = None
    country_code: str | None = None
    as_pdf: bool = False  # True → конвертим через LibreOffice (медленнее)


def _dummy_contract_data() -> dict[str, Any]:
    """Тестовый контекст для preview-рендера: placeholder-значения для всех namespace.

    Сублицензиат, реквизиты, суммы, дата — всё «[ТЕСТ]»-маркеры, чтобы юрист сразу
    видел, что это не настоящий договор. licensor/product/country подкладывается
    из YAML-файлов в preview-эндпоинте отдельно (через load_country/load_product).
    """
    return {
        "contract": {
            "number": "Д-ТЕСТ-001",
            "date": "30.05.2026",
            "city": "[ТЕСТ-Город]",
            "total": "1 000 000.00",
            "currency": "KZT",
            "total_in_words": "один миллион тенге 00 тиын",
        },
        "sublicensee": {
            "name": "[ТЕСТ] ТОО «Ромашка»",
            "full_legal_form": "[ТЕСТ] Товарищество с ограниченной ответственностью «Ромашка»",
            "legal_form": "ТОО",
            "director_position": "Директора",
            "director_short": "[ТЕСТ] И. И. Иванов",
            "director_genitive": "[ТЕСТ] Иванова Ивана Ивановича",
            "acts_basis": "Устава",
            "tax_id_label": "БИН",
            "tax_id": "[ТЕСТ-БИН]",
            "address": "[ТЕСТ] г. Алматы, ул. Тестовая 1",
            "bank": "[ТЕСТ] АО «Kaspi Bank»",
            "bank_code_label": "БИК",
            "bank_code": "[ТЕСТ-БИК]",
            "account": "[ТЕСТ] KZ00000000000000",
            "phone": "+7 (000) 000-00-00",
            "email": "test@example.com",
        },
        # license namespace с парой тестовых строк графика — чтобы юрист на
        # preview видел, как рендерятся таблицы платежей/актов master_skeleton.
        "license": {
            "type": "[ТЕСТ] коробочная",
            "implementation_start_date": "01.07.2026",
            "price_amount_words": "один миллион тенге 00 тиын",
            "payment_schedule": [
                {"date": "01.07.2026", "amount": "500 000.00", "comment": "[ТЕСТ] аванс"},
                {"date": "01.08.2026", "amount": "500 000.00", "comment": "[ТЕСТ] остаток"},
            ],
            "act_schedule": [
                {"date": "01.08.2026", "name": "[ТЕСТ] акт внедрения"},
            ],
        },
        "custom": {},  # сюда лягут дефолты TemplateVariable (см. load_active_variables)
    }


@router.post("/by-code/{code}/preview")
async def template_preview(
    code: str,
    payload: TemplatePreviewIn,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Preview-генерация шаблона с тестовыми данными (Эпик 3).

    Поддерживает только DOCX-шаблоны (на сегодня — только `master_skeleton`).
    Для md/yaml-шаблонов возвращает 400: они рендерятся как часть master, отдельный
    preview бессмыслен.

    Тяжёлый эндпоинт (LibreOffice занимает 300-800ms на конверсию PDF), вызывается
    редко — admin/lawyer вручную нажимает «Preview» на странице /admin/templates.
    Не пишет в БД, не модифицирует overlay.
    """
    tpl = (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()
    if not tpl:
        raise HTTPException(404, "Шаблон не найден")

    # На сегодня preview работает только для master_skeleton (единственный .docx
    # шаблон). product_*/country_* — это YAML-данные, не самостоятельные документы.
    if code != "master_skeleton":
        raise HTTPException(
            400,
            "Preview доступен только для DOCX-шаблонов (на сегодня — master_skeleton)",
        )

    template_path = get_master_skeleton_path()
    if not template_path.exists():
        raise HTTPException(404, "master_skeleton.docx не найден на диске")

    # Подбираем product / country: payload override → привязки шаблона → дефолты.
    product_code = (
        payload.product_code
        or (tpl.product_codes or [None])[0]
        or "macrocrm"
    )
    country_code = (
        payload.country_code
        or (tpl.country_codes or [None])[0]
        or "kz"
    )

    try:
        product = await load_product(session, product_code)
    except FileNotFoundError as e:
        raise HTTPException(400, f"Продукт не найден: {product_code}") from e
    try:
        country = await load_country(session, country_code)
    except FileNotFoundError as e:
        raise HTTPException(400, f"Страна не найдена: {country_code}") from e

    licensor = country.get("licensor", {})

    # Custom-переменные: подкладываем дефолты (как в обычном рендере). Если у переменной
    # нет default — оставляем "", SafeUndefined даст "_____" в шаблоне.
    custom_vars = await load_active_variables(session, product_code, country_code)
    contract_data = _dummy_contract_data()
    contract_data["custom"] = {v.key: (v.default_value or "") for v in custom_vars}

    # Изолируем preview-артефакты в storage/contracts/_preview (отдельный каталог,
    # чтобы не пересекаться с реальными договорами и не индексироваться по contract_id).
    settings = get_settings()
    preview_dir: Path = settings.storage_dir / "contracts" / "_preview"
    preview_dir.mkdir(parents=True, exist_ok=True)
    docx_path = preview_dir / f"preview_{code}.docx"

    try:
        render_docx(template_path, product, country, licensor, contract_data, docx_path)
    except Exception as e:  # noqa: BLE001
        raise HTTPException(500, f"Ошибка рендера: {e}") from e

    if payload.as_pdf:
        try:
            pdf_path = docx_to_pdf(docx_path, preview_dir)
        except Exception as e:  # noqa: BLE001
            raise HTTPException(500, f"Ошибка конверсии PDF (LibreOffice): {e}") from e
        return FileResponse(
            pdf_path,
            media_type="application/pdf",
            filename=f"preview_{code}.pdf",
        )

    # Inline DOCX — браузер скачает или Word откроет.
    return FileResponse(
        docx_path,
        media_type=DOCX_MEDIA,
        filename=f"preview_{code}.docx",
    )


# ============ Master skeleton .docx (download/upload) ============

@router.get("/master-skeleton/info")
async def master_skeleton_info(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Метаданные актуального master_skeleton.docx."""
    path = get_master_skeleton_path()
    if not path.exists():
        raise HTTPException(404, "master_skeleton.docx не найден")
    version = await master_skeleton_version(session)
    return {
        "version": version,
        "size": path.stat().st_size,
        "is_custom": "storage" in str(path),  # True = загружен через UI, False = из репо
        "onlyoffice_ready": settings.onlyoffice_ready,  # доступен ли WYSIWYG-редактор
    }


@router.get("/master-skeleton/download")
async def download_master_skeleton(_: LawyerOrAdmin):
    """Скачать актуальный master_skeleton.docx для редактирования в Word."""
    path = get_master_skeleton_path()
    if not path.exists():
        raise HTTPException(404, "master_skeleton.docx не найден")
    return FileResponse(path, filename="master_skeleton.docx", media_type=DOCX_MEDIA)


@router.post("/master-skeleton/upload", status_code=status.HTTP_201_CREATED)
async def upload_master_skeleton(
    _: LawyerOrAdmin,
    file: UploadFile = File(...),
):
    """Залить новую версию master_skeleton.docx (после редактирования в Word).
    Сохраняется в storage overlay — переживает деплой."""
    if not file.filename.lower().endswith(".docx"):
        raise HTTPException(400, "Допустимы только .docx файлы")

    data = await file.read()
    if len(data) > MAX_TEMPLATE_SIZE:
        raise HTTPException(400, f"Файл больше {MAX_TEMPLATE_SIZE // (1024 * 1024)} МБ")

    # Базовая валидация: попытаться открыть как docx
    try:
        from docxtpl import DocxTemplate
        DocxTemplate(BytesIO(data))
    except Exception as e:  # noqa: BLE001
        raise HTTPException(400, f"Файл не открывается как .docx: {e}") from e

    # Чиним разорванные jinja-теги в runs (Word/OnlyOffice/любой редактор может
    # разбить `{{ x }}` на несколько `<w:r>` — тогда docxtpl их не распознает).
    # Та же нормализация применяется в onlyoffice-callback; здесь — для ручной
    # загрузки .docx, отредактированного в Word (баг аудита #3).
    try:
        data = normalize_docx_jinja_runs(data)
    except Exception as e:  # noqa: BLE001
        raise HTTPException(400, f"Не удалось нормализовать jinja-теги в .docx: {e}") from e

    path = save_uploaded_master_skeleton(data)
    return {"ok": True, "path": str(path), "size": len(data)}


@router.delete("/master-skeleton/upload", status_code=status.HTTP_204_NO_CONTENT)
async def revert_master_skeleton(_: LawyerOrAdmin):
    """Сбросить overlay → вернуть к версии из репо."""
    path = get_master_skeleton_path()
    overlay = path  # это уже overlay если есть
    if "storage" in str(overlay) and overlay.exists():
        overlay.unlink()


# ============ WYSIWYG-редактор master_skeleton.docx (OnlyOffice Document Server) ============

@router.get("/master-skeleton/editor-config")
async def master_skeleton_editor_config(
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Подписанный JWT-конфиг для DocEditor + публичный URL Document Server (грузить api.js).
    Только юрист/админ. 503, если редактор не настроен на сервере."""
    if not settings.onlyoffice_ready:
        raise HTTPException(503, "OnlyOffice не настроен на сервере")
    path = get_master_skeleton_path()
    if not path.exists():
        raise HTTPException(404, "master_skeleton.docx не найден")
    version = await master_skeleton_version(session)
    config = build_editor_config(
        version=version,
        user_id=current_user.id,
        user_name=current_user.full_name,
    )
    return {"ds_url": settings.onlyoffice_public_url.rstrip("/"), "config": config}


@router.get("/master-skeleton/raw")
async def master_skeleton_raw(token: str):
    """Отдаёт master_skeleton.docx Document Server'у (server-to-server, без cookie-сессии).
    Доступ только по короткоживущему подписанному токену (scope=oo-download).
    Guard onlyoffice_ready: при выключенной фиче секрет пуст → токен подделываем пустым
    ключом, поэтому эндпоинт должен быть закрыт, а не отдавать шаблон."""
    if not settings.onlyoffice_ready:
        raise HTTPException(503, "OnlyOffice не настроен")
    claims = onlyoffice_verify(token)
    if not claims or claims.get("scope") != "oo-download" or claims.get("code") != "master_skeleton":
        raise HTTPException(403, "Невалидный токен")
    path = get_master_skeleton_path()
    if not path.exists():
        raise HTTPException(404, "master_skeleton.docx не найден")
    return FileResponse(path, filename="master_skeleton.docx", media_type=DOCX_MEDIA)


@router.post("/master-skeleton/onlyoffice-callback")
async def master_skeleton_onlyoffice_callback(request: Request):
    """Callback Document Server. При сохранении (status 2 = MustSave / 6 = MustForceSave)
    скачиваем отредактированный .docx из кэша DS, чиним разорванные jinja-теги и кладём
    в storage overlay (переживает деплой). JWT обязателен. Всегда отвечаем {"error": 0}."""
    if not settings.onlyoffice_ready:
        raise HTTPException(503, "OnlyOffice не настроен")
    body = await request.json()

    # JWT: DS кладёт подпись в заголовок Authorization: Bearer <jwt> и/или в body.token
    token = None
    auth = request.headers.get("Authorization", "")
    if auth.lower().startswith("bearer "):
        token = auth[7:]
    token = token or body.get("token")
    if not token:
        raise HTTPException(403, "Нет токена OnlyOffice")
    claims = onlyoffice_verify(token)
    if claims is None:
        raise HTTPException(403, "Невалидный токен OnlyOffice")
    # В подписанном токене callback'а — те же поля, что в body (иногда вложены в payload)
    data = claims.get("payload", claims)

    status_code = data.get("status", body.get("status"))
    if status_code in (2, 6):  # MustSave / MustForceSave
        url = data.get("url") or body.get("url")
        if url:
            internal_url = rewrite_ds_download_url(url)
            async with httpx.AsyncClient(timeout=60) as client:
                resp = await client.get(internal_url)
                resp.raise_for_status()
            save_uploaded_master_skeleton(normalize_docx_jinja_runs(resp.content))

    return {"error": 0}
