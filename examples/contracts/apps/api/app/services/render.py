"""Pipeline генерации документа: docxtpl (.docx с jinja2-тегами) → LibreOffice headless → .pdf.

Pandoc больше не используется — все шаблоны теперь Word-файлы с docxtpl-разметкой.
Юрист редактирует master_skeleton.docx в Word, кладёт через UI (upload) или git push.
"""

from __future__ import annotations

import shutil
import subprocess
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation
from pathlib import Path
from typing import Any

from docxtpl import DocxTemplate
from jinja2 import Environment, Undefined

from app.config import get_settings

settings = get_settings()


class SafeUndefined(Undefined):
    """Chainable-undefined: НЕ бросает на доступ к атрибуту/индексу/итерации.

    Критично для рендера реального master_skeleton.docx: шаблон ссылается на
    top-level namespace'ы (`license`, `product`, `country`, `licensor`, `custom`
    и т.д.), которых часть контекстов (bulk-черновик, preview) не содержит вовсе.

    Базовый `jinja2.Undefined` переопределяет только `__str__`, но `__getattr__`/
    `__getitem__`/`__iter__`/арифметика по-прежнему бросают `UndefinedError`. Из-за
    этого `{{ license.number }}` при ОТСУТСТВУЮЩЕМ top-level `license` валил рендер
    (500 на 100% bulk-целей и в preview). Здесь мы делаем undefined «прозрачным»:
    любой доступ возвращает ещё один SafeUndefined, итерация даёт пустую
    последовательность, а рендер в текст — '_____'. Так шаблон с любым набором
    отсутствующих переменных дорендеривается без исключения.
    """

    # Доступ к атрибуту отсутствующего namespace → снова SafeUndefined (chainable):
    # {{ license.payment_schedule }} при отсутствующем license не падает.
    def __getattr__(self, name: str) -> "SafeUndefined":
        # Не перехватываем dunder'ы (jinja/инспекция полагается на них).
        if name.startswith("__") and name.endswith("__"):
            raise AttributeError(name)
        return SafeUndefined(name=name)

    # Индексация/ключи: {{ license['number'] }} / {{ items[0] }} → SafeUndefined.
    def __getitem__(self, key: object) -> "SafeUndefined":
        return SafeUndefined(name=str(key))

    # Итерация по отсутствующей коллекции ({% for x in missing %}) → пусто, не ошибка.
    def __iter__(self):  # type: ignore[override]
        return iter(())

    def __len__(self) -> int:
        return 0

    # Вызов отсутствующего ({{ missing() }}) тоже не должен валить рендер.
    def __call__(self, *args: object, **kwargs: object) -> "SafeUndefined":
        return SafeUndefined()

    def __str__(self) -> str:
        return "_____"

    def __html__(self) -> str:
        return "_____"

    def __bool__(self) -> bool:
        return False

    def __eq__(self, other: object) -> bool:
        return isinstance(other, Undefined)

    def __ne__(self, other: object) -> bool:
        return not self.__eq__(other)

    def __hash__(self) -> int:
        return id(type(self))


# ============ Jinja-фильтры для шаблонов (Эпик 3) ============

# Локализованные единицы: суффикс для рублей и копеек (или эквивалент).
# kk (казахский) у num2words не покрывает все формы, ставим простой fallback.
# Ключ — язык; для подбора по коду валюты см. _CURRENCY_LANG.
_MONEY_SUFFIXES = {
    "ru": ("рублей", "копеек"),
    "kk": ("теңге", "тиын"),
    "kz": ("теңге", "тиын"),
    "en": ("dollars", "cents"),
}

# Код валюты → суффиксы единиц. Если в контекст договора передан currency
# (KZT/RUB/USD/…), берём правильную единицу независимо от языка прописи. Так
# сумма не остаётся «без валюты», когда нет позиций прайса (баг аудита #5).
_CURRENCY_SUFFIXES: dict[str, tuple[str, str]] = {
    "RUB": ("рублей", "копеек"),
    "RUR": ("рублей", "копеек"),
    "KZT": ("тенге", "тиын"),
    "USD": ("долларов США", "центов"),
    "EUR": ("евро", "центов"),
    "UZS": ("сумов", "тийинов"),
    "KGS": ("сомов", "тыйынов"),
    "BYN": ("белорусских рублей", "копеек"),
    "UAH": ("гривен", "копеек"),
}


