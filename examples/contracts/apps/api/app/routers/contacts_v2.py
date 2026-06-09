"""CONTACTS 2.0 Ф1 — холдинги, файлы/папки, теги, справочники.

Роутеры:
- /api/holdings                  — ClientGroup как холдинг (поиск/создание).
- /api/crm/folders, /api/crm/files — файловое хранилище карточек (локальный том).
- /api/crm/tags                  — автокомплит тегов.
- /api/admin/contact-positions   — справочник должностей.
- /api/admin/company-types       — справочник типов компаний.

M2M контакт↔компания, deals/contracts по сущности, unified-список — в роутерах
contacts.py / companies.py (расширены).
"""
from __future__ import annotations

import asyncio
import uuid
from pathlib import Path
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, UploadFile, status
from fastapi.responses import FileResponse
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import (
    ClientGroup,
    Company,
    CompanyType,
    Contact,
    ContactPosition,
    Deal,
    File as CrmFile,
    Folder,
    User,
)
from app.routers.contacts_v2_schemas import (
    CompanyTypeCreate,
    CompanyTypeOut,
    CompanyTypeUpdate,
    ContactPositionCreate,
    ContactPositionOut,
    ContactPositionUpdate,
    FileOut,
    FolderCreate,
    FolderOut,
    FolderUpdate,
)
from app.services.contacts_v2 import (
    DEFAULT_FOLDERS,
    is_path_within,
    sanitize_filename,
    validate_entity_type,
    validate_file_size,
)


# ============ Холдинги (ClientGroup) ============

holdings_router = APIRouter(prefix="/holdings", tags=["holdings"])


class HoldingOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    company_count: int = 0


class HoldingCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)


@holdings_router.get("", response_model=list[HoldingOut])
async def list_holdings(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    limit: int = 100,
):
    """Список холдингов (ClientGroup) с числом компаний. Поиск по name."""
    stmt = select(ClientGroup).order_by(ClientGroup.name)
    if q:
        stmt = stmt.where(ClientGroup.name.ilike(f"%{q}%"))
    stmt = stmt.limit(max(1, min(500, limit)))
    groups = (await session.execute(stmt)).scalars().all()
    # company_count одним запросом
    counts = dict((await session.execute(
        select(Company.group_id, func.count())
        .where(Company.group_id.is_not(None))
        .group_by(Company.group_id)
    )).all())
    return [
        HoldingOut(id=g.id, name=g.name, company_count=int(counts.get(g.id, 0)))
        for g in groups
    ]


