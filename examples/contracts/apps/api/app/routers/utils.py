from fastapi import APIRouter, Query

from app.deps import CurrentUser
from app.services.num_to_words import amount_to_words_ru

router = APIRouter(prefix="/utils", tags=["utils"])


@router.get("/num-to-words")
async def num_to_words(
    _: CurrentUser,
    amount: str = Query(..., description="Сумма (можно с пробелами и запятой)"),
    currency: str | None = Query(None, description="KZT/UZS/RUB/USD/EUR"),
):
    text = amount_to_words_ru(amount, currency)
    return {"text": text}
