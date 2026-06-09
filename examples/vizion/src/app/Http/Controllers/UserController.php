<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();
        // Single source of truth: ResolveActiveCompany middleware. The legacy
        // ?company_id= query param is no longer honoured — to scope to another
        // company the client must switch via POST /api/active-company/{id}.
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $currentUser->company_id);

        if ($currentUser->role === 'superadmin') {
            return User::with('company')
                ->where('company_id', $activeCompanyId)
                ->get();
        }

        if ($currentUser->role === 'admin') {
            return User::where('company_id', $activeCompanyId)->get();
        }

        return response()->json(['message' => __('auth.forbidden')], 403);
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->role === 'superadmin') {
            // может создавать в любой компании, любую роль
        } elseif ($currentUser->role === 'admin') {
            // может создавать только в своей компании, не superadmin
        } else {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $allowedRoles = $currentUser->role === 'superadmin'
            ? ['superadmin', 'admin', 'analyst', 'viewer']
            : ['admin', 'analyst', 'viewer'];

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in($allowedRoles)],
            'company_id' => 'required|exists:companies,id',
            'locale' => 'sometimes|string|max:5',
        ]);

        if ($currentUser->role === 'admin' && (int) $data['company_id'] !== $currentUser->company_id) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data['company_accesses'] = [['company_id' => (int) $data['company_id'], 'role' => $data['role']]];

        return User::create($data);
    }

    public function show(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ($currentUser->role === 'superadmin') {
            return $user->load('company');
        }

        if ($currentUser->role === 'admin' && $user->company_id === $currentUser->company_id) {
            return $user->load('company');
        }

        return response()->json(['message' => __('auth.forbidden')], 403);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();

        $allowedRoles = ['superadmin', 'admin', 'analyst', 'viewer'];

        if ($currentUser->role === 'superadmin') {
            // может всё
        } elseif ($currentUser->role === 'admin' && $user->company_id === $currentUser->company_id) {
            $allowedRoles = ['admin', 'analyst', 'viewer'];
        } else {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // ---- Superadmin peer-guard -----------------------------------------
        // A superadmin must not manage ANOTHER superadmin in privileged ways.
        // Self is always exempt (a superadmin may edit their own account). The
        // target's global role is the `role` column — same source the rest of
        // the ACL reads. These guards only fire for actor=superadmin acting on
        // a DIFFERENT superadmin; non-superadmin targets and self are untouched.
        $isOtherSuperadmin = $user->role === 'superadmin' && $user->id !== $currentUser->id;

        if ($isOtherSuperadmin) {
            // (a) Password: mere presence of the field signals intent to change,
            //     so has() is the correct trigger (no value-diff check).
            if ($request->has('password')) {
                return response()->json(
                    ['message' => __('users.cannot_change_superadmin_password')],
                    403
                );
            }

            // (b) Role: block only when the request ACTUALLY changes the value —
            //     the field is present AND the new value differs from current.
            //     Roles are plain strings, so a direct !== comparison is exact.
            //     This avoids a false 403 when the frontend echoes back an
            //     unchanged `role` alongside an edit to `name` etc.
            if ($request->has('role') && $request->input('role') !== $user->role) {
                return response()->json(
                    ['message' => __('users.cannot_change_superadmin_role')],
                    403
                );
            }

            // (c) Company accesses: block only on a REAL change. company_accesses
            //     is a jsonb list of {company_id, role} maps; key/element order
            //     must not produce a false positive, so both the incoming and the
            //     stored value are deep-normalised (recursively ksort'd, lists
            //     sorted by canonical encoding) before comparison.
            if (
                $request->has('company_accesses')
                && $this->normalizeAccesses($request->input('company_accesses'))
                    !== $this->normalizeAccesses($user->company_accesses)
            ) {
                return response()->json(
                    ['message' => __('users.cannot_change_superadmin_access')],
                    403
                );
            }
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'role' => ['sometimes', Rule::in($allowedRoles)],
            'company_id' => 'sometimes|exists:companies,id',
            'locale' => 'sometimes|string|max:5',
            'company_accesses' => 'sometimes|array',
        ]);

        $user->update($data);

        return $user;
    }

    /**
     * Deep-normalise a company_accesses value into a canonical, order-independent
     * structure for equality comparison.
     *
     * The jsonb column is a list of associative maps (e.g.
     * [['company_id' => 1, 'role' => 'admin'], ...]). When the frontend echoes
     * the value back the key order inside each map, and the order of the list
     * elements, may differ from what is stored — neither should count as a
     * "change". To make `===` exact we:
     *   1. recursively ksort every associative array (stable key order), then
     *   2. sort list elements by their canonical JSON encoding (stable element
     *      order).
     *
     * Accepts the casted array (from $user->company_accesses) or the raw request
     * input (array or null); a null/non-array value normalises to [].
     */
    private function normalizeAccesses(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalize = function (mixed $node) use (&$normalize) {
            if (!is_array($node)) {
                return $node;
            }

            $node = array_map($normalize, $node);

            if (array_is_list($node)) {
                // Stable ordering for list elements via canonical encoding.
                usort($node, fn ($a, $b) => json_encode($a) <=> json_encode($b));
            } else {
                ksort($node);
            }

            return $node;
        };

        return (array) $normalize($value);
    }

    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ($user->id === $currentUser->id) {
            return response()->json(['message' => __('users.cannot_delete_self')], 403);
        }

        if ($currentUser->role === 'superadmin') {
            // Может удалить любого, КРОМЕ другого суперадмина. Self-delete уже
            // отсечён выше (cannot_delete_self), так что здесь target — всегда
            // другой пользователь.
            if ($user->role === 'superadmin') {
                return response()->json(
                    ['message' => __('users.cannot_delete_superadmin')],
                    403
                );
            }
        } elseif ($currentUser->role === 'admin' && $user->company_id === $currentUser->company_id) {
            // админ не может удалять суперадминов
            if ($user->role === 'superadmin') {
                return response()->json(['message' => __('auth.forbidden')], 403);
            }
        } else {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $user->delete();

        return response()->json(['message' => __('users.deleted')]);
    }

    public function profile(Request $request)
    {
        return $request->user()->load('company', 'activeCompany');
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'locale' => 'sometimes|string|max:5',
            'password' => 'sometimes|string|min:8',
        ]);

        $user->update($data);

        return $user;
    }

    /**
     * Set the current user's "home" page — a relative frontend router path the
     * SPA redirects to after login. Any authenticated role may set their own.
     *
     * Open-redirect hardening: the value is stored verbatim and later used by
     * the SPA to navigate, so it MUST be a same-origin relative path. We reject
     * anything that is not a single-leading-slash path:
     *   - must start with exactly one "/" (relative path, not "//evil.com")
     *   - must NOT be a protocol-relative ("//…") or absolute URL ("http://…")
     *   - only a conservative whitelist of path characters (alnum / - _ . ~ /
     *     and query/anchor separators) — no whitespace or scheme/host chars.
     */
    public function updateHomePath(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            // ^/        single leading slash (relative, same-origin)
            // (?!/)     next char is NOT another slash → blocks "//evil.com"
            // remainder restricted to safe router-path characters only
            'path' => ['required', 'string', 'max:255', 'regex:#^/(?!/)[A-Za-z0-9\-_/.~?=&%]*$#'],
        ]);

        $user->update(['home_path' => $data['path']]);

        return response()->json(['home_path' => $user->home_path]);
    }

    public function iframeLink(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ($currentUser->role !== 'superadmin' || $user->role === 'superadmin') {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if (!$user->iframe_token) {
            return response()->json(['iframe_url' => null]);
        }

        return response()->json([
            'iframe_url' => config('app.url') . '?token=' . $user->iframe_token,
        ]);
    }

    public function regenerateIframeLink(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ($currentUser->role !== 'superadmin' || $user->role === 'superadmin') {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $user->iframe_token = hash('sha256', Str::random(128));
        $user->save();

        return response()->json([
            'iframe_url' => config('app.url') . '?token=' . $user->iframe_token,
        ]);
    }
}
