<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AMO -> MGCRM migration maps (filled)
|--------------------------------------------------------------------------
|
| Hand-maintained terminal maps for the temporary AMO import (Domain/Migration,
| dropped at M12). These are the SMALL, manually-curated maps; the high-volume
| auto-maps (custom-field options, products) live in the migration_maps /
| amo_product_mappings tables instead.
|
| Source funnels (AMO subdomain "macro"):
|   - 6149857  "MACRO Global"     -> pipeline_code macro_global
|   - 10915373 "MACRO AI Global"  -> pipeline_code macro_ai_global
| AMO well-known terminal statuses are fixed account-wide: 142 = won, 143 = lost.
|
| Reference enums were re-pulled read-only from the AMO API; the maps below are
| 100% complete for the load phase (every status / user / country / spec / channel
| / task-type option present in the source is covered, with explicit nulls where a
| target intentionally does not exist).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | API / extract (Phase 1)
    |--------------------------------------------------------------------------
    |
    | AMO API v4 connection + extract tuning for AmoClient and the extractors.
    | Token is a long-lived integration Bearer (AMO "долгоживущий" token) — kept
    | in env only, never hard-coded, never logged. base_url interpolates the
    | subdomain so a sandbox account can be pointed at without code changes.
    |
    | rate_limit_rps: AMO caps at ~7 req/s account-wide; we throttle to 6 to leave
    | headroom for the live prod integration running alongside the one-off import.
    | staging_path is resolved against storage_path() by the extractors; the JSONL
    | files are written there (one record per line) so transform/load re-run off
    | disk without re-hitting AMO.
    |
    */
    'api' => [
        'subdomain' => env('AMO_MIGRATION_SUBDOMAIN', 'macro'),
        'base_url' => 'https://'.env('AMO_MIGRATION_SUBDOMAIN', 'macro').'.amocrm.ru/api/v4',
        'token' => env('AMO_MIGRATION_TOKEN'),
        'rate_limit_rps' => (int) env('AMO_MIGRATION_RATE_LIMIT_RPS', 6),
        'pipeline_ids' => [6149857, 10915373],
        'staging_path' => 'amo-migration',

        // Per-entity id-filter batch sizes (AMO caps url length / filter cardinality).
        // events: GET /events caps filter[entity_id] at 10 ids per request — an 11th
        // id returns HTTP 400 "More params given than allowed.", so this MUST stay <= 10.
        'batch' => [
            'contacts' => 250,
            'companies' => 250,
            'tasks' => 50,
            'events' => 10,
        ],

        // Retry policy for 429 / 5xx (honours Retry-After when present).
        'retry' => [
            'max_attempts' => 5,
            'base_delay_ms' => 1000,
            'max_delay_ms' => 30000,
        ],

        // HTTP timeouts (seconds).
        'timeout' => 30,
        'connect_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source / pipelines
    |--------------------------------------------------------------------------
    |
    | Per-pipeline import settings keyed by our pipeline code. The codes resolve to
    | real Pipeline rows by name ('MACRO Global' / 'MACRO AI Global', seeded by
    | AmoPipelineSeeder). default_currency (DEC-A) is the currency AMO deals land in
    | when the source carries none.
    |
    */
    'pipelines' => [
        'macro_global' => [
            'name' => 'MACRO Global',
            'amo_pipeline_id' => 6149857,
            'default_currency' => 'RUB',
        ],
        'macro_ai_global' => [
            'name' => 'MACRO AI Global',
            'amo_pipeline_id' => 10915373,
            'default_currency' => 'RUB',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Status map: amo_status_id => { pipeline_code, stage_code }
    |--------------------------------------------------------------------------
    |
    | 1-for-1 by stage name across both funnels. Stage codes match AmoPipelineSeeder.
    | 142 (won) / 143 (lost) are AMO's account-wide terminal statuses: they appear in
    | every funnel, so pipeline_code is null = keep the deal's own pipeline and force
    | only the terminal stage_code (success / lost).
    |
    */
    'status_map' => [
        // MACRO Global (6149857)
        53169821 => ['pipeline_code' => 'macro_global', 'stage_code' => 'unsorted'],
        55884061 => ['pipeline_code' => 'macro_global', 'stage_code' => 'partner'],
        53169825 => ['pipeline_code' => 'macro_global', 'stage_code' => 'outbound'],
        53169829 => ['pipeline_code' => 'macro_global', 'stage_code' => 'inbound'],
        53233417 => ['pipeline_code' => 'macro_global', 'stage_code' => 'qualification'],
        53233413 => ['pipeline_code' => 'macro_global', 'stage_code' => 'schedule'], // deleted MACRO Global stage → folds into 'schedule'
        53233425 => ['pipeline_code' => 'macro_global', 'stage_code' => 'schedule'],
        83123365 => ['pipeline_code' => 'macro_global', 'stage_code' => 'walking'],
        53233429 => ['pipeline_code' => 'macro_global', 'stage_code' => 'meeting'],
        53233421 => ['pipeline_code' => 'macro_global', 'stage_code' => 'cold'],
        53169833 => ['pipeline_code' => 'macro_global', 'stage_code' => 'warm'],
        83123369 => ['pipeline_code' => 'macro_global', 'stage_code' => 'trial'],
        53233433 => ['pipeline_code' => 'macro_global', 'stage_code' => 'hot'],
        // MACRO AI Global (10915373)
        85848393 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'unsorted'],
        86443005 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'long_term'],
        85848397 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'outbound'],
        85868509 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'inbound'],
        85848401 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'qualification'],
        85868513 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'schedule'],
        85868517 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'meeting'],
        85868521 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'cold'],
        85848405 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'warm'],
        85868525 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'trial'],
        85868529 => ['pipeline_code' => 'macro_ai_global', 'stage_code' => 'hot'],
        // Shared terminal statuses (both funnels). pipeline_code null = keep the
        // deal's own pipeline; only the stage_code is forced.
        142 => ['pipeline_code' => null, 'stage_code' => 'success'],
        143 => ['pipeline_code' => null, 'stage_code' => 'lost'],
    ],

    /*
    |--------------------------------------------------------------------------
    | User map: amo_user_id => owner email (resolved by email, DEC-C)
    |--------------------------------------------------------------------------
    |
    | The ETL resolves each email to an MGCRM User by email; unmatched / departed
    | reps fall back to fallback_user_email (the AmoImportUserSeeder service account)
    | so owner stays NOT NULL. The ~5 active managers will auto-attach once their
    | MGCRM accounts use the same email. All 44 active AMO users are listed.
    |
    */
    'user_map' => [
        1318836 => 'trif.kemerovo@gmail.com',
        1331166 => 'gms0990@gmail.com',
        1676917 => 'lisitsyn.alexsander.macro@gmail.com',
        2002921 => 'stas.gavrichenko@mail.ru',
        2351116 => 'b.yadykin@macroglobaltech.com',
        2435437 => 'b.yadykin@macroprop.tech',
        2663458 => 'a.breslavskiy@gmail.com',
        2810827 => 'yu.rusakova@macrocrm.ru',
        3265804 => 'for.vladislav.egorov@gmail.com',
        3265819 => 'o.moiseeva@macroglobaltech.com',
        3811570 => 'catherineborisova@mail.com',
        3826015 => 'aleksander.zaitsev2308@yandex.ru',
        3894298 => 'vsukhoborova@inbox.ru',
        5828518 => 'alex3u@gmail.com',
        5924224 => 'denisovarita.8938@gmail.com',
        6340110 => 'm.alnabulsi@macroprop.tech',
        6511566 => 'Valteroleg735@gmail.com',
        6650037 => 'ilyarogov.mera@gmail.com',
        7349512 => 'russolatypov@gmail.com',
        7383372 => 'les-54527@mail.ru',
        7431451 => 't.bogacheva13@gmail.com',
        7497174 => 'andrei.kravchyk@yandex.ru',
        7577640 => 'r21082108@gmail.com',
        7672551 => 'test@macrodigital.ru',
        8156331 => 'a.koroleva.macro@gmail.com',
        8216286 => 'prmacrodigital@gmail.com',
        8516238 => 'm.r.volkova@yandex.ru',
        8516436 => 'k.fayl@macrodigital.ru',
        8664786 => 'v.eliseev@macroglobaltech.com',
        8830020 => 'o.pilipyuk@macrodigital.ru',
        9037533 => 'aselnurlanovna52@gmail.com',
        9167689 => 'to@macrodigital.ru',
        9180609 => 'd.zelenskiy@macroglobaltech.com',
        9189185 => 'o.bolshakova1@macrodigital.ru',
        9468397 => 'a.dyuzheva@macrodigital.ru',
        9580529 => 'f.baissary@macroprop.tech',
        11851097 => 'm.tempel@macroglobaltech.com',
        12527393 => 'k.fedorin@macroglobaltech.com',
        12730133 => 'g.lapteva@macrodigital.ru',
        12807857 => 'ch.smakov@macroglobaltech.com',
        13623909 => 'e.medvedeva@macrodigital.ru',
        13828997 => 's.shomina@macroglobaltech.com',
        13829005 => 'g.nekrasov@macroglobaltech.com',
        13869573 => 'j.zaikova@macrodigital.ru',
    ],
    'fallback_user_email' => 'import-amo@mgcrm.local',

    /*
    |--------------------------------------------------------------------------
    | Country map: amo enum_id (CF 711078) => ISO alpha-2 (or null)
    |--------------------------------------------------------------------------
    |
    | All 86 RF regions collapse to 'ru'; the importer also writes the original
    | region label into extra_fields.amo_region. Foreign countries map to ISO
    | alpha-2. "Иное государство" => null.
    |
    */
    'country_map' => [
        1188466 => 'ru', // Алтайский край
        1188468 => 'ru', // Амурская область
        1188470 => 'ru', // Архангельская область
        1188472 => 'ru', // Астраханская область
        1188474 => 'ru', // г. Байконур
        1188476 => 'ru', // Белгородская область
        1188478 => 'ru', // Брянская область
        1188480 => 'ru', // Владимирская область
        1188482 => 'ru', // Волгоградская область
        1188484 => 'ru', // Вологодская область
        1188486 => 'ru', // Воронежская область
        1188488 => 'ru', // г. Москва
        1188490 => 'ru', // Еврейская АО
        1188492 => 'ru', // Забайкальский край
        1188494 => 'ru', // Ивановская область
        1188496 => 'ru', // Иркутская область
        1188498 => 'ru', // Кабардино-Балкарская респ.
        1188500 => 'ru', // Калининградская область
        1188502 => 'ru', // Калужская область
        1188504 => 'ru', // Камчатский край
        1188506 => 'ru', // Кемеровская область
        1188508 => 'ru', // Кировская область
        1188510 => 'ru', // Костромская область
        1188512 => 'ru', // Краснодарский край
        1188514 => 'ru', // Красноярский край
        1188516 => 'ru', // Курганская область
        1188518 => 'ru', // Курская область
        1188520 => 'ru', // Ленинградская область
        1188522 => 'ru', // Липецкая область
        1188524 => 'ru', // Магаданская область
        1188526 => 'ru', // Московская область
        1188528 => 'ru', // Мурманская область
        1188530 => 'ru', // Ненецкий АО
        1188532 => 'ru', // Нижегородская область
        1188534 => 'ru', // Новгородская область
        1188536 => 'ru', // Новосибирская область
        1188538 => 'ru', // Омская область
        1188540 => 'ru', // Оренбургская область
        1188542 => 'ru', // Орловская область
        1188544 => 'ru', // Пензенская область
        1188546 => 'ru', // Пермский край
        1188548 => 'ru', // Приморский край
        1188550 => 'ru', // Псковская область
        1188552 => 'ru', // Респ. Адыгея
        1188554 => 'ru', // Респ. Алтай
        1188556 => 'ru', // Респ. Башкортостан
        1188558 => 'ru', // Респ. Бурятия
        1188560 => 'ru', // Респ. Дагестан
        1188562 => 'ru', // Респ. Ингушетия
        1188564 => 'ru', // Респ. Карачаево-Черкессия
        1188566 => 'ru', // Респ. Калмыкия
        1188568 => 'ru', // Респ. Карелия
        1188570 => 'ru', // Респ. Коми
        1188572 => 'ru', // Респ. Крым
        1188574 => 'ru', // Респ. Марий Эл
        1188576 => 'ru', // Респ. Мордовия
        1188578 => 'ru', // Респ. Саха (Якутия)
        1188580 => 'ru', // Респ. Северная Осетия
        1188582 => 'ru', // Респ. Татарстан
        1188584 => 'ru', // Респ. Тыва
        1188586 => 'ru', // Респ. Хакасия
        1188588 => 'ru', // Ростовская область
        1188590 => 'ru', // Рязанская область
        1188592 => 'ru', // Самарская область
        1188594 => 'ru', // г. Санкт-Петербург
        1188596 => 'ru', // Саратовская область
        1188598 => 'ru', // Сахалинская область
        1188600 => 'ru', // Свердловская область
        1188602 => 'ru', // г. Севастополь
        1188604 => 'ru', // Смоленская область
        1188606 => 'ru', // Ставропольский край
        1188608 => 'ru', // Тамбовская область
        1188610 => 'ru', // Тверская область
        1188612 => 'ru', // Томская область
        1188614 => 'ru', // Тульская область
        1188616 => 'ru', // Тюменская область
        1188618 => 'ru', // Удмуртская Республика
        1188620 => 'ru', // Ульяновская область
        1188622 => 'ru', // Хабаровский край
        1188624 => 'ru', // Ханты-Мансийский АО
        1188626 => 'ru', // Челябинская область
        1188628 => 'ru', // Чеченская Республика
        1188630 => 'ru', // Чувашская Республика
        1188632 => 'ru', // Чукотский АО
        1188634 => 'ru', // Ямало-Ненецкий АО
        1188636 => 'ru', // Ярославская область
        1188638 => null, // Иное государство
        1188640 => 'uz', // Узбекистан
        1188642 => 'kz', // Казахстан
        1188664 => 'ae', // UAE
        1191874 => 'ge', // Грузия
        1191876 => 'kg', // Кыргызстан
        1192280 => 'ir', // Iran
        1192282 => 'sa', // Saudi Arabia
        1192284 => 'tr', // Turkey
        1192286 => 'il', // Israel
        1192288 => 'eg', // Egypt
        1192290 => 'qa', // Qatar
        1192292 => 'kw', // Kuwait
        1192294 => 'om', // Oman
        1192296 => 'bh', // Bahrain
        1192298 => 'cy', // Cyprus
        1192300 => 'lb', // Lebanon
        1192302 => 'jo', // Jordan
        1192566 => 'az', // Азербайджан
        1192998 => 'me', // Montenegro
        1193048 => 'by', // Беларусь
        1193940 => 'am', // Армения
        1194894 => 'tj', // Таджикистан
        1194938 => 'rs', // Сербия
        1195348 => 'lt', // Литва
        1197160 => 'th', // Thailand
        1199190 => 'in', // India
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax-id label map: country_code => requisite label
    |--------------------------------------------------------------------------
    |
    | Drives the per-country tax-id label on the company requisite. Countries not
    | listed fall through to null => the UI shows the generic "Tax ID".
    |
    */
    'tax_id_label_map' => [
        'ru' => 'ИНН',
        'uz' => 'ИНН',
        'kz' => 'БИН',
        'ge' => 'TIN',
        'ae' => 'TRN',
        'by' => 'УНП',
        'am' => 'TIN',
        'kg' => 'ИНН',
    ],

    /*
    |--------------------------------------------------------------------------
    | Category (CF 748860) — reference only, NOT mapped to a category_code
    |--------------------------------------------------------------------------
    |
    | The importer copies the RAW value (S1/S2/M1/M2/L1/L2/L3) into
    | extra_fields.amo_category; category logic is curated separately by the user.
    | Listed here only for lookup.
    |
    */
    'category_enums' => [
        1204186 => 'S 1',
        1204188 => 'S 2',
        1204190 => 'M 1',
        1204192 => 'M 2',
        1204194 => 'L 1',
        1204196 => 'L 2',
        1204198 => 'L 3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Specialization map: amo enum_id (CF 709546) => CompanySpecialization (or null)
    |--------------------------------------------------------------------------
    |
    | Targets are App\Domain\Crm\Enums\CompanySpecialization values. Options without
    | a matching target (industrial / finishing / road / designers / management co. /
    | "other") map to null (skip) — MGCRM has no 'other' specialization. The importer
    | takes the FIRST mappable value of the AMO multiselect.
    |
    */
    'specialization_map' => [
        1138196 => 'developer', // Застройщик (developer)
        1138198 => 'contractor', // Генподрядчик (construction company)
        1138200 => null, // Промышленное строительство (-)
        1138202 => null, // Отделочники (-)
        1138204 => null, // УК/ЖЭК (services company)
        1138206 => 'real_estate_agency', // АН/риэлторы (real estate agency)
        1138208 => 'supplier', // Производители оборудования (suppliers)
        1138210 => null, // Дорожное строительство (-)
        1138212 => 'contractor', // Подрядные организации (-)
        1138214 => null, // Проектировщики (architects)
        1138216 => null, // Другое/недевелопмент (other)
        1189808 => 'partner', // Агент MACRO (consultants)
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel map: amo enum_id (CF 708366) => AcquisitionChannel name
    |--------------------------------------------------------------------------
    |
    | Targets are one of the 7 seeded AcquisitionChannel names (AcquisitionChannelSeeder).
    | Anything without a clean target collapses to 'Другое'.
    |
    */
    'channel_map' => [
        1136340 => 'Другое', // Сайт
        1136342 => 'Холодный звонок', // Холодные звонки менеджеров
        1136344 => 'Рекомендации партнёров', // Партнер
        1136350 => 'Соцсети', // Соц.сети
        1136354 => 'Другое', // СберЛид
        1136410 => 'Другое', // Сторонние сайты
        1136412 => 'Другое', // Действующий клиент
        1136456 => 'Рекомендации партнёров', // Рекомендации сотрудников
        1190532 => 'Входящий запрос', // Входящий звонок на 8 800
        1190534 => 'Другое', // Бывший клиент
        1190536 => 'Рекомендации клиентов', // Рекомендации клиентов
        1190538 => 'Другое', // СберСпециалистMACRO
        1190540 => 'Выставка', // СберМероприятие
        1190542 => 'Выставка', // Мероприятия (не Сбер)
        1200686 => 'Другое', // Лид от ТП MACRO
        1200688 => 'Другое', // Лид от ОВ MACRO
        1200690 => 'Другое', // Лид от ОП MACRO
    ],

    /*
    |--------------------------------------------------------------------------
    | Task type map: amo_task_type_id => mgcrm activity kind
    |--------------------------------------------------------------------------
    |
    | Default is 'task'. Only an explicit allowlist resolves to call / meeting; the
    | remaining ~73 AMO task types fall through to the default (see task_type_default).
    |
    */
    'task_type_default' => 'task',
    'task_type_map' => [
        // call
        1 => 'call',          // Связаться
        2783037 => 'call',    // 1.2 Звонок
        2783009 => 'call',    // 1.3.Call Touch
        3349837 => 'call',    // 8.2 Cash call
        // meeting
        2 => 'meeting',       // Встреча
        1887817 => 'meeting', // 4.1 MeetingDone
        3689485 => 'meeting', // 3.4. Schedule
        3689489 => 'meeting', // 4.5 ArrangeOn-e
        3349657 => 'meeting', // 4.6 ArrangeOff-e
        3689493 => 'meeting', // 6.7. ST-meeting
        2478399 => 'meeting', // ОС Созвон
        2478396 => 'meeting', // ОС Встреча
    ],

    /*
    |--------------------------------------------------------------------------
    | Note type map: amo_note_type => mgcrm note/activity kind ('skip' = drop)
    |--------------------------------------------------------------------------
    */
    'note_type_map' => [
        'call_in' => 'call',
        'call_out' => 'call',
        'common' => 'note',
        'attachment' => 'note',
        'service_message' => 'note',
        'amomail_message' => 'note',
        'messenger' => 'note',
        'geolocation' => 'skip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Loss reason map: amo_loss_reason_id => mgcrm lost_reason code/id
    |--------------------------------------------------------------------------
    |
    | The 4 AMO loss reasons are stage-position labels ("after Проверка" etc.), not
    | semantic reasons, so all map to null: the deal still lands in the lost stage,
    | just without a structured lost_reason. Intentionally empty.
    |
    | AMO source (for reference):
    |   9139455 (1) после "Проверка"
    |   9139458 (2) после "Назначить/провести презентацию"
    |   9139461 (3) после "Греть (не готовы)"
    |   9139464 (4) после "Дожимать/Тестировать/Согласовать"
    |
    */
    'loss_reason_map' => [
        // intentionally empty — all AMO loss reasons => null (no semantic target)
    ],

];
