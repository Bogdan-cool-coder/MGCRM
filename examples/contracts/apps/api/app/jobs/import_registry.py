"""Ф4e — импорт реестра клиентов из фактического списка (registry_import.tsv).

Дедуп: один контрагент на компанию (по нормализованному имени) + отдельная подписка
на каждую платформу со своим статусом ЖЦ. C1 → C0 (решение Богдана). Регион — best-effort
из страны; для фильтрации используются country + city.

Запуск dry-run (без записи в БД):
    python -m app.jobs.import_registry
Реальный импорт (после ОК) вызывается через run_import(session) из эндпоинта/скрипта.
"""
from __future__ import annotations

import re
from collections import Counter, defaultdict
from pathlib import Path

DATA_PATH = Path(__file__).resolve().parent.parent / "data" / "registry_import.tsv"

# страна → регион-справочник (best-effort; me/gb остаются без региона, фильтр по country/city)
# CS-hotfix (0080): добавлен ru → отдельный регион РФ.
REGION_BY_COUNTRY = {"kz": "ca", "uz": "ca", "kg": "ca", "tj": "ca", "tm": "ca",
                     "ge": "caucasus", "am": "caucasus", "az": "caucasus", "ae": "gcc",
                     "ru": "ru"}


def norm(s: str) -> str:
    return re.sub(r"[^a-zа-я0-9]", "", (s or "").lower())


def effective_code(status: str) -> str | None:
    """Статус из списка → код этапа ЖЦ. C1→C0; пусто → нет этапа."""
    if not status:
        return None
    return "C0" if status in ("C0", "C1") else status


def parse(path: Path = DATA_PATH) -> list[dict]:
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        parts = (line.split("\t") + ["", "", "", "", ""])[:5]
        platform, status, name, city, country = (p.strip() for p in parts)
        if not name:
            continue
        rows.append({"platform": platform, "status": status, "name": name,
                     "city": city, "country": country})
    return rows


def analyze(rows: list[dict]) -> dict:
    by_company: dict[str, list[dict]] = defaultdict(list)
    by_sub: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for r in rows:
        by_company[norm(r["name"])].append(r)
        by_sub[(norm(r["name"]), r["platform"])].append(r)

    conflicts = []
    for (_nm, pl), rs in by_sub.items():
        codes = {effective_code(r["status"]) for r in rs}
        if len(codes) > 1:
            conflicts.append((rs[0]["name"], pl, sorted(c or "—" for c in codes)))

    cross = []
    for _nm, rs in by_company.items():
        plats = sorted({r["platform"] for r in rs})
        if len(plats) > 1:
            cross.append((rs[0]["name"], plats))

    by_status = Counter(effective_code(r["status"]) or "— (нет)" for r in rows)
    by_platform = Counter(r["platform"] for r in rows)
    by_country = Counter(r["country"] or "—" for r in rows)
    no_region = sorted({r["country"] for r in rows if r["country"] and r["country"].lower() not in REGION_BY_COUNTRY})
    return {
        "rows": len(rows),
        "counterparties": len(by_company),
        "subscriptions": len(by_sub),
        "by_status": by_status,
        "by_platform": by_platform,
        "by_country": by_country,
        "conflicts": sorted(conflicts),
        "cross_platform": sorted(cross),
        "no_region_countries": no_region,
    }


def dry_run() -> dict:
    rows = parse()
    a = analyze(rows)
    print("=" * 64)
    print("DRY-RUN импорта реестра CS (Ф4e) — БЕЗ записи в БД")
    print("=" * 64)
    print(f"Строк в файле:                 {a['rows']}")
    print(f"Контрагентов (уник. компаний): {a['counterparties']}")
    print(f"Подписок (компания×платформа): {a['subscriptions']}")
    print("\nПо платформам:", dict(a["by_platform"]))
    print("По статусам ЖЦ:", dict(sorted(a["by_status"].items())))
    print("По странам:", dict(sorted(a["by_country"].items())))
    print(f"\nКомпаний на 2 платформах ({len(a['cross_platform'])}) → по 2 подписки:")
    for name, plats in a["cross_platform"]:
        print(f"  • {name}: {', '.join(plats)}")
    print(f"\n⚠ КОНФЛИКТЫ — один контрагент+платформа с разными статусами ({len(a['conflicts'])}):")
    for name, pl, codes in a["conflicts"]:
        print(f"  • {name} [{pl}]: {', '.join(codes)} — нужно решение Богдана")
    if a["no_region_countries"]:
        print(f"\nСтраны без региона в справочнике (фильтр по country/city): {a['no_region_countries']}")
    print("=" * 64)
    return a


