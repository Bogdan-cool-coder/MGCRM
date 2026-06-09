"""Создаёт templates/contracts_master/reference.docx из оригинала,
очищая body (оставляя только стили, header/footer, разделы и табличные шаблоны).

Pandoc использует reference.docx как источник styles при конвертации .md → .docx.
Запуск:
    python scripts/build_reference_docx.py \\
        templates/contracts_master/_source_original.docx \\
        templates/contracts_master/reference.docx
"""

from __future__ import annotations

import shutil
import sys
from pathlib import Path

from docx import Document


def clean_body(src_path: Path, dst_path: Path) -> None:
    """Копирует исходный .docx → reference.docx и удаляет все параграфы и таблицы из body.
    Styles (styles.xml), header/footer, темы — остаются. Pandoc их подхватит."""
    shutil.copy(src_path, dst_path)
    doc = Document(str(dst_path))

    body = doc.element.body
    # Удаляем все дочерние элементы body, кроме sectPr (он в конце — определяет разметку страницы)
    sectPr = body.find("{http://schemas.openxmlformats.org/wordprocessingml/2006/main}sectPr")
    for child in list(body):
        if child is sectPr:
            continue
        body.remove(child)

    # Добавляем один пустой параграф со стилем Normal — чтобы body не было полностью пустым
    p = doc.add_paragraph("", style="Normal")
    if sectPr is not None:
        # Перемещаем sectPr обратно в конец
        body.remove(sectPr)
        body.append(sectPr)

    doc.save(str(dst_path))
    print(f"Saved reference.docx → {dst_path}")
    print(f"Размер: {dst_path.stat().st_size:,} байт")


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python build_reference_docx.py <source.docx> <reference.docx>")
        sys.exit(1)
    clean_body(Path(sys.argv[1]), Path(sys.argv[2]))
