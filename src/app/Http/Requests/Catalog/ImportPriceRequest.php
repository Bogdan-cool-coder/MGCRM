<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ImportPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('admin-write');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