async def run_import(session) -> dict:
    """CONTACTS 2.0 Ф3-B: match-or-create Company (источник истины) + mirror Counterparty.
    Подписки создаются/обновляются с company_id + counterparty_id (зеркало).
    Идемпотентно: существующие записи находит по name-ключу/id, не плодит дубли."""
    from datetime import UTC, datetime

    from sqlalchemy.future import select

    from app.models import ClientSubscription, Company, Counterparty, Platform, Region
    from app.services.customer_success import lifecycle_stage_by_code

    rows = parse()
    platforms = {p.code: p for p in (await session.execute(select(Platform))).scalars().all()}
    regions = {r.code: r for r in (await session.execute(select(Region))).scalars().all()}
    stage_map = await lifecycle_stage_by_code(session)

    # Загружаем Counterparty по нормализованному имени (зеркало)
    cps: dict[str, Counterparty] = {
        norm(c.name): c for c in (await session.execute(select(Counterparty))).scalars().all()
    }
    # Загружаем Company по нормализованному name || legal_name
    companies: dict[str, Company] = {}
    for co in (await session.execute(select(Company))).scalars().all():
        k = norm(co.name or co.legal_name or "")
        if k and k not in companies:
            companies[k] = co

    res = {"cp_created": 0, "cp_matched": 0, "sub_created": 0, "sub_updated": 0, "skipped": []}
    for r in rows:
        platform = platforms.get(r["platform"])
        if not platform:
            res["skipped"].append(f"{r['name']}: нет платформы {r['platform']}")
            continue
        # CS-hotfix (0080): per-row savepoint — одна битая строка (IntegrityError,
        # коллизия UNIQUE и т.п.) больше не валит весь импорт 500. Сбойную строку
        # откатываем до savepoint, логируем в skipped и идём дальше.
        try:
            async with session.begin_nested():
                await _import_one_row(
                    session, r, platform, regions, stage_map, cps, companies, res,
                )
        except Exception as exc:  # noqa: BLE001 — изоляция строки, детали в skipped
            res["skipped"].append(f"{r['name']} [{r['platform']}]: {exc}")
    await session.commit()
    return res


