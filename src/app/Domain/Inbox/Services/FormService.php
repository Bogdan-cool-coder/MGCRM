<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Form;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FormService — all Form CRUD logic. Slug is auto-generated (unique) when not
 * supplied; a clashing slug raises 409. publicMeta() returns the anon-safe view.
 */
class FormService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Form::query()
            ->with('createdBy:id,full_name')
            ->when(isset($filters['is_active']), fn (Builder $q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['channel_id']), fn (Builder $q) => $q->where('channel_id', $filters['channel_id']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Form
    {
        $slug = $data['public_slug'] ?? null;
        if ($slug !== null && $slug !== '') {
            if ($this->slugTaken($slug)) {
                throw ValidationException::withMessages([
                    'public_slug' => "Slug '{$slug}' is already taken.",
                ])->status(409);
            }
        } else {
            $slug = $this->generateUniqueSlug();
        }

        $data['public_slug'] = $slug;
        $data['fields'] ??= [];
        $data['created_by_user_id'] = $creator->id;

        return Form::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Form $form, array $data): Form
    {
        if (array_key_exists('public_slug', $data) && $data['public_slug'] !== null && $data['public_slug'] !== '') {
            if ($this->slugTaken($data['public_slug'], $form->id)) {
                throw ValidationException::withMessages([
                    'public_slug' => "Slug '{$data['public_slug']}' is already taken.",
                ])->status(409);
            }
        }

        $form->update($data);
        $form->refresh();

        return $form;
    }

    public function delete(Form $form): void
    {
        $form->delete();
    }

    /**
     * Anon-safe metadata for the public render page (no slug/channel leaked).
     * Throws 404 for an inactive form (hides its existence).
     *
     * @return array{name: string, fields: list<array<string, mixed>>, thank_you_text: string|null}
     */
    public function publicMeta(Form $form): array
    {
        if (! $form->is_active) {
            throw (new ModelNotFoundException)
                ->setModel(Form::class);
        }

        return [
            'name' => $form->name,
            'fields' => $form->fields ?? [],
            'thank_you_text' => $form->thank_you_text,
        ];
    }

    private function slugTaken(string $slug, ?int $excludeId = null): bool
    {
        return Form::query()
            ->where('public_slug', $slug)
            ->when($excludeId !== null, fn (Builder $q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    private function generateUniqueSlug(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = Str::random(11);
            if (! $this->slugTaken($candidate)) {
                return $candidate;
            }
        }

        // Astronomically unlikely; widen the space rather than fail the request.
        return Str::random(16);
    }
}
