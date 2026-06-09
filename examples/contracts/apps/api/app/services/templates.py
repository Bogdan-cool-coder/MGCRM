"""Загрузка шаблонов: master_skeleton, product/country YAML.

Хранение: PostgreSQL (таблица templates) + seed из файлов при первом старте.
"""

from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Any

import yaml
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.models import LicensorEntity, Template, TemplateVariable, TemplateVariableType

settings = get_settings()


# ============ Категории шаблонов (Эпик 3) ============
# Whitelist категорий документа. Каждая категория — отдельный вид документа
# (главный сублицензионный, допсоглашение, уведомление, акт, расторжение).
# Используется при PATCH /templates/by-code/{code} и в админ-UI (страница
# /admin/templates → редактирование category).
TEMPLATE_CATEGORIES: tuple[str, ...] = (
    "sublicense_main",
    "addendum",
    "notice",
    "act",
    "cancellation",
)

# Человеко-читаемые имена категорий — для UI Variables, group-by на странице
# /admin/templates и форм PATCH. Не предназначены для i18n (только RU).
TEMPLATE_CATEGORY_LABELS: dict[str, str] = {
    "sublicense_main": "Сублицензионный (основной)",
    "addendum": "Дополнительное соглашение",
    "notice": "Уведомление",
    "act": "Акт",
    "cancellation": "Расторжение",
}


# ============ Seed на первом старте ============

