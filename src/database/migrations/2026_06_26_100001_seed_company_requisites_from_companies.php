<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: for every existing crm_companies row that has at least one
 * requisite-related field set, create one company_requisites row (is_current=true)
 * copying the single-set data from the Company columns.
 *
 * Companies with all-null requisite fields also get a stub current row so that
 * the "every company has exactly one current requisites" invariant holds.
 *
 * This migration is irreversible (down() is a no-op) because reverting it would
 * mean discarding data that the application may have updated through the new API.
 * If you need to roll back schema, run the preceding migration's down() first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        // Pull all non-deleted companies (soft-delete: deleted_at is null).
        $companies = DB::table('crm_companies')
            ->whereNull('deleted_at')
            ->select([
                'id',
                'legal_name',
                'full_legal_form',
                'legal_form',
                'gender_ending_oe',
                'director_position',
                'director_genitive',
                'director_short',
                'acts_basis',
                'tax_id_label',
                'tax_id',
                'country_code',
                'address',
                'bank',
                'bank_code_label',
                'bank_code',
                'account',
            ])
            ->get();

        $rows = [];

        foreach ($companies as $c) {
            // Flatten legacy bank fields into a bank_details JSON blob.
            $bankDetails = null;
            $hasBank = ($c->bank || $c->bank_code || $c->bank_code_label || $c->account);
            if ($hasBank) {
                $bankDetails = json_encode([
                    'bank' => $c->bank,
                    'bank_code_label' => $c->bank_code_label,
                    'bank_code' => $c->bank_code,
                    'account' => $c->account,
                ], JSON_UNESCAPED_UNICODE);
            }

            $rows[] = [
                'company_id' => $c->id,
                'legal_name' => $c->legal_name,
                'full_legal_form' => $c->full_legal_form,
                'legal_form' => $c->legal_form,
                'gender_ending_oe' => $c->gender_ending_oe,
                'director_position' => $c->director_position,
                'director_genitive' => $c->director_genitive,
                'director_short' => $c->director_short,
                'acts_basis' => $c->acts_basis,
                'tax_id_label' => $c->tax_id_label,
                'tax_id' => $c->tax_id,
                'country_code' => $c->country_code,
                'address' => $c->address,
                'bank_details' => $bankDetails,
                'is_current' => true,
                'valid_from' => null,
                'valid_to' => null,
                'label' => 'Основные реквизиты',
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Chunk every 200 to avoid huge parameter lists.
            if (count($rows) >= 200) {
                DB::table('company_requisites')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('company_requisites')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally empty — reverting a data migration risks data loss.
        // Drop the schema migration (100000) to remove the table entirely.
    }
};
