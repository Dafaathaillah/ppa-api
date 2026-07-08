<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Aduan;
use App\Models\InvComputer;
use App\Models\InvLaptop;
use App\Models\pengajuanAksesUser;
use App\Models\UserAll;
use App\Services\ImageOptimizerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile guest flow — 1:1 parity with the web guest controllers
 * (GuestAllController + GuestReportController). Everything is scoped to the
 * authenticated user (their nrp / UserAll id), so these endpoints are safe for
 * any authenticated caller and never expose another user's data. No new tables,
 * no new business flow: pengajuan-akses writes to pengajuan_akses_user (read by
 * the existing Admin Pengajuan Akses menu) and complaints write to aduans.
 */
class GuestApiController extends Controller
{
    /**
     * Guest dashboard: the user's OWN laptop + computer inventory merged into a
     * single list, plus the flag telling whether they already submitted a role
     * access request. Mirrors GuestAllController@index.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $userAll = UserAll::where('nrp', $user->nrp)->first();

        $invs = [];

        if ($userAll) {
            foreach (InvLaptop::where('user_alls_id', $userAll->id)->get() as $l) {
                $invs[] = [
                    'id'              => $l->id,
                    'no_inv'          => $l->laptop_code,
                    'asset_ho_number' => $l->number_asset_ho,
                    'jenis'           => 'Laptop',
                    'brand'           => $l->laptop_name,
                    'spek'            => $l->spesifikasi,
                    'sn'              => $l->serial_number,
                    'status'          => $l->status,
                    'kondisi'         => $l->condition,
                ];
            }

            foreach (InvComputer::where('user_alls_id', $userAll->id)->get() as $k) {
                $invs[] = [
                    'id'              => $k->id,
                    'no_inv'          => $k->computer_code,
                    'asset_ho_number' => $k->number_asset_ho,
                    'jenis'           => 'Komputer',
                    'brand'           => $k->computer_name,
                    'spek'            => $k->spesifikasi,
                    'sn'              => $k->serial_number,
                    'status'          => $k->status,
                    'kondisi'         => $k->condition,
                ];
            }
        }

        $existing = pengajuanAksesUser::where('nrp_user', $user->nrp)->first();

        return response()->json([
            'invs'            => $invs,
            'data_pengajuan'  => $existing ? 'Y' : 'N',
            'pengajuan_status' => $existing?->status,
        ]);
    }

    /**
     * Submit a role-access request. Mirrors GuestAllController@pengajuanAkses:
     * idempotent (does nothing if a request already exists) and writes into the
     * SAME pengajuan_akses_user table the Admin menu already manages.
     */
    public function pengajuanAkses(Request $request)
    {
        $user = $request->user();

        $existing = pengajuanAksesUser::where('nrp_user', $user->nrp)->first();

        if (! $existing) {
            $existing = pengajuanAksesUser::create([
                'id_user'   => $user->id,
                'nrp_user'  => $user->nrp,
                'nama_user' => $user->name,
                'divisi'    => $user->position,
                'dept'      => $user->department,
                'site'      => $user->site,
                'status'    => 'BELUM',
            ]);
        }

        return response()->json([
            'message'        => 'Pengajuan akses tersimpan.',
            'data_pengajuan' => 'Y',
            'data'           => $existing,
        ], 201);
    }