async def reseed_unchanged_templates(session: AsyncSession) -> None:
    """Перезаписать в БД только те шаблоны, которые ещё не правил юрист (version=1)
    — если файл в репо обновился. Безопасно, не затирает кастомные правки."""
    base = settings.templates_dir

    files = [
        ("master_skeleton", "md", "Master Skeleton (структура договора)", base / "master_skeleton.md"),
    ]
    for path in sorted((base / "products").glob("product_*.yaml")):
        code = path.stem
        files.append((code, "yaml", f"Продукт: {code.removeprefix('product_').upper()}", path))
    for path in sorted((base / "countries").glob("country_*.yaml")):
        code = path.stem
        files.append((code, "yaml", f"Страна: {code.removeprefix('country_').upper()}", path))

    for code, kind, title, path in files:
        if not path.exists():
            continue
        file_content = path.read_text(encoding="utf-8")
        existing = (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()
        if existing is None:
            session.add(Template(code=code, kind=kind, title=title, content=file_content))
        elif existing.version == 1 and existing.content != file_content:
            # Юрист ещё не правил — обновляем из репозитория
            existing.content = file_content
    await session.commit()


async def seed_templates_from_files(session: AsyncSession) -> None:
    """Если в таблице templates пусто — залить из файлов в templates/contracts_master/."""
    existing = (await session.execute(select(Template).limit(1))).scalar_one_or_none()
    if existing:
        return

    base = settings.templates_dir

    # master_skeleton.md
    master = base / "master_skeleton.md"
    if master.exists():
        session.add(Template(
            code="master_skeleton",
            kind="md",
            title="Master Skeleton (структура договора)",
            content=master.read_text(encoding="utf-8"),
        ))

    # product_*.yaml
    for path in sorted((base / "products").glob("product_*.yaml")):
        code = path.stem  # product_macrocrm
        session.add(Template(
            code=code,
            kind="yaml",
            title=f"Продукт: {code.removeprefix('product_').upper()}",
            content=path.read_text(encoding="utf-8"),
        ))

    # country_*.yaml
    for path in sorted((base / "countries").glob("country_*.yaml")):
        code = path.stem  # country_kz
        session.add(Template(
            code=code,
            kind="yaml",
            title=f"Страна: {code.removeprefix('country_').upper()}",
            content=path.read_text(encoding="utf-8"),
        ))

    await session.commit()


async def seed_licensors_from_files(session: AsyncSession) -> None:
    """Если licensor_entities пусто — залить из country_*.yaml блока licensor."""
    existing = (await session.execute(select(LicensorEntity).limit(1))).scalar_one_or_none()
    if existing:
        return

    base = settings.templates_dir / "countries"
    for path in sorted(base.glob("country_*.yaml")):
        country_code = path.stem.removeprefix("country_")
        with path.open(encoding="utf-8") as f:
            data = yaml.safe_load(f)
        lic = data.get("licensor")
        if not lic:
            continue
        session.add(LicensorEntity(
            country_code=country_code,
            is_default=True,
            legal_form=lic.get("legal_form", ""),
            full_legal_form=lic.get("full_legal_form", ""),
            gender_ending_oe=lic.get("gender_ending_ое", "ое"),
            name=lic.get("name", ""),
            director_position=lic.get("director_position", "Директора"),
            director_short=lic.get("director_short", ""),
            director_genitive=lic.get("director_genitive", ""),
            acts_basis=lic.get("acts_basis", "Устава"),
            tax_id_label=lic.get("tax_id_label", ""),
            tax_id=lic.get("tax_id", ""),
            address=lic.get("address", ""),
            bank=lic.get("bank", ""),
            bank_code_label=lic.get("bank_code_label", ""),
            bank_code=lic.get("bank_code", ""),
            account=lic.get("account", ""),
            phone=lic.get("phone"),
            email=lic.get("email"),
            website=lic.get("website"),
            training_login=lic.get("training_login"),
        ))
    await session.commit()


# ============ Чтение из БД ============

async def get_template(session: AsyncSession, code: str) -> Template | None:
    return (await session.execute(select(Template).where(Template.code == code))).scalar_one_or_none()


async def load_master_skeleton(session: AsyncSession) -> str:
    """Legacy: для .md шаблонов. После pivot на docxtpl master_skeleton хранится в файле .docx,
    эта функция используется только для совместимости и для админ-просмотра .md."""
    tpl = await get_template(session, "master_skeleton")
    if tpl and tpl.content:
        return tpl.content
    md_path = settings.templates_dir / "master_skeleton.md"
    if md_path.exists():
        return md_path.read_text(encoding="utf-8")
    return ""


async def load_product(session: AsyncSession, product_code: str) -> dict[str, Any]:
    tpl = await get_template(session, f"product_{product_code}")
    if tpl:
        return yaml.safe_load(tpl.content)
    # fallback
    path = settings.templates_dir / "products" / f"product_{product_code}.yaml"
    if not path.exists():
        raise FileNotFoundError(f"Product not found: {product_code}")
    with path.open(encoding="utf-8") as f:
        return yaml.safe_load(f)


async def load_country(session: AsyncSession, country_code: str) -> dict[str, Any]:
    tpl = await get_template(session, f"country_{country_code}")
    if tpl:
        return yaml.safe_load(tpl.content)
    path = settings.templates_dir / "countries" / f"country_{country_code}.yaml"
    if not path.exists():
        raise FileNotFoundError(f"Country not found: {country_code}")
    with path.open(encoding="utf-8") as f:
        return yaml.safe_load(f)


async def list_products(session: AsyncSession) -> list[dict[str, Any]]:
    tpls = (await session.execute(
        select(Template).where(Template.code.like("product_%")).order_by(Template.code)
    )).scalars().all()
    if not tpls:
        # fallback на файлы
        result = []
        for path in sorted((settings.templates_dir / "products").glob("product_*.yaml")):
            code = path.stem.removeprefix("product_")
            with path.open(encoding="utf-8") as f:
                result.append({"code": code, **yaml.safe_load(f)})
        return result
    return [{"code": t.code.removeprefix("product_"), **yaml.safe_load(t.content)} for t in tpls]


async def list_countries(session: AsyncSession) -> list[dict[str, Any]]:
    tpls = (await session.execute(
        select(Template).where(Template.code.like("country_%")).order_by(Template.code)
    )).scalars().all()
    if not tpls:
        result = []
        for path in sorted((settings.templates_dir / "countries").glob("country_*.yaml")):
            code = path.stem.removeprefix("country_")
            with path.open(encoding="utf-8") as f:
                result.append({"code": code, **yaml.safe_load(f)})
        return result
    return [{"code": t.code.removeprefix("country_"), **yaml.safe_load(t.content)} for t in tpls]


async def get_licensor_for_country(
    session: AsyncSession,
    country_code: str,
    override_id: int | None = None,
) -> dict[str, Any]:
    """Берём актуального лицензиара из БД, fallback на yaml.licensor.
    Если задан override_id — берём именно его (для перевыбора в карточке договора)."""
    lic = None
    if override_id:
        lic = (await session.execute(
            select(LicensorEntity).where(LicensorEntity.id == override_id)
        )).scalar_one_or_none()
    if not lic:
        lic = (await session.execute(
            select(LicensorEntity).where(LicensorEntity.country_code == country_code)
        )).scalar_one_or_none()
    if lic:
        return {
            "legal_form": lic.legal_form,
            "full_legal_form": lic.full_legal_form,
            "gender_ending_ое": lic.gender_ending_oe,
            "name": lic.name,
            "director_position": lic.director_position,
            "director_short": lic.director_short,
            "director_genitive": lic.director_genitive,
            "acts_basis": lic.acts_basis,
            "tax_id_label": lic.tax_id_label,
            "tax_id": lic.tax_id,
            "address": lic.address,
            "bank": lic.bank,
            "bank_code_label": lic.bank_code_label,
            "bank_code": lic.bank_code,
            "account": lic.account,
            "phone": lic.phone or "",
            "email": lic.email or "",
            "website": lic.website or "",
            "training_login": lic.training_login or "_____________",
        }
    country = await load_country(session, country_code)
    return country.get("licensor", {})


# ============ Кастомные переменные шаблона ============

_CHECKBOX_TRUE = {True, "true", "True", "1", 1, "да", "Да", "on"}


async def load_active_variables(
    session: AsyncSession,
    product_code: str | None = None,
    country_code: str | None = None,
) -> list[TemplateVariable]:
    """Активные переменные, видимые для данного продукта/страны.

    Область видимости: пустой список product_codes/country_codes = «на всех».
    Иначе переменная видна только если product_code/country_code входит в список.
    В v1 UI всегда создаёт глобальные (пустые списки), но фильтр уже готов к привязке.
    """
    rows = (await session.execute(
        select(TemplateVariable)
        .where(TemplateVariable.is_active.is_(True))
        .order_by(TemplateVariable.sort_order, TemplateVariable.id)
    )).scalars().all()

    def matches(v: TemplateVariable) -> bool:
        prod_ok = (not v.product_codes) or (product_code in v.product_codes)
        country_ok = (not v.country_codes) or (country_code in v.country_codes)
        return prod_ok and country_ok

    return [v for v in rows if matches(v)]


def build_custom_context(variables: list[TemplateVariable], raw: dict[str, Any]) -> dict[str, Any]:
    """Строит namespace {{ custom.* }}: подставляет дефолты и форматирует по типу.

    - checkbox → «Да» / «Нет»
    - остальные → строка как есть (дата уже хранится в ДД.ММ.ГГГГ)
    Только определённые (активные) переменные попадают в контекст; значения удалённых
    переменных игнорируются, а их теги в .docx отрендерятся как _____ (SafeUndefined).
    """
    out: dict[str, Any] = {}
    for v in variables:
        val = raw.get(v.key)
        if v.var_type == TemplateVariableType.checkbox:
            if val is None or val == "":
                val = v.default_value
            out[v.key] = "Да" if val in _CHECKBOX_TRUE else "Нет"
            continue
        if val is None or (isinstance(val, str) and val.strip() == ""):
            val = v.default_value or ""
        out[v.key] = val
    return out


async def master_skeleton_version(session: AsyncSession) -> str:
    """SHA256[:12] от текущего master_skeleton.docx (или .md fallback)."""
    from app.services.render import get_master_skeleton_path
    docx_path = get_master_skeleton_path()
    if docx_path.exists():
        return hashlib.sha256(docx_path.read_bytes()).hexdigest()[:12]
    content = await load_master_skeleton(session)
    return hashlib.sha256(content.encode("utf-8")).hexdigest()[:12]
