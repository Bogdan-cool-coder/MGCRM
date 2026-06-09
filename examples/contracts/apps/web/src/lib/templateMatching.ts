/**
 * Эпик 3: pure-функция фильтрации шаблонов по контексту договора.
 *
 * Правило: пустой список привязки = «подходит всем» (без ограничений).
 * Непустой список = должен содержать значение из контекста.
 * Все 4 фильтра комбинируются по AND.
 */

export interface TemplateBindings {
  product_codes?: string[] | null;
  country_codes?: string[] | null;
  client_category_codes?: string[] | null;
  department_ids?: number[] | null;
}

export interface TemplateMatchContext {
  product_code?: string | null;
  country_code?: string | null;
  client_category_code?: string | null;
  department_id?: number | null;
}

export function isTemplateApplicable(
  t: TemplateBindings,
  ctx: TemplateMatchContext,
): boolean {
  if (
    t.product_codes?.length &&
    (!ctx.product_code || !t.product_codes.includes(ctx.product_code))
  ) {
    return false;
  }
  if (
    t.country_codes?.length &&
    (!ctx.country_code || !t.country_codes.includes(ctx.country_code))
  ) {
    return false;
  }
  if (
    t.client_category_codes?.length &&
    (!ctx.client_category_code ||
      !t.client_category_codes.includes(ctx.client_category_code))
  ) {
    return false;
  }
  if (
    t.department_ids?.length &&
    (!ctx.department_id || !t.department_ids.includes(ctx.department_id))
  ) {
    return false;
  }
  return true;
}
