"""Эпик 13: курсы — content_blocks validation + percent calculation.

Pure-функции (тестируются без БД):
- validate_content_blocks(blocks) — проверяет kind whitelist + required поля per kind
- compute_course_percent(progress, course) — % required lessons completed

Whitelists:
- CONTENT_BLOCK_KINDS — допустимые kind в content_blocks
- VIDEO_URL_WHITELIST — домены для embed-видео (защита от XSS через iframe)
- LESSON_KINDS — допустимые kind у CourseLesson
- COURSE_COMPLETION_POLICIES — допустимые completion_policy
- USER_CRM_EXPERIENCE_LEVELS — допустимые crm_experience_level в users

Замечание про CHECK на min-questions:
Если хотим жёстко требовать ≥5 вопросов в quiz-уроке — это backend-validation
(NOT DB CHECK с подзапросом — антипаттерн). Реализовано на этапе publish курса
(см. publish_course в роутере). На уровне create вопросов разрешаем меньше.
"""
from __future__ import annotations

from typing import Any
from urllib.parse import urlparse

# ============ Whitelists ============

# Допустимые виды блоков контента в CourseLesson.content_blocks.
CONTENT_BLOCK_KINDS: frozenset[str] = frozenset({
    "markdown",
    "image",
    "drive_video",
    "loom_video",
    "youtube_video",
    "callout",
})

# Допустимые домены для embed-видео. Защита от XSS — не пускаем произвольный iframe.
# Subdomain (www.) учитывается, для каждого host добавляем оба варианта.
VIDEO_URL_WHITELIST: frozenset[str] = frozenset({
    "drive.google.com",
    "www.loom.com",
    "loom.com",
    "www.youtube.com",
    "youtube.com",
    "youtu.be",
    "player.vimeo.com",
    "vimeo.com",
})

# Допустимые kind у CourseLesson.
LESSON_KINDS: frozenset[str] = frozenset({"theory", "video", "quiz"})

# Допустимые completion_policy у Course.
COURSE_COMPLETION_POLICIES: frozenset[str] = frozenset({
    "informational",
    "soft_gate",
})

# Допустимые crm_experience_level у User.
USER_CRM_EXPERIENCE_LEVELS: frozenset[str] = frozenset({
    "none",
    "basic",
    "advanced",
})

# Допустимые kind у LessonQuizQuestion.
QUESTION_KINDS: frozenset[str] = frozenset({"single", "multi"})

# Допустимые style у блока 'callout'.
CALLOUT_STYLES: frozenset[str] = frozenset({"info", "warning", "success", "danger"})

# Допустимые схемы для image-url (запрет javascript:/data:/vbscript: → stored XSS).
IMAGE_URL_SCHEMES: frozenset[str] = frozenset({"http", "https"})

# Лимиты длины полей контента (DoS / JSONB-bloat guard).
MAX_TEXT_LEN = 50_000        # markdown / callout text
MAX_URL_LEN = 2_048          # image url
MAX_CAPTION_LEN = 1_000      # image caption

# Допустимые статусы CourseProgress.
PROGRESS_STATUSES: frozenset[str] = frozenset({
    "not_started",
    "in_progress",
    "completed",
    "overdue",
})


# ============ Content block validation ============

def _check_video_url_host(url: str, field: str) -> None:
    """Проверка что host URL входит в VIDEO_URL_WHITELIST (защита от XSS iframe)."""
    if not url or not isinstance(url, str):
        raise ValueError(f"Поле '{field}' пусто или не строка")
    try:
        parsed = urlparse(url)
    except (ValueError, TypeError) as e:
        raise ValueError(f"Поле '{field}' — некорректный URL: {e}") from e
    if parsed.scheme not in ("http", "https"):
        raise ValueError(f"Поле '{field}' — допустимы только http/https, не {parsed.scheme!r}")
    host = (parsed.hostname or "").lower()
    if host not in VIDEO_URL_WHITELIST:
        raise ValueError(
            f"Поле '{field}' — host {host!r} не в whitelist. "
            f"Допустимы: {sorted(VIDEO_URL_WHITELIST)}"
        )


