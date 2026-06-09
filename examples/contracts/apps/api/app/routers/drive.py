"""Эндпоинт выгрузки договора в Google Drive."""

import logging
from pathlib import Path
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request
from googleapiclient.errors import HttpError
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import get_session
from app.deps import CurrentUser, load_contract, require_owner_or_role
from app.models import AuditLog, Contract, ContractStatus, UserRole
from app.services.drive import upload_file

# Эпик 0 (RBAC централизация): единый dep вместо inline `if user.role == manager
# and c.author_user_id != user.id`. Lawyer допущен наряду с admin/director.
_RequireContractOwner = Depends(
    require_owner_or_role(
        load_contract,
        owner_field="author_user_id",
        elevated=(UserRole.admin, UserRole.director, UserRole.lawyer),
    )
)

logger = logging.getLogger(__name__)


def _humanize_drive_error(e: Exception) -> str:
    """Превращает ошибку Google в понятное пользователю сообщение."""
    text = str(e)
    if isinstance(e, HttpError):
        status_code = getattr(e, "status_code", None) or (e.resp.status if e.resp else None)
        if "accessNotConfigured" in text or "has not been used in project" in text:
            return ("Google Drive API не включён в проекте. Откройте Google Cloud Console → "
                    "APIs & Services → Library → Google Drive API → Enable, подождите 1-2 минуты и повторите.")
        if status_code == 403 or "insufficientPermissions" in text or "forbidden" in text.lower():
            return ("Нет доступа к папке. Убедитесь что подключённый Google-аккаунт имеет права на эту папку "
                    "(или используйте Общий диск).")
        if status_code == 404 or "notFound" in text:
            return "Папка не найдена. Проверьте ссылку на папку Google Drive."
        if "storageQuotaExceeded" in text:
            return "Превышена квота хранилища Google-аккаунта. Используйте Общий диск (Shared Drive)."
    if "не подключён" in text or "not connected" in text.lower():
        return "Google Drive не подключён. Зайдите в Интеграции и нажмите «Подключить Google»."
    return f"Ошибка выгрузки в Google Drive: {text[:200]}"

router = APIRouter(prefix="/contracts", tags=["drive"])


class DriveUploadIn(BaseModel):
    folder_url: str


@router.post("/{contract_id}/drive-upload")
async def upload_to_drive(
    contract_id: int,
    payload: DriveUploadIn,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if c.status not in (ContractStatus.approved, ContractStatus.uploaded):
        raise HTTPException(400, "Договор не согласован")
    if not c.docx_path or not Path(c.docx_path).exists():
        raise HTTPException(400, "Документ не сгенерирован")

    try:
        docx_res = upload_file(
            Path(c.docx_path), payload.folder_url,
            filename=f"Договор {c.number}.docx",
            mime_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        )
        pdf_res = None
        if c.pdf_path and Path(c.pdf_path).exists():
            pdf_res = upload_file(
                Path(c.pdf_path), payload.folder_url,
                filename=f"Договор {c.number}.pdf",
                mime_type="application/pdf",
            )
    except (HttpError, RuntimeError, ValueError) as e:
        logger.warning("Drive upload failed for contract %s: %s", contract_id, e)
        raise HTTPException(status_code=422, detail=_humanize_drive_error(e)) from e

    c.drive_folder_url = payload.folder_url
    c.drive_docx_url = docx_res["webViewLink"]
    if pdf_res:
        c.drive_pdf_url = pdf_res["webViewLink"]
    c.status = ContractStatus.uploaded

    session.add(AuditLog(
        user_id=current_user.id, contract_id=c.id, action="upload_drive",
        payload={"docx": docx_res, "pdf": pdf_res},
        ip=request.client.host if request.client else None,
    ))
    await session.commit()
    return {
        "drive_docx_url": c.drive_docx_url,
        "drive_pdf_url": c.drive_pdf_url,
    }
