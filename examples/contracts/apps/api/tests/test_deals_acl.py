"""ACL видимости этапов воронки + аналитика (чистая логика, без БД)."""

from datetime import UTC, datetime, timedelta

from app.models import PipelineStage, User, UserRole
from app.services.deals import avg_days_in_stage, stage_visible_to


def _stage(dept=None, users=None):
    return PipelineStage(visible_department_ids=dept or [], visible_user_ids=users or [])


def _user(role=UserRole.manager, uid=1, dept=None):
    return User(id=uid, role=role, department_id=dept)


def test_admin_and_director_see_restricted_stage():
    s = _stage(dept=[99])
    assert stage_visible_to(s, _user(UserRole.admin))
    assert stage_visible_to(s, _user(UserRole.director))


def test_empty_acl_visible_to_everyone():
    assert stage_visible_to(_stage(), _user(UserRole.manager, dept=5))


def test_visible_by_department():
    s = _stage(dept=[5])
    assert stage_visible_to(s, _user(UserRole.manager, uid=1, dept=5))
    assert not stage_visible_to(s, _user(UserRole.manager, uid=1, dept=6))


def test_visible_by_user():
    s = _stage(users=[7])
    assert stage_visible_to(s, _user(UserRole.manager, uid=7))
    assert not stage_visible_to(s, _user(UserRole.manager, uid=8))


def test_restricted_hidden_from_other_manager():
    s = _stage(dept=[5], users=[7])
    assert not stage_visible_to(s, _user(UserRole.manager, uid=8, dept=6))


def test_avg_days_in_stage():
    now = datetime(2026, 1, 11, tzinfo=UTC)
    assert avg_days_in_stage([now - timedelta(days=2), now - timedelta(days=4)], now) == 3.0
    assert avg_days_in_stage([], now) == 0.0
    assert avg_days_in_stage([None], now) == 0.0
