<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompanyBranding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBranding>
 */
class CompanyBrandingFactory extends Factory
{
    protected $model = CompanyBranding::class;

    public function definition(): array
    {
        return [
            // company_id is supplied by the caller (no orphan FK).
            'logo_path'  => null,
            'colors'     => [
                'primary'   => '#1d4ed8',
                'secondary' => '#64748b',
                'accent'    => '#f59e0b',
                'text'      => '#111827',
                'bg'        => '#ffffff',
            ],
            'fonts'      => ['heading' => 'Inter', 'body' => 'Inter'],
            'header'     => ['ru' => 'Шапка', 'en' => 'Header'],
            'footer'     => ['ru' => 'Подвал', 'en' => 'Footer'],
            'requisites' => ['inn' => '7700000000', 'address' => 'Москва'],
        ];
    }
}
