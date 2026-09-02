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

class PasswordBankAllSiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'kategori' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $passwords = PasswordBank::query()
            ->where('site', 'all-sites')
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
            'data' => $this->formatPassword($this->findAllSitePassword($passwordBank), $request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $password = DB::transaction(function () use ($validated, $request) {
            return PasswordBank::create([
                ...$validated,
                'password' => Crypt::encryptString($validated['password']),
                'site' => 'all-sites',
                'nama_pembuat' => 'site '.($request->user()->site ?? '-'),
                'user_id' => $request->user()->getKey(),
                'role_owner' => 'all',
            ]);
        });

        return response()->json([
            'message' => 'Data password all site berhasil dibuat.',
            'data' => $this->formatPassword($password, $request),
        ], 201);
    }

    public function update(Request $request, int $passwordBank): JsonResponse
    {
        $password = $this->findAllSitePassword($passwordBank);
        $this->ensureOwner($request, $password);
        $validated = $request->validate($this->rules(true));

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Crypt::encryptString($validated['password']);
        }

        $password->update($validated);

        return response()->json([
            'message' => 'Data password all site berhasil diperbarui.',
            'data' => $this->formatPassword($password->fresh(), $request),
        ]);
    }

    public function destroy(Request $request, int $passwordBank): JsonResponse
    {
        $password = $this->findAllSitePassword($passwordBank);
        $this->ensureOwner($request, $password);
        $password->delete();

        return response()->json(['message' => 'Data password all site berhasil dihapus.']);
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
        ];
    }

    private function findAllSitePassword(int $id): PasswordBank
    {
        $password = PasswordBank::query()->where('site', 'all-sites')->find($id);

        if (! $password) {
            throw (new ModelNotFoundException)->setModel(PasswordBank::class, [$id]);
        }

        return $password;
    }

    private function ensureOwner(Request $request, PasswordBank $password): void
    {
        abort_unless((string) $password->user_id === (string) $request->user()->getKey(), 403, 'Hanya pembuat data yang dapat mengubah atau menghapusnya.');
    }

    private function formatPassword(PasswordBank $password, Request $request): array
    {
        try {
            $decryptedPassword = Crypt::decryptString($password->password);
            $decryptionFailed = false;
        } catch (\Throwable) {
            $decryptedPassword = null;
            $decryptionFailed = true;
        }

        return [
            'id' => $password->id,
            'title' => $password->title,
            'deskripsi' => $password->deskripsi,
            'kategori' => $password->kategori,
            'username' => $password->username,
            'password' => $decryptedPassword,
            'password_decryption_failed' => $decryptionFailed,
            'url' => $password->url,
            'remark' => $password->remark,
            'site' => $password->site,
            'nama_pembuat' => $password->nama_pembuat,
            'user_id' => $password->user_id,
            'role_owner' => $password->role_owner,
            'can_modify' => (string) $password->user_id === (string) $request->user()->getKey(),
            'created_at' => $password->created_at,
            'updated_at' => $password->updated_at,
        ];
    }
}
