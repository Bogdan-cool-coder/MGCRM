<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\Source;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDirectoryRequest;
use App\Http\Resources\Crm\SourceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sources = Source::orderBy('sort_order')->get();

        return SourceResource::collection($sources);
    }

    public function store(StoreDirectoryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $source = Source::create($request->validated());

        return SourceResource::make($source);
    }

    public function show(Request $request, Source $source): JsonResource
    {
        return SourceResource::make($source);
    }

    public function update(StoreDirectoryRequest $request, Source $source): JsonResource
    {
        $this->authorize('admin-write');

        $source->update($request->validated());

        return SourceResource::make($source->fresh());
    }

    public function destroy(Request $request, Source $source): JsonResponse
    {
        $this->authorize('admin-write');

        $source->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
