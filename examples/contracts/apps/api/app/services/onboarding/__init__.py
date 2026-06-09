"""Эпик 13: Онбординг — обучающие курсы и прогресс.

Подпакет вместо одного файла потому, что в онбординге несколько обособленных
зон ответственности (course CRUD / quiz scoring / progress tracking / auto-assign
по роли). Дробить на 4 файла плоско в services/ — захламит namespace; держать
здесь и импортить через app.services.onboarding.<module>.

Модули:
- courses — CRUD helpers для Course/Module/Lesson/Question + validate_content_blocks
- quiz — start/submit attempt, scoring, randomize, rate-limit
- progress — mark_lesson_completed, recompute_course_status, enforce_soft_gate
- auto_assign — assign_default_courses (по target_roles при POST /users)
"""
