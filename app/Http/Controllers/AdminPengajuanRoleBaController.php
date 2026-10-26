<?php

namespace App\Http\Controllers;

use App\Models\pengajuanAksesUser;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminPengajuanRoleBaController extends Controller
{
    public function index()
    {
        
        $data = pengajuanAksesUser::where('site', 'BA')->get();
        return Inertia::render('Guest/SiteBa/AksesRoleIndex', ['dataUsers' => $data]);
    }

    public function edit($id)
    {
        $data = pengajuanAksesUser::find($id);
        // dd($data);
        return Inertia::render('Guest/SiteBa/AksesRoleEdit', [
            'dataUsers' => $data
        ]);
    }

    public function update(Request $request)
    {
        $data_pengajuan = [
            'status' => 'DONE'
        ];

        $data_role_user = [
            'role' => $request->status
        ];

        pengajuanAksesUser::firstWhere('id', $request->id)->update($data_pengajuan);
        User::firstWhere('id', $request->id_user)->update($data_role_user);
        return redirect()->route('akses.page');
    }
}
