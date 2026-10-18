<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-180), tahap expand: absensi karyawan lewat login. `Karyawan.IkutAbsensi` menandai siapa yang absen (manajer,
 * supervisor, pemilik tidak); `AturanKehadiran` mendapat saklar simpan foto bukti dan tolak-di-luar-radius; `Absensi`
 * mendapat tanda "perlu dicek" untuk lokasi yang janggal. Bawaan menjaga perilaku lama: semua karyawan ikut absensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->boolean('IkutAbsensi')->default(true)->after('Status');
        });

        // Data lama: absen sekarang lewat login beremail, jadi karyawan tanpa akun beremail dan orang manajemen (pemilik,
        // admin, akuntan, manajer outlet, supervisor) tidak ikut absensi. Sisanya tetap ikut.
        DB::table('Karyawan')->whereNull('IdPengguna')->update(['IkutAbsensi' => false]);
        DB::table('Karyawan')
            ->whereIn('IdPengguna', DB::table('Pengguna')->whereNull('Email')->select('Id'))
            ->update(['IkutAbsensi' => false]);
        DB::table('Karyawan')
            ->whereIn('IdPengguna', DB::table('TenantPengguna')
                ->join('Peran', 'Peran.Id', '=', 'TenantPengguna.IdPeran')
                ->where(fn ($kueri) => $kueri->where('TenantPengguna.Pemilik', true)
                    ->orWhereIn('Peran.Kode', ['Pemilik', 'Admin', 'Akuntan', 'ManajerOutlet', 'Supervisor']))
                ->select('TenantPengguna.IdPengguna'))
            ->update(['IkutAbsensi' => false]);

        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->boolean('SimpanFotoBukti')->default(true);
            $tabel->boolean('TolakDiLuarRadius')->default(false);
        });

        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->boolean('PerluDicek')->default(false);
            $tabel->string('CatatanPeriksa', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->dropColumn(['PerluDicek', 'CatatanPeriksa']);
        });

        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->dropColumn(['SimpanFotoBukti', 'TolakDiLuarRadius']);
        });

        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->dropColumn('IkutAbsensi');
        });
    }
};
