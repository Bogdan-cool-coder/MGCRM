<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\ContactPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDirectoryRequest;
use App\Http\Resources\Crm\ContactPositionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactPositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $positions = ContactPosition::orderBy('sort_order')->orderBy('name')->get();

        return ContactPositionResource::collection($positions);
    }

    public function store(StoreDirectoryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $pos = ContactPosition::create($request->validated());

        return ContactPositionResource::make($pos);
    }

    public function show(Request $request, ContactPosition $contactPosition): JsonResource
    {
        return ContactPositionResource::make($contactPosition);
    }

    public function update(StoreDirectoryRequest $request, ContactPosition $contactPosition): JsonResource
    {
        $this->authorize('admin-write');

        $contactPosition->update($request->validated());

        return ContactPositionResource::make($contactPosition->fresh());
    }

    public function destroy(Request $request, ContactPosition $contactPosition): JsonResponse
    {
        $this->authorize('admin-write');

        $contactPosition->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
