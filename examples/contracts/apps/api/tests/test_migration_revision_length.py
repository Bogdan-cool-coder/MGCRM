"""Regression-тест: revision IDs всех Alembic-миграций должны влезать в
`alembic_version.version_num` (VARCHAR(32) по умолчанию).

Прод-инцидент 2026-05-31 (run 26699517705): миграция 0029 имела
revision = "0029_counterparty_responsible_user" (34 chars) → StringDataRight
TruncationError при UPDATE alembic_version. Прод оставался на 0028, новые
api-реплики падали в crash-loop. Hotfix `e5b130f` сократил до
"0029_cpty_responsible_user".

Этот тест ловит проблему ДО деплоя — pytest падает локально и в CI если
кто-то добавит migration с revision >32 chars.
"""

import re
from pathlib import Path

# Лимит alembic_version.version_num. Значение из коробки Alembic; если
# когда-то изменим (через `alembic init` или custom env.py), увеличить и
# здесь.
ALEMBIC_VERSION_VARCHAR_LIMIT = 32

VERSIONS_DIR = Path(__file__).parent.parent / "alembic" / "versions"
_REVISION_RE = re.compile(r"^revision[^=]*=\s*[\"']([^\"']+)[\"']", re.M)


def _collect_revisions() -> list[tuple[str, str]]:
    """Возвращает list[(filename, revision_id)] для всех миграций."""
    out: list[tuple[str, str]] = []
    for f in sorted(VERSIONS_DIR.glob("*.py")):
        if f.name.startswith("_"):
            continue
        src = f.read_text(encoding="utf-8")
        m = _REVISION_RE.search(src)
        if not m:
            continue
        out.append((f.name, m.group(1)))
    return out


def test_all_alembic_revisions_fit_varchar_limit():
    """Каждый revision id ≤32 символов — иначе UPDATE alembic_version валится."""
    revisions = _collect_revisions()
    assert revisions, "No alembic migrations discovered — wrong path?"

    violations = [
        (filename, rev, len(rev))
        for filename, rev in revisions
        if len(rev) > ALEMBIC_VERSION_VARCHAR_LIMIT
    ]

    assert not violations, (
        f"Alembic revision IDs exceed VARCHAR({ALEMBIC_VERSION_VARCHAR_LIMIT}) limit. "
        f"Use short snake_case slug after the '0NNN_' prefix.\n"
        + "\n".join(
            f"  {filename}: revision='{rev}' is {length} chars (max {ALEMBIC_VERSION_VARCHAR_LIMIT})"
            for filename, rev, length in violations
        )
    )


def test_revisions_have_consistent_numeric_prefix():
    """Каждая миграция начинается с 4-значного префикса `0NNN_` для порядка."""
    revisions = _collect_revisions()
    bad_prefix = [
        (filename, rev)
        for filename, rev in revisions
        if not re.match(r"^\d{4}_", rev)
    ]
    assert not bad_prefix, (
        "All revisions must start with 4-digit `NNNN_` prefix:\n"
        + "\n".join(f"  {filename}: '{rev}'" for filename, rev in bad_prefix)
    )


def test_revisions_are_unique():
    """Защита от дублирующихся revision ID (cherry-pick / merge ошибки)."""
    revisions = _collect_revisions()
    seen: dict[str, str] = {}
    duplicates: list[tuple[str, str, str]] = []
    for filename, rev in revisions:
        if rev in seen:
            duplicates.append((rev, seen[rev], filename))
        else:
            seen[rev] = filename
    assert not duplicates, (
        "Duplicate revision IDs detected:\n"
        + "\n".join(
            f"  '{rev}' in both {first} and {second}"
            for rev, first, second in duplicates
        )
    )
