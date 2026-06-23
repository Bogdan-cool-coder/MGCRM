<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST companies/{company}/folders/{folder}/files
 * POST contacts/{contact}/folders/{folder}/files
 */
class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');
        if ($company instanceof Company) {
            return $this->user()->can('update', $company);
        }

        /** @var Contact|null $contact */
        $contact = $this->route('contact');
        if ($contact instanceof Contact) {
            return $this->user()->can('update', $contact);
        }

        return false;
    }

    public function rules(): array
    {
        $maxKb = (int) config('crm.files.max_size_kb', 20 * 1024); // default 20 MB

        return [
            'file' => ['required', 'file', "max:{$maxKb}"],
        ];
    }
}