def _check_image_url(url: str, field: str) -> None:
    """URL картинки: только http/https, без javascript:/data: (stored-XSS guard), с лимитом длины."""
    if not isinstance(url, str) or not url.strip():
        raise ValueError(f"Поле '{field}' пусто или не строка")
    if len(url) > MAX_URL_LEN:
        raise ValueError(f"Поле '{field}' — длина URL превышает {MAX_URL_LEN}")
    try:
        parsed = urlparse(url.strip())
    except (ValueError, TypeError) as e:
        raise ValueError(f"Поле '{field}' — некорректный URL: {e}") from e
    if parsed.scheme.lower() not in IMAGE_URL_SCHEMES:
        raise ValueError(
            f"Поле '{field}' — допустимы только http/https-картинки, не {parsed.scheme!r}"
        )
    if not (parsed.hostname or "").strip():
        raise ValueError(f"Поле '{field}' — URL без хоста недопустим")


def validate_content_blocks(blocks: Any) -> None:
    """Валидация content_blocks (raise ValueError на bad shape).

    Проверяет:
    - blocks является list
    - каждый блок — dict с обязательным kind ∈ CONTENT_BLOCK_KINDS
    - per-kind обязательные поля:
        markdown: text (str, non-empty)
        image: url (str), caption (str, optional)
        drive_video: drive_url (str, whitelist host)
        loom_video: loom_url (str, whitelist host)
        youtube_video: youtube_id (str, alphanumeric+_-) — собирается из ID, не URL
        callout: style ∈ CALLOUT_STYLES, text (str, non-empty)

    Caller (роутер) ловит ValueError и преобразует в HTTPException 400.
    """
    if not isinstance(blocks, list):
        raise ValueError("content_blocks должно быть списком")
    for idx, block in enumerate(blocks):
        if not isinstance(block, dict):
            raise ValueError(f"Блок #{idx} — не dict")
        kind = block.get("kind")
        if kind not in CONTENT_BLOCK_KINDS:
            raise ValueError(
                f"Блок #{idx} — недопустимый kind {kind!r}. "
                f"Допустимы: {sorted(CONTENT_BLOCK_KINDS)}"
            )
        if kind == "markdown":
            text = block.get("text")
            if not isinstance(text, str) or not text.strip():
                raise ValueError(f"Блок #{idx} (markdown): нужен непустой 'text'")
            if len(text) > MAX_TEXT_LEN:
                raise ValueError(
                    f"Блок #{idx} (markdown): 'text' длиннее {MAX_TEXT_LEN} символов"
                )
        elif kind == "image":
            # http/https only + длина (запрет javascript:/data: → stored XSS).
            _check_image_url(block.get("url"), f"блок #{idx} url")
            # caption опционален; если есть — должен быть строкой в пределах лимита
            caption = block.get("caption")
            if caption is not None:
                if not isinstance(caption, str):
                    raise ValueError(f"Блок #{idx} (image): 'caption' должен быть строкой")
                if len(caption) > MAX_CAPTION_LEN:
                    raise ValueError(
                        f"Блок #{idx} (image): 'caption' длиннее {MAX_CAPTION_LEN}"
                    )
        elif kind == "drive_video":
            _check_video_url_host(block.get("drive_url"), f"блок #{idx} drive_url")
        elif kind == "loom_video":
            _check_video_url_host(block.get("loom_url"), f"блок #{idx} loom_url")
        elif kind == "youtube_video":
            yt_id = block.get("youtube_id")
            if not isinstance(yt_id, str) or not yt_id.strip():
                raise ValueError(
                    f"Блок #{idx} (youtube_video): нужен непустой 'youtube_id'"
                )
            # Базовая проверка: только [A-Za-z0-9_-]. Длина 5-20 (типичный youtube_id = 11).
            import re
            if not re.fullmatch(r"[A-Za-z0-9_-]{5,20}", yt_id):
                raise ValueError(
                    f"Блок #{idx} (youtube_video): 'youtube_id' содержит "
                    f"недопустимые символы или некорректную длину"
                )
        elif kind == "callout":
            style = block.get("style")
            if style not in CALLOUT_STYLES:
                raise ValueError(
                    f"Блок #{idx} (callout): style {style!r} не в "
                    f"{sorted(CALLOUT_STYLES)}"
                )
            text = block.get("text")
            if not isinstance(text, str) or not text.strip():
                raise ValueError(f"Блок #{idx} (callout): нужен непустой 'text'")
            if len(text) > MAX_TEXT_LEN:
                raise ValueError(
                    f"Блок #{idx} (callout): 'text' длиннее {MAX_TEXT_LEN} символов"
                )