async def _import_one_row(session, r, platform, regions, stage_map, cps, companies, res) -> None:
    """Импорт одной строки реестра внутри savepoint. Исключения пробрасываются
    наверх — там savepoint откатывается, строка попадает в skipped."""
    from datetime import UTC, datetime

    from sqlalchemy.future import select

    from app.models import ClientSubscription, Company, Counterparty

    key = norm(r["name"])
    country_code = (r["country"] or "kz")[:2].lower()

    # ── 1. Counterparty (зеркало) ──
    cp = cps.get(key)
    if cp is None:
        cp = Counterparty(name=r["name"], country_code=country_code, city=r["city"] or None)
        session.add(cp)
        await session.flush()
        cps[key] = cp
        res["cp_created"] += 1
    else:
        res["cp_matched"] += 1
        if not cp.city and r["city"]:
            cp.city = r["city"]
        if r["country"]:
            cp.country_code = country_code

    # ── 2. Company (источник истины) ──
    company = companies.get(key)
    if company is None:
        # Попробуем найти по counterparty_id (после Ф0 миграции)
        if cp.id:
            company = (await session.execute(
                select(Company).where(Company.counterparty_id == cp.id)
            )).scalar_one_or_none()
    if company is None:
        # Создаём Company как зеркало Counterparty
        company = Company(
            name=r["name"],
            legal_name=r["name"],
            country=country_code.upper(),
            country_code=country_code,
            city=r["city"] or None,
            counterparty_id=cp.id,
        )
        session.add(company)
        await session.flush()
        companies[key] = company
    else:
        # Дозаполняем поля (не затираем ручные правки)
        if not company.country and country_code:
            company.country = country_code.upper()
        if not company.country_code and country_code:
            company.country_code = country_code
        if not company.city and r["city"]:
            company.city = r["city"]
        if not company.counterparty_id:
            company.counterparty_id = cp.id

    # CS-hotfix (0080): нормализуем lookup (.lower()), иначе "RU"/"KZ" не матчили.
    region = regions.get(REGION_BY_COUNTRY.get((r["country"] or "").lower(), ""))
    code = effective_code(r["status"])
    stage = stage_map.get(code) if code else None
    # CS-hotfix (0080): подписки без статуса в TSV раньше получали NULL-stage и
    # выпадали из total/operating дашборда. Дефолтим на B0 (старт внедрения).
    if stage is None:
        stage = stage_map.get("B0")

    # ── 3. Подписка: поиск по company_id+platform (источник истины) ──
    # Fallback на counterparty_id+platform (для старых записей без company_id)
    sub = None
    if company.id:
        sub = (await session.execute(
            select(ClientSubscription).where(
                ClientSubscription.company_id == company.id,
                ClientSubscription.platform_id == platform.id,
            )
        )).scalars().first()
    if sub is None:
        sub = (await session.execute(
            select(ClientSubscription).where(
                ClientSubscription.counterparty_id == cp.id,
                ClientSubscription.platform_id == platform.id,
            )
        )).scalars().first()

    region_id = region.id if region else None

    if sub is None:
        # CS-hotfix (0080): защита от коллизии uq_sub_company_platform_region —
        # у компании уже может быть подписка на эту платформу/регион (импорт без
        # platform-region уникальности раньше плодил кандидатов на дубль).
        if company.id is not None:
            clash = (await session.execute(
                select(ClientSubscription.id).where(
                    ClientSubscription.company_id == company.id,
                    ClientSubscription.platform_id == platform.id,
                    ClientSubscription.region_id == region_id,
                )
            )).first()
            if clash:
                res["skipped"].append(
                    f"{r['name']} [{r['platform']}]: подписка company={company.id} "
                    f"platform={platform.id} region={region_id} уже есть, skip"
                )
                return
        session.add(ClientSubscription(
            counterparty_id=cp.id,
            company_id=company.id,
            platform_id=platform.id,
            region_id=region_id,
            lifecycle_stage_id=stage.id if stage else None,
            stage_changed_at=datetime.now(UTC) if stage else None,
        ))
        res["sub_created"] += 1
    else:
        # Дозаполняем поля, не затираем ручной ввод.
        # CS-hotfix (0080): перед проставлением company_id проверяем, что у этой
        # компании НЕТ другой подписки (platform, region) — иначе IntegrityError
        # на uq_sub_company_platform_region. При коллизии — лог + skip (не плодим).
        if sub.company_id is None and company.id:
            clash = (await session.execute(
                select(ClientSubscription.id).where(
                    ClientSubscription.company_id == company.id,
                    ClientSubscription.platform_id == sub.platform_id,
                    ClientSubscription.region_id == sub.region_id,
                    ClientSubscription.id != sub.id,
                )
            )).first()
            if clash:
                res["skipped"].append(
                    f"{r['name']} [{r['platform']}]: company={company.id} уже имеет "
                    f"подписку (platform={sub.platform_id}, region={sub.region_id}); "
                    f"company_id не проставлен на sub={sub.id}"
                )
            else:
                sub.company_id = company.id
        if stage and sub.lifecycle_stage_id != stage.id:
            sub.lifecycle_stage_id = stage.id
            sub.stage_changed_at = datetime.now(UTC)
        # CS-hotfix (0080): дозаполнение region_id с проверкой коллизии (#8).
        if sub.region_id is None and region:
            clash = (await session.execute(
                select(ClientSubscription.id).where(
                    ClientSubscription.company_id == sub.company_id,
                    ClientSubscription.platform_id == sub.platform_id,
                    ClientSubscription.region_id == region.id,
                    ClientSubscription.id != sub.id,
                )
            )).first() if sub.company_id is not None else None
            if not clash:
                sub.region_id = region.id
        res["sub_updated"] += 1


if __name__ == "__main__":
    dry_run()