def _split_amount(value: Any) -> tuple[int, int] | None:
    """Округляет сумму ДО 2 знаков и делит на (целые, копейки 0..99).

    Округление выполняется ОДИН раз до целочисленного числа копеек, затем
    `divmod` гарантирует, что дробная часть строго в диапазоне 0..99 — поэтому
    случай «...100 копеек» математически невозможен (баг аудита #4): перенос
    в рубли уже учтён округлением. Возвращает None для пустого/невалидного входа.
    """
    if value is None or value == "":
        return None
    try:
        d = Decimal(str(value))
    except (InvalidOperation, ValueError, TypeError):
        return None
    # Округляем к ближайшим копейкам как целое число копеек (HALF_UP) — единый
    # источник истины и для рублей, и для копеек, без рассинхрона.
    cents = int((d * 100).to_integral_value(rounding=ROUND_HALF_UP))
    whole, fraction = divmod(abs(cents), 100)
    if cents < 0:
        whole = -whole
    return whole, fraction


def _resolve_money_suffixes(lang: str, currency: str | None) -> tuple[str, str]:
    """Подбирает (единица_целых, единица_дробных). Приоритет — код валюты."""
    if currency:
        cur = currency.strip().upper()
        if cur in _CURRENCY_SUFFIXES:
            return _CURRENCY_SUFFIXES[cur]
    return _MONEY_SUFFIXES.get(lang, ("", ""))


def _money_in_words(value: Any, lang: str = "ru", currency: str | None = None) -> str:
    """Сумма прописью с подстановкой единицы и количества копеек/центов.

    Пример: 1234.56 (lang="ru") → "одна тысяча двести тридцать четыре рублей 56 копеек".
    `currency` (код, напр. "KZT"/"USD") имеет приоритет над `lang` при выборе
    единицы — гарантирует валюту в прописи, даже если позиций прайса нет (#5).
    Кейсы:
    - None / "" → пустая строка
    - неконвертируемое значение → str(value) (graceful: не валим рендер шаблона)
    - kk локаль num2words не всегда поддерживает — ловим LookupError и
      падаем в "ru" как safe fallback.
    """
    if value is None or value == "":
        return ""
    parts = _split_amount(value)
    if parts is None:
        return str(value)
    whole, fraction = parts
    try:
        from num2words import num2words
    except ImportError:  # pragma: no cover — в проде num2words есть всегда
        return str(value)
    try:
        words = num2words(whole, lang=lang)
    except (NotImplementedError, LookupError):
        words = num2words(whole, lang="ru")
        lang = "ru"
    suffix_whole, suffix_fraction = _resolve_money_suffixes(lang, currency)
    if suffix_whole:
        return f"{words} {suffix_whole} {fraction:02d} {suffix_fraction}"
    # Для языков без явного суффикса — компактная XX/100 запись.
    return f"{words} {fraction:02d}/100"


def _num_in_words(value: Any, lang: str = "ru") -> str:
    """Число прописью без суффикса валюты.

    Пример: 42 → "сорок два". Дробная часть округляется к ближайшему целому.
    None / "" → пустая строка; невалидное значение → str(value).
    """
    if value is None or value == "":
        return ""
    try:
        from num2words import num2words
    except ImportError:  # pragma: no cover
        return str(value)
    try:
        # Округляем к целому: num2words рассчитан на int. Decimal('1.7') → 2.
        n = int(Decimal(str(value)).to_integral_value())
    except (InvalidOperation, ValueError, TypeError):
        return str(value)
    try:
        return num2words(n, lang=lang)
    except (NotImplementedError, LookupError):
        return num2words(n, lang="ru")


def _build_jinja_env(default_currency: str | None = None) -> Environment:
    """Создаёт jinja Environment с SafeUndefined и нашими фильтрами.

    Вынесено в отдельную функцию для тестов и для preview-эндпоинта.

    `default_currency` — код валюты договора (из contract_data.contract.currency).
    Если шаблон вызывает `{{ amount | money_in_words }}` без явного currency,
    подставляется этот дефолт — так сумма прописью НЕ остаётся без валюты, когда
    позиций прайса нет (баг аудита #5). Явный `money_in_words(currency=...)` в
    шаблоне всё ещё переопределяет дефолт.
    """
    env = Environment(undefined=SafeUndefined, autoescape=False)

    def money_in_words_filter(value: Any, lang: str = "ru", currency: str | None = None) -> str:
        return _money_in_words(value, lang=lang, currency=currency or default_currency)

    env.filters["money_in_words"] = money_in_words_filter
    env.filters["num_in_words"] = _num_in_words
    return env