# ============ Course percent calculation ============

def compute_course_percent(
    lesson_states: dict[str, Any] | None,
    required_lesson_ids: list[int],
) -> int:
    """Pure-function: % required lessons completed (0..100).

    Если required_lesson_ids пуст — возвращает 0 (нечего считать).
    Lesson считается completed, если в lesson_states[str(id)] есть completed_at.
    """
    if not required_lesson_ids:
        return 0
    if not lesson_states:
        return 0
    completed = 0
    for lid in required_lesson_ids:
        st = lesson_states.get(str(lid)) or lesson_states.get(lid)
        if isinstance(st, dict) and st.get("completed_at"):
            completed += 1
    # Округляем вниз (88.6% → 88, чтобы 100% значило ВСЕ уроки)
    return int(completed * 100 / len(required_lesson_ids))


def is_course_completed(
    lesson_states: dict[str, Any] | None,
    required_lesson_ids: list[int],
) -> bool:
    """True если все required lessons completed. Пусто required → False (нечего пройти)."""
    if not required_lesson_ids:
        return False
    if not lesson_states:
        return False
    for lid in required_lesson_ids:
        st = lesson_states.get(str(lid)) or lesson_states.get(lid)
        if not (isinstance(st, dict) and st.get("completed_at")):
            return False
    return True


# ============ Validation helpers (для роутеров) ============

def validate_lesson_kind(kind: str) -> None:
    if kind not in LESSON_KINDS:
        raise ValueError(
            f"Недопустимый kind урока: {kind!r}. Допустимы: {sorted(LESSON_KINDS)}"
        )


def validate_completion_policy(policy: str) -> None:
    if policy not in COURSE_COMPLETION_POLICIES:
        raise ValueError(
            f"Недопустимый completion_policy: {policy!r}. "
            f"Допустимы: {sorted(COURSE_COMPLETION_POLICIES)}"
        )


def validate_question_kind(kind: str) -> None:
    if kind not in QUESTION_KINDS:
        raise ValueError(
            f"Недопустимый kind вопроса: {kind!r}. Допустимы: {sorted(QUESTION_KINDS)}"
        )


def validate_target_roles(roles: Any) -> list[str]:
    """target_roles должен быть list[str] из набора ('admin','director','lawyer','manager').

    Пустой список разрешён — курс на ВСЕХ ролей.
    Возвращает дедуплицированный отсортированный список.
    """
    if not isinstance(roles, list):
        raise ValueError("target_roles должно быть списком строк")
    ALLOWED = {"admin", "director", "lawyer", "manager"}
    out: set[str] = set()
    for r in roles:
        if not isinstance(r, str):
            raise ValueError(f"target_roles содержит не-строку: {r!r}")
        if r not in ALLOWED:
            raise ValueError(
                f"target_roles содержит недопустимую роль {r!r}. "
                f"Допустимы: {sorted(ALLOWED)}"
            )
        out.add(r)
    return sorted(out)


def validate_correct_answers(
    correct: Any, options: list[str], kind: str,
) -> list[int]:
    """correct_answers должен быть list[int] валидных индексов options.

    Для kind='single' — ровно 1 элемент.
    Для kind='multi' — 1+ элементов.
    Дедуплицируется и сортируется. Index out of range → ValueError.
    """
    if not isinstance(correct, list):
        raise ValueError("correct_answers должен быть списком индексов")
    if not isinstance(options, list) or not options:
        raise ValueError("options должен быть непустым списком")
    out: set[int] = set()
    for c in correct:
        # bool — тоже int в Python, но мы хотим именно int
        if isinstance(c, bool) or not isinstance(c, int):
            raise ValueError(f"correct_answers содержит не-int: {c!r}")
        if c < 0 or c >= len(options):
            raise ValueError(
                f"correct_answers[{c}] out of range (options длина {len(options)})"
            )
        out.add(c)
    if not out:
        raise ValueError("correct_answers не должен быть пустым")
    if kind == "single" and len(out) != 1:
        raise ValueError(f"для kind='single' нужно ровно 1 правильный ответ, передано {len(out)}")
    return sorted(out)
