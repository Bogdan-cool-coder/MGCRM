<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'name',
        'is_system',
        'crm_url',
        'currency_code',
        'timezone',
        'macrodata_host',
        'macrodata_port',
        'macrodata_database',
        'macrodata_username',
        'macrodata_password',
    ];

    protected $hidden = [
        'macrodata_password',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'macrodata_password' => 'encrypted',
        ];
    }

    /**
     * Instance-level cache of resolved semantic_key => value lookups so that a
     * report config with N placeholders triggers at most one DB read. Populated
     * lazily by {@see macrodataValue()} (single key) or upfront by
     * {@see macrodataMappingsAsArray()} (all keys preload).
     *
     * Not persisted, reset on fresh()/refresh() because the relation is
     * unloaded by Eloquent when the model is reloaded.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $macrodataValueCache = null;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function macrodataMappings(): HasMany
    {
        return $this->hasMany(CompanyMacrodataMapping::class);
    }

    /**
     * Per-company brand profile (logo, palette, fonts, header/footer, requisites)
     * applied to HTML commercial proposals. One-to-one; may be absent (the
     * branding pipeline falls back to defaults when null).
     */
    public function branding(): HasOne
    {
        return $this->hasOne(CompanyBranding::class);
    }

    /**
     * Resolve a single semantic_key for this company.
     *
     * First call hydrates the full mappings cache in one query — subsequent
     * lookups (e.g. ConfigResolver expanding multiple placeholders in one
     * report config) are O(1) in memory. Returns $default when the key is
     * absent, never throws.
     */
    public function macrodataValue(string $semanticKey, mixed $default = null): mixed
    {
        if ($this->macrodataValueCache === null) {
            $this->macrodataValueCache = $this->loadMacrodataCache();
        }

        return array_key_exists($semanticKey, $this->macrodataValueCache)
            ? $this->macrodataValueCache[$semanticKey]
            : $default;
    }

    /**
     * Return all mappings as a flat `[semantic_key => value]` array. Useful for
     * the ConfigResolver's ResolverContext where the upfront cost of one query
     * is paid once and every subsequent placeholder is memory-only.
     *
     * @return array<string, mixed>
     */
    public function macrodataMappingsAsArray(): array
    {
        if ($this->macrodataValueCache === null) {
            $this->macrodataValueCache = $this->loadMacrodataCache();
        }

        return $this->macrodataValueCache;
    }

    /**
     * Single DB read that hydrates the instance cache. Reuses the
     * `macrodataMappings` relation when it's already eager-loaded to avoid a
     * redundant round-trip in scenarios like ReportController preloading
     * mappings alongside the active company.
     *
     * @return array<string, mixed>
     */
    private function loadMacrodataCache(): array
    {
        $rows = $this->relationLoaded('macrodataMappings')
            ? $this->getRelation('macrodataMappings')
            : $this->macrodataMappings()->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->semantic_key] = $row->value;
        }

        return $out;
    }
}
