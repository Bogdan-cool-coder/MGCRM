"""Эпик 14 — Departments + Visibility ACL: CRUD отделов + дерево + members.

Endpoints (все требуют cookie-auth):
  GET    /api/departments           — список (плоский или ?as_tree=true)
  GET    /api/departments/{id}       — детали + список прямых children
  POST   /api/departments  (admin)   — создать
  PATCH  /api/departments/{id} (admin) — обновить (name, parent_id, head_user_id,
                                                   sort_order, is_active)
  DELETE /api/departments/{id} (admin) — soft-delete (is_active=false), 409 если
                                          есть users в отделе
  GET    /api/departments/{id}/members — список users отдела (sort by full_name)

Plus user-department assignment (admin):
  PATCH /api/users/{user_id}/department — admin переводит юзера в другой отдел
                                          и/или назначает manager_id.

Также экспортируем `assignment_router` отдельно, чтобы main.py подключил его на
prefix /api (роутер users.py уже сидит на /users — мы не хотим конфликтовать с
ним, поэтому /users/{user_id}/department живёт в отдельном APIRouter с
явным префиксом).
"""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import Department, User

router = APIRouter(prefix="/departments", tags=["departments"])

# Отдельный роутер для PATCH /users/{user_id}/department — он живёт под
# /users/, но логически принадлежит эпику 14 (departments). Регистрируется
# в main.py отдельно.
assignment_router = APIRouter(prefix="/users", tags=["users", "departments"])


# ============ Pydantic schemas ============

