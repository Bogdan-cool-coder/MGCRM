<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts\Admin;

use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Services\LicensorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreLicensorBankAccountRequest;
use App\Http\Requests\Contracts\UpdateLicensorBankAccountRequest;
use App\Http\Resources\Contracts\LicensorBankAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class LicensorBankAccountController extends Controller
{
    public function __construct(
        private readonly LicensorService $service,
    ) {}

    public function index(Request $request, LicensorEntity $licensorEntity): AnonymousResourceCollection
    {
        $this->authorize('view', $licensorEntity);

        return LicensorBankAccountResource::collection(
            $licensorEntity->bankAccounts()->orderBy('currency')->get()
        );
    }

    public function store(StoreLicensorBankAccountRequest $request, LicensorEntity $licensorEntity): JsonResponse
    {
        $account = $this->service->createAccount($licensorEntity, $request->validated());

        return LicensorBankAccountResource::make($account)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateLicensorBankAccountRequest $request, LicensorBankAccount $bankAccount): JsonResource
    {
        $account = $this->service->updateAccount($bankAccount, $request->validated());

        return LicensorBankAccountResource::make($account);
    }

    public function destroy(Request $request, LicensorBankAccount $bankAccount): JsonResponse
    {
        // Bank-account deletion is admin-only per spec (vault S2.1 §Е).
        $this->authorize('delete', $bankAccount->licensor);

        $this->service->deleteAccount($bankAccount);

        return response()->json(null, 204);
    }
}
