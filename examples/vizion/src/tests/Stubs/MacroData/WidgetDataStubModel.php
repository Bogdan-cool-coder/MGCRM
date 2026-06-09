<?php

declare(strict_types=1);

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

/**
 * Stub Eloquent model used by WidgetDataTest.
 *
 * Points at the 'sqlite' connection (the in-memory test DB) and
 * the 'widget_data_test_rows' table created in WidgetDataTest::setUp().
 * Registered under the App\Models\MacroData namespace so that
 * WidgetDataService::resolveModelClass('WidgetDataStubModel') finds it.
 */
class WidgetDataStubModel extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'widget_data_test_rows';

    public $timestamps = false;

    protected $guarded = ['*'];
}

/**
 * Stub related model for relation group_by tests.
 *
 * Represents the "manager" lookup table: id + manager_name.
 * Primary key: id (default).
 */
class WidgetDataStubRelatedModel extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'widget_data_related_rows';

    public $timestamps = false;

    protected $guarded = ['*'];
}

/**
 * Stub primary model with a BelongsTo relation to WidgetDataStubRelatedModel.
 *
 * Table: widget_data_fk_rows
 * Columns: id, manager_id (FK → widget_data_related_rows.id), amount
 *
 * Used to test relation group_by (dot-path "stubManager.manager_name").
 */
class WidgetDataStubFkModel extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'widget_data_fk_rows';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * BelongsTo relation: manager_id → widget_data_related_rows.id
     * Mirrors the real EstateDeals::usersManager() pattern.
     */
    public function stubManager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WidgetDataStubRelatedModel::class, 'manager_id', 'id');
    }

    /**
     * HasMany relation (used to test that HasMany is correctly rejected for
     * relation group_by — would cause row duplication).
     */
    public function stubChildren(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WidgetDataStubRelatedModel::class, 'manager_id', 'id');
    }
}

/**
 * Stub model for temporal group_by tests.
 *
 * Table: widget_data_date_rows
 * Columns: id, amount (REAL), deal_date (DATE)
 *
 * Used to verify that temporal tokens ("deal_date|month") produce
 * correct DATE_FORMAT (MySQL) / strftime (SQLite via subclass) aliases
 * and that labels are YYYY-MM strings sorted chronologically.
 */
class WidgetDataStubDateModel extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'widget_data_date_rows';

    public $timestamps = false;

    protected $guarded = ['*'];
}