    /**
     * The user's OWN complaints. Mirrors GuestReportController@index
     * (Aduan::where('nrp', auth nrp)).
     */
    public function complaints(Request $request)
    {
        $user = $request->user();

        $aduan = Aduan::query()
            ->whereNull('deleted_at')
            ->where('nrp', $user->nrp)
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('complaint_code', 'like', '%' . $request->string('search') . '%');
            })
            ->orderByDesc('date_of_complaint')
            ->get();

        return response()->json(['data' => $aduan]);
    }

    /**
     * Submit a satisfaction rating (1–5 stars) for one of the guest's OWN
     * complaints once it is CLOSED. Mirrors the web GuestReportController flow
     * (unratedClosed + storeRating) and reuses the existing `user_rating` column
     * — no schema change, no new business flow.
     *
     * Scoped hard to the caller (Aduan.nrp === auth nrp) so a guest can never
     * rate someone else's ticket. Persisted with saveQuietly() so the rating
     * write does NOT trip the AduanObserver and fire a spurious "Update Aduan"
     * push back at the rater.
     */
    public function rateComplaint(Request $request, string $id)
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $user = $request->user();

        $aduan = Aduan::query()
            ->whereNull('deleted_at')
            ->where('nrp', $user->nrp)
            ->findOrFail($id);

        // Only CLOSED complaints are ratable, matching the web unratedClosed gate.
        if (strtoupper((string) $aduan->status) !== 'CLOSED') {
            return response()->json([
                'message' => 'Rating hanya dapat diberikan untuk complaint yang sudah Closed.',
            ], 422);
        }

        $aduan->user_rating = $validated['rating'];
        $aduan->saveQuietly();

        return response()->json([
            'message' => 'Rating tersimpan. Terima kasih atas penilaian Anda.',
            'data'    => $aduan,
        ]);
    }

    /**
     * Data needed to render the guest create-complaint form: auto-generated
     * ticket, the readonly reporter identity, and site-scoped categories.
     * Mirrors GuestReportController@create.
     */
    public function complaintMeta(Request $request)
    {
        $user = $request->user();
        $siteType = strtoupper((string) $user->site) === 'HO' ? 'HO' : 'SITE';

        $categories = DB::table('root_cause_categories')
            ->select('id', 'category_root_cause')
            ->where('site_type', $siteType)
            ->get();

        return response()->json([
            'ticket'     => $this->generateTicket($user->site),
            'nrp'        => $user->nrp,
            'nama'       => $user->name,
            'categories' => $categories,
        ]);
    }

    /**
     * Create a complaint for the guest. NRP + reporter name are taken from the
     * authenticated user (server-enforced readonly), matching the web guest form
     * where those fields are auto-filled and locked. Mirrors
     * GuestReportController@store.
     */
    public function storeComplaint(Request $request)
    {
        $validated = $request->validate([
            'complaint_note'   => ['required', 'string'],
            'phone_number'     => ['nullable', 'string'],
            'location'         => ['nullable', 'string'],
            'location_detail'  => ['nullable', 'string'],
            'category_name'    => ['nullable', 'string'],
            'inventory_number' => ['nullable', 'string'],
            'complaint_code'   => ['nullable', 'string'],
            'image'            => ['nullable', 'file', 'image'],
        ]);

        $user = $request->user();
        $site = $user->site;

        $maxId = ((int) Aduan::max('max_id')) + 1;
        $userAll = UserAll::where('nrp', $user->nrp)->first();

        $payload = [
            'max_id'             => $maxId,
            'nrp'                => $user->nrp,
            'complaint_name'     => $user->name ?: 'User Belum Terdaftar Pada Sistem (NRP Not Detect!)',
            'complaint_note'     => $validated['complaint_note'],
            'phone_number'       => $validated['phone_number'] ?? null,
            'date_of_complaint'  => Carbon::now('Asia/Ujung_Pandang')->format('Y-m-d H:i:s'),
            'created_date'       => Carbon::now()->format('Y-m-d'),
            'location'           => $validated['location'] ?? null,
            'detail_location'    => $validated['location_detail'] ?? null,
            'category_name'      => $validated['category_name'] ?? null,
            'urgency'            => 'NORMAL',
            'status'             => 'OPEN',
            'complaint_code'     => $validated['complaint_code'] ?? $this->generateTicket($site),
            'complaint_position' => $userAll?->position ?? 'User Belum Terdaftar Pada Sistem (NRP Not Detect!)',
            'site'               => $site,
            'site_pelapor'       => $site,
        ];

        if (! empty($validated['inventory_number'])) {
            $payload['inventory_number'] = $validated['inventory_number'];
        }

        if ($request->hasFile('image')) {
            $payload['complaint_image'] = url('storage/' . ImageOptimizerService::storeAndOptimize($request->file('image'), 'images'));
        }

        $aduan = Aduan::create($payload);

        return response()->json([
            'message' => 'Complaint created successfully.',
            'data'    => $aduan,
        ], 201);
    }

    /**
     * Ticket generator — parity with GuestReportController@create
     * (ADUAN-{yy}{m}{d}-{seq}, per-site daily sequence).
     */
    private function generateTicket(?string $site): string
    {
        $now = Carbon::now();
        $prefix = 'ADUAN-' . $now->format('y') . $now->month . $now->day . '-';

        $last = Aduan::whereDate('created_at', $now->format('Y-m-d'))
            ->where('site', $site)
            ->orderByDesc('max_id')
            ->first();

        $seq = 0;
        if ($last && $last->complaint_code) {
            $parts = explode('-', $last->complaint_code);
            $seq = (int) ($parts[2] ?? 0);
        }

        return $prefix . str_pad(($seq % 10000) + 1, 2, '0', STR_PAD_LEFT);
    }
}
