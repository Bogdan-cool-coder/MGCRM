/**
 * video-parsers.ts — парсеры URL для видео-провайдеров.
 * Используется в admin block-editors и student block-viewers.
 * Типизация строго без any.
 */

export interface ParsedDriveUrl {
  kind: "drive";
  embedUrl: string;
  fileId: string;
}

export interface ParsedYouTubeId {
  kind: "youtube";
  youtubeId: string;
}

export interface ParsedLoomId {
  kind: "loom";
  loomId: string;
}

/**
 * Парсит Google Drive URL и возвращает embed-ссылку.
 * Поддерживает форматы:
 *   - drive.google.com/file/d/{ID}/view
 *   - drive.google.com/file/d/{ID}/preview (as-is)
 *   - drive.google.com/open?id={ID}
 * Возвращает null при невалидном URL.
 */
export function parseDriveUrl(url: string): ParsedDriveUrl | null {
  if (!url) return null;

  try {
    const u = new URL(url);

    // Уже preview — как есть, только извлекаем fileId
    const previewMatch = u.pathname.match(/\/file\/d\/([^/]+)\/preview/);
    if (previewMatch) {
      return {
        kind: "drive",
        fileId: previewMatch[1],
        embedUrl: url,
      };
    }

    // /file/d/{ID}/view или /file/d/{ID}/edit и т.д.
    const fileMatch = u.pathname.match(/\/file\/d\/([^/]+)/);
    if (fileMatch) {
      const fileId = fileMatch[1];
      return {
        kind: "drive",
        fileId,
        embedUrl: `https://drive.google.com/file/d/${fileId}/preview`,
      };
    }

    // ?id={ID}
    const idParam = u.searchParams.get("id");
    if (idParam && (u.hostname === "drive.google.com" || u.hostname === "docs.google.com")) {
      return {
        kind: "drive",
        fileId: idParam,
        embedUrl: `https://drive.google.com/file/d/${idParam}/preview`,
      };
    }

    return null;
  } catch {
    return null;
  }
}

/**
 * Парсит YouTube URL или ID и возвращает 11-символьный videoId.
 * Поддерживает форматы:
 *   - youtube.com/watch?v=ID
 *   - youtu.be/ID
 *   - youtube.com/embed/ID
 *   - прямой ID (11 символов)
 * Возвращает null при невалидном URL.
 */
export function parseYoutubeId(urlOrId: string): ParsedYouTubeId | null {
  if (!urlOrId) return null;

  // Если это уже ID (11 символов, без пробелов, буквы/цифры/дефис/underscore)
  if (/^[a-zA-Z0-9_-]{11}$/.test(urlOrId.trim())) {
    return { kind: "youtube", youtubeId: urlOrId.trim() };
  }

  try {
    const u = new URL(urlOrId);

    // youtube.com/watch?v=ID
    const vParam = u.searchParams.get("v");
    if (vParam && /^[a-zA-Z0-9_-]{11}$/.test(vParam)) {
      return { kind: "youtube", youtubeId: vParam };
    }

    // youtu.be/ID или youtube.com/embed/ID
    const pathMatch = u.pathname.match(/\/(?:embed\/|v\/)?([a-zA-Z0-9_-]{11})(?:[/?]|$)/);
    if (pathMatch) {
      return { kind: "youtube", youtubeId: pathMatch[1] };
    }

    return null;
  } catch {
    // Попытка как относительный путь
    const match = urlOrId.match(/[?&]v=([a-zA-Z0-9_-]{11})/);
    if (match) return { kind: "youtube", youtubeId: match[1] };
    return null;
  }
}

/**
 * Парсит Loom URL и возвращает loomId.
 * Поддерживает форматы:
 *   - loom.com/share/{ID}
 *   - loom.com/embed/{ID}
 *   - www.loom.com/share/{ID}
 * Возвращает null при невалидном URL.
 */
export function parseLoomId(url: string): ParsedLoomId | null {
  if (!url) return null;

  try {
    const u = new URL(url);
    if (!u.hostname.endsWith("loom.com")) return null;

    const match = u.pathname.match(/\/(?:share|embed)\/([a-zA-Z0-9]+)/);
    if (match) {
      return { kind: "loom", loomId: match[1] };
    }

    return null;
  } catch {
    return null;
  }
}