class DepartmentBase(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    parent_id: int | None = None
    head_user_id: int | None = None
    sort_order: int = 0
    is_active: bool = True


class DepartmentCreate(DepartmentBase):
    pass


class DepartmentUpdate(BaseModel):
    """Все поля опциональны — PATCH-semantics."""
    name: str | None = Field(default=None, min_length=1, max_length=128)
    parent_id: int | None = None
    head_user_id: int | None = None
    sort_order: int | None = None
    is_active: bool | None = None


class DepartmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    parent_id: int | None
    head_user_id: int | None
    sort_order: int
    is_active: bool
    # member_count и children_count — вычисляются в list (single query),
    # nullable если не агрегировали
    member_count: int | None = None
    children_count: int | None = None


class DepartmentTree(BaseModel):
    """Recursive node для tree-view."""
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    parent_id: int | None
    head_user_id: int | None
    sort_order: int
    is_active: bool
    member_count: int = 0
    children: list[DepartmentTree] = Field(default_factory=list)


# Late binding для рекурсивной схемы.
DepartmentTree.model_rebuild()


class DepartmentMember(BaseModel):
    """Один user из членов отдела (для GET /{id}/members)."""
    model_config = ConfigDict(from_attributes=True)
    id: int
    full_name: str
    email: str
    role: str
    is_active: bool
    manager_id: int | None = None


class UserDepartmentAssignIn(BaseModel):
    """PATCH /api/users/{user_id}/department body."""
    department_id: int | None = None  # None = открепить
    manager_id: int | None = None     # None = очистить руководителя


# ============ Helpers ============

async def _get_department_or_404(session: AsyncSession, dept_id: int) -> Department:
    dept = (await session.execute(
        select(Department).where(Department.id == dept_id)
    )).scalar_one_or_none()
    if not dept:
        raise HTTPException(404, "Отдел не найден")
    return dept


async def _validate_no_cycle(
    session: AsyncSession,
    dept_id: int,
    new_parent_id: int | None,
) -> None:
    """Проверка, что назначение parent_id не создаёт цикл в дереве отделов.

    Простой случай: parent_id == dept_id → цикл. Глубже: новый предок
    среди потомков self → цикл.
    """
    if new_parent_id is None:
        return
    if new_parent_id == dept_id:
        raise HTTPException(400, "Отдел не может быть родителем самому себе")

    # Идём вверх от new_parent_id — если встречаем dept_id, это цикл.
    visited: set[int] = set()
    current_id: int | None = new_parent_id
    while current_id is not None:
        if current_id in visited:
            # Защита от существующего цикла в БД (теоретически невозможно, но
            # на всякий случай).
            raise HTTPException(500, "Обнаружен цикл в дереве отделов")
        visited.add(current_id)
        if current_id == dept_id:
            raise HTTPException(400, "Циклическое назначение parent_id")
        parent = (await session.execute(
            select(Department.parent_id).where(Department.id == current_id)
        )).scalar_one_or_none()
        current_id = parent


async def _count_members(session: AsyncSession, dept_id: int) -> int:
    return int((await session.execute(
        select(func.count()).select_from(User).where(User.department_id == dept_id)
    )).scalar() or 0)


# ============ Endpoints ============

@router.get("", response_model=list[DepartmentOut])
async def list_departments(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    include_inactive: bool = Query(default=False),
):
    """Плоский список отделов с agg-полями member_count / children_count.

    Для tree-view используется отдельный GET /api/departments/tree (см. ниже).
    """
    stmt = select(Department)
    if not include_inactive:
        stmt = stmt.where(Department.is_active.is_(True))
    stmt = stmt.order_by(Department.sort_order, Department.id)
    depts = (await session.execute(stmt)).scalars().all()

    # Подгружаем counts одним запросом (а не N+1).
    member_counts_rows = (await session.execute(
        select(User.department_id, func.count())
        .where(User.department_id.is_not(None))
        .group_by(User.department_id)
    )).all()
    member_counts: dict[int, int] = {int(d): int(c) for d, c in member_counts_rows}

    children_counts_rows = (await session.execute(
        select(Department.parent_id, func.count())
        .where(Department.parent_id.is_not(None))
        .group_by(Department.parent_id)
    )).all()
    children_counts: dict[int, int] = {int(p): int(c) for p, c in children_counts_rows}

    return [
        DepartmentOut(
            id=d.id,
            name=d.name,
            parent_id=d.parent_id,
            head_user_id=d.head_user_id,
            sort_order=d.sort_order,
            is_active=d.is_active,
            member_count=member_counts.get(d.id, 0),
            children_count=children_counts.get(d.id, 0),
        )
        for d in depts
    ]


@router.get("/tree", response_model=list[DepartmentTree])
async def list_departments_tree(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    include_inactive: bool = Query(default=False),
):
    """Иерархическое дерево отделов (роутер группирует по parent_id)."""
    stmt = select(Department)
    if not include_inactive:
        stmt = stmt.where(Department.is_active.is_(True))
    stmt = stmt.order_by(Department.sort_order, Department.id)
    depts = (await session.execute(stmt)).scalars().all()

    member_counts_rows = (await session.execute(
        select(User.department_id, func.count())
        .where(User.department_id.is_not(None))
        .group_by(User.department_id)
    )).all()
    member_counts: dict[int, int] = {int(d): int(c) for d, c in member_counts_rows}

    # Сборка деревьев: словарь node id → DepartmentTree
    nodes: dict[int, DepartmentTree] = {}
    for d in depts:
        nodes[d.id] = DepartmentTree(
            id=d.id,
            name=d.name,
            parent_id=d.parent_id,
            head_user_id=d.head_user_id,
            sort_order=d.sort_order,
            is_active=d.is_active,
            member_count=member_counts.get(d.id, 0),
            children=[],
        )

    roots: list[DepartmentTree] = []
    for d in depts:
        node = nodes[d.id]
        if d.parent_id and d.parent_id in nodes:
            nodes[d.parent_id].children.append(node)
        else:
            roots.append(node)
    return roots


@router.get("/{dept_id}", response_model=DepartmentOut)
async def get_department(
    dept_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    dept = await _get_department_or_404(session, dept_id)
    member_count = await _count_members(session, dept_id)
    children_count = int((await session.execute(
        select(func.count()).select_from(Department).where(Department.parent_id == dept_id)
    )).scalar() or 0)
    return DepartmentOut(
        id=dept.id,
        name=dept.name,
        parent_id=dept.parent_id,
        head_user_id=dept.head_user_id,
        sort_order=dept.sort_order,
        is_active=dept.is_active,
        member_count=member_count,
        children_count=children_count,
    )


@router.post("", response_model=DepartmentOut, status_code=status.HTTP_201_CREATED)
async def create_department(
    payload: DepartmentCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # Валидируем parent_id (если задан)
    if payload.parent_id is not None:
        await _get_department_or_404(session, payload.parent_id)
    if payload.head_user_id is not None:
        head = (await session.execute(
            select(User).where(User.id == payload.head_user_id)
        )).scalar_one_or_none()
        if not head:
            raise HTTPException(404, f"Пользователь head_user_id={payload.head_user_id} не найден")

    dept = Department(
        name=payload.name,
        parent_id=payload.parent_id,
        head_user_id=payload.head_user_id,
        sort_order=payload.sort_order,
        is_active=payload.is_active,
    )
    session.add(dept)
    await session.commit()
    await session.refresh(dept)
    return DepartmentOut(
        id=dept.id,
        name=dept.name,
        parent_id=dept.parent_id,
        head_user_id=dept.head_user_id,
        sort_order=dept.sort_order,
        is_active=dept.is_active,
        member_count=0,
        children_count=0,
    )


@router.patch("/{dept_id}", response_model=DepartmentOut)
async def update_department(
    dept_id: int,
    payload: DepartmentUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    dept = await _get_department_or_404(session, dept_id)
    patch = payload.model_dump(exclude_unset=True)

    if "parent_id" in patch:
        if patch["parent_id"] is not None:
            await _get_department_or_404(session, patch["parent_id"])
            await _validate_no_cycle(session, dept_id, patch["parent_id"])
    if "head_user_id" in patch and patch["head_user_id"] is not None:
        head = (await session.execute(
            select(User).where(User.id == patch["head_user_id"])
        )).scalar_one_or_none()
        if not head:
            raise HTTPException(404, f"Пользователь head_user_id={patch['head_user_id']} не найден")

    for k, v in patch.items():
        setattr(dept, k, v)
    await session.commit()
    await session.refresh(dept)

    member_count = await _count_members(session, dept_id)
    children_count = int((await session.execute(
        select(func.count()).select_from(Department).where(Department.parent_id == dept_id)
    )).scalar() or 0)
    return DepartmentOut(
        id=dept.id,
        name=dept.name,
        parent_id=dept.parent_id,
        head_user_id=dept.head_user_id,
        sort_order=dept.sort_order,
        is_active=dept.is_active,
        member_count=member_count,
        children_count=children_count,
    )


@router.delete("/{dept_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_department(
    dept_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Soft-delete (is_active=false). 409, если в отделе есть active users."""
    dept = await _get_department_or_404(session, dept_id)
    active_members = int((await session.execute(
        select(func.count())
        .select_from(User)
        .where(User.department_id == dept_id, User.is_active.is_(True))
    )).scalar() or 0)
    if active_members > 0:
        raise HTTPException(
            409,
            f"В отделе {active_members} активных сотрудник(ов). "
            "Сначала переведите их в другой отдел или деактивируйте.",
        )
    dept.is_active = False
    await session.commit()


@router.get("/{dept_id}/members", response_model=list[DepartmentMember])
async def list_department_members(
    dept_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сотрудники отдела (sort by full_name)."""
    await _get_department_or_404(session, dept_id)
    users = (await session.execute(
        select(User)
        .where(User.department_id == dept_id)
        .order_by(User.full_name)
    )).scalars().all()
    return [
        DepartmentMember(
            id=u.id,
            full_name=u.full_name,
            email=u.email,
            role=u.role.value if hasattr(u.role, "value") else str(u.role),
            is_active=u.is_active,
            manager_id=u.manager_id,
        )
        for u in users
    ]


def manager_assignment_creates_cycle(
    manager_of: dict[int, int | None], *, user_id: int, new_manager_id: int,
) -> bool:
    """Pure: назначение new_manager_id руководителем user_id создаёт цикл?

    Поднимаемся вверх по manager_id от new_manager_id; если встречаем user_id —
    значит user_id уже (транзитивно) руководит назначаемым, и получится цикл.
    `seen` защищает от зацикливания на уже-битых данных.
    """
    if new_manager_id == user_id:
        return True
    cur: int | None = new_manager_id
    seen: set[int] = set()
    while cur is not None and cur not in seen:
        if cur == user_id:
            return True
        seen.add(cur)
        cur = manager_of.get(cur)
    return False


# ============ assignment_router: PATCH /users/{user_id}/department ============

@assignment_router.patch("/{user_id}/department", response_model=DepartmentMember)
async def assign_user_department(
    user_id: int,
    payload: UserDepartmentAssignIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Перевести юзера в другой отдел и/или назначить руководителя.

    department_id=None → открепить от отдела.
    manager_id=None → очистить руководителя.

    Самоназначение менеджером запрещено (manager_id != user_id).
    """
    user = (await session.execute(
        select(User).where(User.id == user_id)
    )).scalar_one_or_none()
    if not user:
        raise HTTPException(404, "Пользователь не найден")

    patch = payload.model_dump(exclude_unset=True)

    if "department_id" in patch and patch["department_id"] is not None:
        # Проверим, что отдел существует
        dept = (await session.execute(
            select(Department).where(Department.id == patch["department_id"])
        )).scalar_one_or_none()
        if not dept:
            raise HTTPException(
                404, f"Отдел department_id={patch['department_id']} не найден",
            )
        if not dept.is_active:
            raise HTTPException(
                400, f"Отдел '{dept.name}' деактивирован — нельзя назначить",
            )

    if "manager_id" in patch and patch["manager_id"] is not None:
        if patch["manager_id"] == user_id:
            raise HTTPException(400, "Пользователь не может быть собственным руководителем")
        mgr = (await session.execute(
            select(User).where(User.id == patch["manager_id"])
        )).scalar_one_or_none()
        if not mgr:
            raise HTTPException(
                404, f"Руководитель manager_id={patch['manager_id']} не найден",
            )
        # A2 WARNING: нельзя подчинить уволенному/неактивному руководителю.
        if not mgr.is_active:
            raise HTTPException(
                400, "Нельзя назначить руководителем неактивного сотрудника",
            )
        # A2 WARNING: запрет цикла в оргграфе (A→B→A). Поднимаемся вверх по
        # manager_id от назначаемого руководителя — если встречаем user_id,
        # значит создаётся цикл (зависание subordinates_count / эскалаций).
        cur: int | None = mgr.id
        seen: set[int] = set()
        while cur is not None and cur not in seen:
            if cur == user_id:
                raise HTTPException(
                    400, "Циклическое назначение руководителя",
                )
            seen.add(cur)
            cur = (await session.execute(
                select(User.manager_id).where(User.id == cur)
            )).scalar_one_or_none()

    # Явное присваивание вместо setattr-loop (A2 WARNING): чтобы добавление
    # любого поля в схему не открыло тихий privilege-escalation через этот endpoint.
    if "department_id" in patch:
        user.department_id = patch["department_id"]
    if "manager_id" in patch:
        user.manager_id = patch["manager_id"]
    await session.commit()
    await session.refresh(user)

    return DepartmentMember(
        id=user.id,
        full_name=user.full_name,
        email=user.email,
        role=user.role.value if hasattr(user.role, "value") else str(user.role),
        is_active=user.is_active,
        manager_id=user.manager_id,
    )
