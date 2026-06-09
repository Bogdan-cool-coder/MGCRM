"""CONTACTS 2.0 Ф0 — Pydantic-схемы для новых сущностей.

Только СХЕМЫ (Out/Create/Update). Эндпоинты — следующая фаза (Ф1).
Сущности: ContactCompanyLink (M2M), CompanyType, ContactPosition, Folder, File.
"""
from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, ConfigDict, Field


# ============ CompanyType (справочник типов компаний) ============

class CompanyTypeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    description: str | None
    sort_order: int
    is_active: bool
    created_at: datetime


class CompanyTypeCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    description: str | None = None
    sort_order: int = 0
    is_active: bool = True


class CompanyTypeUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    description: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None


# ============ ContactPosition (справочник должностей) ============

class ContactPositionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    sort_order: int
    is_active: bool
    created_at: datetime


class ContactPositionCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    sort_order: int = 0
    is_active: bool = True


class ContactPositionUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    sort_order: int | None = None
    is_active: bool | None = None


# ============ ContactCompanyLink (M2M контакт↔компания) ============

class ContactCompanyLinkOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    contact_id: int
    company_id: int
    position: str | None
    position_id: int | None
    employment_status: str
    is_primary: bool
    created_at: datetime
    updated_at: datetime


class ContactCompanyLinkCreate(BaseModel):
    contact_id: int
    company_id: int
    position: str | None = Field(default=None, max_length=128)
    position_id: int | None = None
    employment_status: str = Field(default="works", max_length=16)
    is_primary: bool = False


class ContactCompanyLinkUpdate(BaseModel):
    position: str | None = Field(default=None, max_length=128)
    position_id: int | None = None
    employment_status: str | None = Field(default=None, max_length=16)
    is_primary: bool | None = None


# ============ Folder (папки файлов) ============

class FolderOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    owner_entity_type: str
    owner_entity_id: int
    name: str
    is_system: bool
    created_at: datetime


class FolderCreate(BaseModel):
    owner_entity_type: str = Field(max_length=16)  # contact / company
    owner_entity_id: int
    name: str = Field(min_length=1, max_length=255)


class FolderUpdate(BaseModel):
    # Переименование. is_system-папки нельзя переименовывать (валидация в эндпоинте Ф1).
    name: str | None = Field(default=None, min_length=1, max_length=255)


# ============ File ============

class FileOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    folder_id: int
    owner_entity_type: str
    owner_entity_id: int
    original_name: str
    file_size: int
    mime_type: str | None
    uploaded_by_user_id: int | None
    created_at: datetime


class FileCreate(BaseModel):
    # Метаданные файла; собственно байты загружаются через UploadFile в эндпоинте Ф1.
    folder_id: int
    owner_entity_type: str = Field(max_length=16)
    owner_entity_id: int
    original_name: str = Field(min_length=1, max_length=255)