def get_master_skeleton_path() -> Path:
    """Путь к актуальному master_skeleton.docx.

    Приоритет:
    1. /data/storage/templates/master_skeleton.docx (если есть — юрист загрузил через UI)
    2. /app/templates/contracts_master/master_skeleton.docx (из репо)
    """
    overlay = settings.storage_dir / "templates" / "master_skeleton.docx"
    if overlay.exists():
        return overlay
    builtin = settings.templates_dir / "master_skeleton.docx"
    return builtin


def save_uploaded_master_skeleton(content_bytes: bytes) -> Path:
    """Записывает новый master_skeleton.docx в storage overlay (поверх встроенного)."""
    overlay_dir = settings.storage_dir / "templates"
    overlay_dir.mkdir(parents=True, exist_ok=True)
    overlay = overlay_dir / "master_skeleton.docx"
    overlay.write_bytes(content_bytes)
    return overlay


def docx_to_pdf(docx_path: Path, output_dir: Path) -> Path:
    """Конвертация DOCX → PDF через LibreOffice headless."""
    cmd = [
        "soffice",
        "--headless",
        "--convert-to", "pdf",
        "--outdir", str(output_dir),
        str(docx_path),
    ]
    result = subprocess.run(cmd, check=True, capture_output=True, text=True, timeout=120)
    if result.returncode != 0:
        raise RuntimeError(f"soffice failed: {result.stderr}")

    pdf_path = output_dir / (docx_path.stem + ".pdf")
    if not pdf_path.exists():
        raise RuntimeError(f"PDF was not created at {pdf_path}")
    return pdf_path


def _enrich_product(product: dict[str, Any]) -> dict[str, Any]:
    """Добавляет product.modules_flat — плоский список модулей для динамической таблицы.
    section показывается только в первой строке каждой группы (для merge-эффекта)."""
    p = dict(product)
    flat = []
    for section in product.get("modules_table", []) or []:
        section_name = section.get("name", "")
        modules = section.get("modules", []) or []
        for i, m in enumerate(modules):
            flat.append({
                "section": section_name if i == 0 else "",
                "name": m.get("name", ""),
                "status": m.get("status", ""),
            })
    p["modules_flat"] = flat
    return p


def render_docx(
    template_path: Path,
    product: dict[str, Any],
    country: dict[str, Any],
    licensor: dict[str, Any],
    contract_data: dict[str, Any],
    output_path: Path,
) -> Path:
    """Рендерит .docx-шаблон через docxtpl с jinja2-контекстом."""
    tpl = DocxTemplate(str(template_path))
    context = {
        "product": _enrich_product(product),
        "country": country,
        "licensor": licensor,
        **contract_data,
    }
    # Валюта договора → дефолт для money_in_words (#5): берём из contract.currency,
    # с фолбэком на country.currency_code, чтобы сумма прописью всегда была с
    # единицей даже без позиций прайса.
    contract_block = contract_data.get("contract") or {}
    default_currency = (
        (contract_block.get("currency") if isinstance(contract_block, dict) else None)
        or (country.get("currency_code") if isinstance(country, dict) else None)
    )
    tpl.render(context, jinja_env=_build_jinja_env(default_currency=default_currency))
    tpl.save(str(output_path))
    return output_path


def generate_contract_files(
    contract_id: int,
    product: dict[str, Any],
    country: dict[str, Any],
    licensor: dict[str, Any],
    contract_data: dict[str, Any],
) -> tuple[Path, Path]:
    """Полный пайплайн. Возвращает (docx_path, pdf_path)."""
    out_dir = settings.storage_dir / "contracts" / str(contract_id)
    out_dir.mkdir(parents=True, exist_ok=True)

    template_path = get_master_skeleton_path()
    if not template_path.exists():
        raise FileNotFoundError(f"master_skeleton.docx не найден: {template_path}")

    docx_path = out_dir / "contract.docx"
    render_docx(template_path, product, country, licensor, contract_data, docx_path)

    pdf_path = docx_to_pdf(docx_path, out_dir)
    return docx_path, pdf_path
