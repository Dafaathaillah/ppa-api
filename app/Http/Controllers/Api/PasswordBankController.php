<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordBank;
use App\Models\PasswordCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PasswordBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'kategori' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $passwords = PasswordBank::query()
            ->where('site', $this->userSite($request))
            ->when($validated['kategori'] ?? null, fn ($query, $category) => $query->where('kategori', $category))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%")
                        ->orWhere('kategori', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (PasswordBank $password) => $this->formatPassword($password, $request));

        return response()->json($passwords);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => PasswordCategory::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, int $passwordBank): JsonResponse
    {
        return response()->json([
            'data' => $this->formatPassword($this->findSitePassword($request, $passwordBank), $request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $isAllRole = (bool) ($validated['is_all_role'] ?? false);
        $this->authorizeAllRoleSelection($request, $isAllRole);
        unset($validated['is_all_role']);

        $password = DB::transaction(fn () => PasswordBank::create([
            ...$validated,
            'password' => Crypt::encryptString($validated['password']),
            'site' => $this->userSite($request),
            'nama_pembuat' => $request->user()->name,
            'user_id' => $request->user()->getKey(),
            'role_owner' => $isAllRole ? 'all' : $request->user()->role,
        ]));

        return response()->json([
            'message' => 'Data password site berhasil dibuat.',
            'data' => $this->formatPassword($password, $request),
        ], 201);
    }

    public function update(Request $request, int $passwordBank): JsonResponse
    {
        $password = $this->findSitePassword($request, $passwordBank);
        $this->authorizeModification($request, $password);
        $validated = $request->validate($this->rules(true));

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Crypt::encryptString($validated['password']);
        }

        if (array_key_exists('is_all_role', $validated)) {
            $this->authorizeAllRoleSelection($request, (bool) $validated['is_all_role']);
            $password->role_owner = $validated['is_all_role']
                ? 'all'
                : ($password->role_owner === 'all' ? $request->user()->role : $password->role_owner);
            unset($validated['is_all_role']);
        }

        $password->fill($validated);
        $password->nama_pembuat = $request->user()->name;
        $password->save();

        return response()->json([
            'message' => 'Data password site berhasil diperbarui.',
            'data' => $this->formatPassword($password->fresh(), $request),
        ]);
    }

    public function destroy(Request $request, int $passwordBank): JsonResponse
    {
        $password = $this->findSitePassword($request, $passwordBank);
        $this->authorizeModification($request, $password);
        $password->delete();

        return response()->json(['message' => 'Data password site berhasil dihapus.']);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'title' => [...$required, 'string', 'max:255'],
            'kategori' => [...$required, 'string', 'max:255', Rule::exists('password_categories', 'name')],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => [...$required, 'string', 'max:10000'],
            'url' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'is_all_role' => ['sometimes', 'boolean'],
        ];
    }

    private function userSite(Request $request): string
    {
        $site = strtoupper(trim((string) $request->user()->site));
        abort_if($site === '' || $site === 'ALL-SITES', 422, 'Site user tidak valid untuk password bank per site.');

        return $site;
    }

    private function findSitePassword(Request $request, int $id): PasswordBank
    {
        $password = PasswordBank::query()
            ->where('site', $this->userSite($request))
            ->whereKey($id)
            ->first();

        if (! $password) {
            throw (new ModelNotFoundException)->setModel(PasswordBank::class, [$id]);
        }

        return $password;
    }

    private function authorizeModification(Request $request, PasswordBank $password): void
    {
        $role = $request->user()->role;

        abort_if(
            in_array($role, ['ict_admin', 'ict_technician'], true)
                && in_array($password->role_owner, ['ict_group_leader', 'ict_developer'], true),
            403,
            'Akses ditolak: Tidak dapat mengubah data pimpinan.'
        );

        abort_if(
            $password->role_owner === 'all' && $role !== 'ict_group_leader',
            403,
            'Akses ditolak: Hanya Group Leader yang dapat mengubah data ini.'
        );
    }

    private function authorizeAllRoleSelection(Request $request, bool $isAllRole): void
    {
        abort_if(
            $isAllRole && $request->user()->role !== 'ict_group_leader',
            403,
            'Hanya Group Leader yang dapat membuat password untuk seluruh role.'
        );
    }

    private function formatPassword(PasswordBank $password, Request $request): array
    {
        $role = $request->user()->role;
        $restricted = in_array($role, ['ict_admin', 'ict_technician'], true)
            && in_array($password->role_owner, ['ict_group_leader', 'ict_developer'], true);
        $decryptionFailed = false;

        if ($restricted) {
            $plainPassword = null;
        } else {
            try {
                $plainPassword = Crypt::decryptString($password->password);
            } catch (\Throwable) {
                $plainPassword = null;
                $decryptionFailed = true;
            }
        }

        return [
            'id' => $password->id,
            'title' => $password->title,
            'deskripsi' => $password->deskripsi,
            'kategori' => $password->kategori,
            'username' => $password->username,
            'password' => $plainPassword,
            'password_restricted' => $restricted,
            'password_decryption_failed' => $decryptionFailed,
            'url' => $password->url,
            'remark' => $password->remark,
            'site' => $password->site,
            'nama_pembuat' => $password->nama_pembuat,
            'user_id' => $password->user_id,
            'role_owner' => $password->role_owner,
            'can_modify' => ! ($restricted || ($password->role_owner === 'all' && $role !== 'ict_group_leader')),
            'created_at' => $password->created_at,
            'updated_at' => $password->updated_at,
        ];
    }
}