@holdings_router.post("", response_model=HoldingOut, status_code=status.HTTP_201_CREATED)
async def create_holding(
    data: HoldingCreate,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать холдинг (ClientGroup)."""
    group = ClientGroup(name=data.name)
    session.add(group)
    await session.commit()
    await session.refresh(group)
    return HoldingOut(id=group.id, name=group.name, company_count=0)


# ============ Файлы и папки ============

crm_router = APIRouter(prefix="/crm", tags=["crm-files"])


def _files_root() -> Path:
    """Корневая директория CRM-файлов на локальном томе (/data/storage/crm_files)."""
    root = get_settings().storage_dir / "crm_files"
    root.mkdir(parents=True, exist_ok=True)
    return root


async def _require_entity(session: AsyncSession, entity_type: str, entity_id: int) -> None:
    """Проверить, что сущность contact/company существует."""
    try:
        validate_entity_type(entity_type)
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None
    model: type[Contact] | type[Company] | type[Deal]
    if entity_type == "contact":
        model = Contact
    elif entity_type == "deal":
        model = Deal
    else:
        model = Company
    exists = (await session.execute(
        select(model.id).where(model.id == entity_id)
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(404, f"{entity_type} {entity_id} не найден")


async def _require_owner_access(
    session: AsyncSession, owner_entity_type: str, owner_entity_id: int, user: User,
) -> None:
    """Проверить, что пользователь видит owner-сущность файла/папки (visibility ACL).

    Резолвит owner (contact/company/deal) и применяет тот же object-level scope-
    чек, что и item-эндпоинты карточек (ensure_object_visible → 404 вне scope).

    IDOR-fix: раньше ensure_object_visible звался ТОЛЬКО для company; contact и
    deal проверялись лишь на существование → файлы/папки чужих контактов и сделок
    были доступны вне scope (чтение чужих документов, write-подброс). Теперь все
    три типа проходят object-level scope единообразно с contacts.py / deals-
    роутером (entity_type='contact' использует owner_id, 'deal' — owner_user_id;
    admin/director со scope='all' проходят всегда).
    """
    from app.services.access_control import ensure_object_visible

    if owner_entity_type == "company":
        company = (await session.execute(
            select(Company).where(Company.id == owner_entity_id)
        )).scalar_one_or_none()
        if not company:
            raise HTTPException(404, "Компания не найдена")
        await ensure_object_visible(session, company, "company", user)
    elif owner_entity_type == "contact":
        contact = (await session.execute(
            select(Contact).where(Contact.id == owner_entity_id)
        )).scalar_one_or_none()
        if not contact:
            raise HTTPException(404, "Контакт не найден")
        await ensure_object_visible(session, contact, "contact", user)
    elif owner_entity_type == "deal":
        # Wave 4: файлы на сделке. Object-level scope сделки (owner_user_id) —
        # тот же, что в deals-роутере; здесь применяем его явно (не только existence).
        deal = (await session.execute(
            select(Deal).where(Deal.id == owner_entity_id)
        )).scalar_one_or_none()
        if not deal:
            raise HTTPException(404, "Сделка не найдена")
        await ensure_object_visible(session, deal, "deal", user)


async def _ensure_default_folders(
    session: AsyncSession, entity_type: str, entity_id: int,
) -> None:
    """Идемпотентно создать дефолтные папки сущности (Системная / Документы)."""
    existing = set((await session.execute(
        select(Folder.name).where(
            Folder.owner_entity_type == entity_type,
            Folder.owner_entity_id == entity_id,
        )
    )).scalars().all())
    created = False
    for name, is_system in DEFAULT_FOLDERS:
        if name not in existing:
            session.add(Folder(
                owner_entity_type=entity_type, owner_entity_id=entity_id,
                name=name, is_system=is_system,
            ))
            created = True
    if created:
        await session.commit()


@crm_router.get("/folders", response_model=list[FolderOut])
async def list_folders(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity_type: str,
    entity_id: int,
):
    """Папки сущности. При первом обращении авто-создаёт дефолтные (идемпотентно)."""
    await _require_entity(session, entity_type, entity_id)
    # P0 security (Unit 3b): не только existence, но и visibility таргет-сущности.
    await _require_owner_access(session, entity_type, entity_id, current_user)
    await _ensure_default_folders(session, entity_type, entity_id)
    return (await session.execute(
        select(Folder)
        .where(
            Folder.owner_entity_type == entity_type,
            Folder.owner_entity_id == entity_id,
        )
        .order_by(Folder.is_system.desc(), Folder.id)
    )).scalars().all()


@crm_router.post("/folders", response_model=FolderOut, status_code=status.HTTP_201_CREATED)
async def create_folder(
    data: FolderCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать пользовательскую папку (is_system=False)."""
    await _require_entity(session, data.owner_entity_type, data.owner_entity_id)
    # P0 security (Unit 3b): нельзя плодить папки в чужой невидимой сущности.
    await _require_owner_access(
        session, data.owner_entity_type, data.owner_entity_id, current_user
    )
    folder = Folder(
        owner_entity_type=data.owner_entity_type,
        owner_entity_id=data.owner_entity_id,
        name=data.name,
        is_system=False,
    )
    session.add(folder)
    await session.commit()
    await session.refresh(folder)
    return folder


async def _get_folder_or_404(session: AsyncSession, folder_id: int) -> Folder:
    folder = (await session.execute(
        select(Folder).where(Folder.id == folder_id)
    )).scalar_one_or_none()
    if not folder:
        raise HTTPException(404, "Папка не найдена")
    return folder


@crm_router.patch("/folders/{folder_id}", response_model=FolderOut)
async def rename_folder(
    folder_id: int,
    data: FolderUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Переименовать папку. Системные (is_system) переименовывать нельзя."""
    folder = await _get_folder_or_404(session, folder_id)
    # P0 security (Unit 3b): нельзя править папку чужой невидимой сущности.
    await _require_owner_access(
        session, folder.owner_entity_type, folder.owner_entity_id, current_user
    )
    if folder.is_system:
        raise HTTPException(400, "Системную папку нельзя переименовать")
    if data.name is not None:
        folder.name = data.name
    await session.commit()
    await session.refresh(folder)
    return folder


@crm_router.delete("/folders/{folder_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_folder(
    folder_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить папку. Системную — нельзя. Файлы внутри удаляются каскадно
    (FK ondelete=CASCADE) + физические файлы с диска."""
    folder = await _get_folder_or_404(session, folder_id)
    await _require_owner_access(
        session, folder.owner_entity_type, folder.owner_entity_id, current_user
    )
    if folder.is_system:
        raise HTTPException(400, "Системную папку нельзя удалить")
    # Удалить физические файлы с диска (записи уйдут по CASCADE).
    files = (await session.execute(
        select(CrmFile).where(CrmFile.folder_id == folder_id)
    )).scalars().all()
    for f in files:
        await asyncio.to_thread(_safe_unlink, f.file_path)
    await session.delete(folder)
    await session.commit()


def _safe_unlink(path: str) -> None:
    """Удалить файл с диска, только если он внутри files_root (защита traversal)."""
    root = str(_files_root())
    if is_path_within(root, path):
        try:
            Path(path).unlink(missing_ok=True)
        except OSError:
            pass


@crm_router.get("/files", response_model=list[FileOut])
async def list_files(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity_type: str,
    entity_id: int,
    folder_id: int | None = None,
):
    """Файлы сущности (опц. фильтр по папке)."""
    await _require_entity(session, entity_type, entity_id)
    # P0 security (Unit 3b): visibility таргет-сущности, не только existence.
    await _require_owner_access(session, entity_type, entity_id, current_user)
    stmt = select(CrmFile).where(
        CrmFile.owner_entity_type == entity_type,
        CrmFile.owner_entity_id == entity_id,
    )
    if folder_id is not None:
        stmt = stmt.where(CrmFile.folder_id == folder_id)
    stmt = stmt.order_by(CrmFile.created_at.desc())
    return (await session.execute(stmt)).scalars().all()


@crm_router.post("/files", response_model=FileOut, status_code=status.HTTP_201_CREATED)
async def upload_file(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity_type: str = Form(...),
    entity_id: int = Form(...),
    folder_id: int = Form(...),
    file: UploadFile = File(...),
):
    """Загрузить файл в папку карточки (multipart). Лимит 20 МБ.

    Возвращает 413, если файл больше лимита.
    """
    await _require_entity(session, entity_type, entity_id)
    # P0 security (Unit 3b): write-IDOR. Раньше можно было подбросить файл в
    # невидимую чужую компанию/контакт. Теперь — visibility таргета обязательна.
    await _require_owner_access(session, entity_type, entity_id, current_user)
    folder = await _get_folder_or_404(session, folder_id)
    if folder.owner_entity_type != entity_type or folder.owner_entity_id != entity_id:
        raise HTTPException(400, "Папка не принадлежит этой сущности")

    data = await file.read()
    try:
        validate_file_size(len(data))
    except ValueError as ex:
        raise HTTPException(
            status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, str(ex),
        ) from None

    safe_name = sanitize_filename(file.filename)
    ext = Path(safe_name).suffix
    stored_name = f"{uuid.uuid4().hex}{ext}"
    target_dir = _files_root() / entity_type / str(entity_id)
    target_dir.mkdir(parents=True, exist_ok=True)
    out_path = target_dir / stored_name
    # Защита от traversal: путь обязан лежать внутри files_root.
    if not is_path_within(str(_files_root()), str(out_path)):
        raise HTTPException(400, "Недопустимый путь файла")
    await asyncio.to_thread(out_path.write_bytes, data)

    rec = CrmFile(
        folder_id=folder_id,
        owner_entity_type=entity_type,
        owner_entity_id=entity_id,
        file_path=str(out_path),
        original_name=safe_name,
        file_size=len(data),
        mime_type=file.content_type,
        uploaded_by_user_id=current_user.id,
    )
    session.add(rec)
    await session.commit()
    await session.refresh(rec)
    return rec


async def _get_file_or_404(session: AsyncSession, file_id: int) -> CrmFile:
    rec = (await session.execute(
        select(CrmFile).where(CrmFile.id == file_id)
    )).scalar_one_or_none()
    if not rec:
        raise HTTPException(404, "Файл не найден")
    return rec


# MIME-типы, безопасные для inline-отдачи. Всё остальное (в т.ч. text/html,
# image/svg+xml, application/xhtml+xml) — форсим octet-stream, чтобы клиент-
# контролируемый mime_type не стал XSS-вектором при открытии в браузере.
_SAFE_INLINE_MIME: frozenset[str] = frozenset({
    "application/pdf",
    "image/png", "image/jpeg", "image/gif", "image/webp",
    "text/plain",
})


@crm_router.get("/files/{file_id}/download")
async def download_file(
    file_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отдать файл (FileResponse) с оригинальным именем.

    Безопасность: Content-Disposition=attachment всегда (файл скачивается, не
    рендерится), а для не-whitelist mime_type форсим application/octet-stream —
    mime_type клиент-контролируемый при загрузке (XSS-вектор иначе).
    """
    rec = await _get_file_or_404(session, file_id)
    await _require_owner_access(
        session, rec.owner_entity_type, rec.owner_entity_id, current_user
    )
    if not is_path_within(str(_files_root()), rec.file_path) or not Path(rec.file_path).exists():
        raise HTTPException(404, "Файл отсутствует на диске")
    mime = rec.mime_type if rec.mime_type in _SAFE_INLINE_MIME else "application/octet-stream"
    return FileResponse(
        rec.file_path,
        filename=rec.original_name,
        media_type=mime,
        content_disposition_type="attachment",
    )


@crm_router.delete("/files/{file_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_file(
    file_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить запись о файле + физический файл с диска."""
    rec = await _get_file_or_404(session, file_id)
    await _require_owner_access(
        session, rec.owner_entity_type, rec.owner_entity_id, current_user
    )
    await asyncio.to_thread(_safe_unlink, rec.file_path)
    await session.delete(rec)
    await session.commit()


# ============ Теги (автокомплит) ============

class TagsOut(BaseModel):
    tags: list[str] = Field(default_factory=list)


@crm_router.get("/tags", response_model=TagsOut)
async def list_tags(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    entity_type: str | None = Query(default=None, description="contact | company | None (оба)"),
    limit: int = 50,
):
    """Distinct-теги из crm_contacts.tags / crm_companies.tags для автокомплита.

    entity_type фильтрует источник; None — оба. q — substring-фильтр (lower).
    """
    tags: set[str] = set()
    want_contact = entity_type in (None, "contact")
    want_company = entity_type in (None, "company")
    if want_contact:
        for arr in (await session.execute(select(Contact.tags))).scalars().all():
            tags.update(arr or [])
    if want_company:
        for arr in (await session.execute(select(Company.tags))).scalars().all():
            tags.update(arr or [])
    result = sorted(tags)
    if q:
        ql = q.lower()
        result = [t for t in result if ql in t.lower()]
    return TagsOut(tags=result[: max(1, min(500, limit))])


# ============ Справочники (admin) ============

positions_router = APIRouter(prefix="/admin/contact-positions", tags=["admin-dictionaries"])
company_types_router = APIRouter(prefix="/admin/company-types", tags=["admin-dictionaries"])


@positions_router.get("", response_model=list[ContactPositionOut])
async def list_positions(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    only_active: bool = False,
):
    """Список должностей (для автокомплита). q — поиск по name."""
    stmt = select(ContactPosition).order_by(ContactPosition.sort_order, ContactPosition.name)
    if only_active:
        stmt = stmt.where(ContactPosition.is_active.is_(True))
    if q:
        stmt = stmt.where(ContactPosition.name.ilike(f"%{q}%"))
    return (await session.execute(stmt)).scalars().all()


@positions_router.post("", response_model=ContactPositionOut, status_code=status.HTTP_201_CREATED)
async def create_position(
    data: ContactPositionCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    pos = ContactPosition(**data.model_dump())
    session.add(pos)
    await session.commit()
    await session.refresh(pos)
    return pos


@positions_router.patch("/{position_id}", response_model=ContactPositionOut)
async def update_position(
    position_id: int,
    data: ContactPositionUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    pos = (await session.execute(
        select(ContactPosition).where(ContactPosition.id == position_id)
    )).scalar_one_or_none()
    if not pos:
        raise HTTPException(404, "Должность не найдена")
    for k, v in data.model_dump(exclude_unset=True).items():
        setattr(pos, k, v)
    await session.commit()
    await session.refresh(pos)
    return pos


@positions_router.delete("/{position_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_position(
    position_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    pos = (await session.execute(
        select(ContactPosition).where(ContactPosition.id == position_id)
    )).scalar_one_or_none()
    if not pos:
        raise HTTPException(404, "Должность не найдена")
    await session.delete(pos)
    await session.commit()


@company_types_router.get("", response_model=list[CompanyTypeOut])
async def list_company_types(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    only_active: bool = False,
):
    """Список типов компаний. q — поиск по name."""
    stmt = select(CompanyType).order_by(CompanyType.sort_order, CompanyType.name)
    if only_active:
        stmt = stmt.where(CompanyType.is_active.is_(True))
    if q:
        stmt = stmt.where(CompanyType.name.ilike(f"%{q}%"))
    return (await session.execute(stmt)).scalars().all()


@company_types_router.post("", response_model=CompanyTypeOut, status_code=status.HTTP_201_CREATED)
async def create_company_type(
    data: CompanyTypeCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    ct = CompanyType(**data.model_dump())
    session.add(ct)
    await session.commit()
    await session.refresh(ct)
    return ct


@company_types_router.patch("/{type_id}", response_model=CompanyTypeOut)
async def update_company_type(
    type_id: int,
    data: CompanyTypeUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    ct = (await session.execute(
        select(CompanyType).where(CompanyType.id == type_id)
    )).scalar_one_or_none()
    if not ct:
        raise HTTPException(404, "Тип компании не найден")
    for k, v in data.model_dump(exclude_unset=True).items():
        setattr(ct, k, v)
    await session.commit()
    await session.refresh(ct)
    return ct


@company_types_router.delete("/{type_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_company_type(
    type_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    ct = (await session.execute(
        select(CompanyType).where(CompanyType.id == type_id)
    )).scalar_one_or_none()
    if not ct:
        raise HTTPException(404, "Тип компании не найден")
    await session.delete(ct)
    await session.commit()
