<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Aksi\SimpanTargetPenjualan;
use App\Domain\Karyawan\Enum\CakupanTargetPenjualan;
use App\Domain\Karyawan\Kueri\DaftarKaryawan;
use App\Domain\Karyawan\Kueri\ProgresTargetPenjualan;
use App\Domain\Karyawan\Model\TargetPenjualan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Target penjualan bulanan (F-18 bagian 3, `/kelola/karyawan/target`): lihat progres `karyawan.lihat`; simpan & hapus
 * target `karyawan.kelola`. Target outlet dibatasi outlet yang boleh diakses pengguna.
 */
final class TargetPenjualanKontroler extends DasarKelolaKontroler
{
    public function Tampil(Request $permintaan, ProgresTargetPenjualan $progres, DaftarKaryawan $karyawan, PetaUuidOutlet $outlet, AksesPengguna $akses): Response
    {
        $opsi = $progres->AmbilOpsiPeriode();
        $periode = $permintaan->string('periode')->toString();
        $periode = in_array($periode, array_column($opsi['Opsi'], 'Nilai'), true) ? $periode : $opsi['Berjalan'];

        return Inertia::render('Kelola/Karyawan/Target', [
            'Periode' => $periode,
            'Berjalan' => $periode === $opsi['Berjalan'],
            'OpsiPeriode' => $opsi['Opsi'],
            'Target' => $progres->Ambil($periode, $this->IdOutletBoleh()),
            'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh(), true)),
            'OpsiKaryawan' => $karyawan->AmbilPilihan($this->IdOutletBoleh()),
            'Izin' => ['Kelola' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::KaryawanKelola)],
        ]);
    }

    public function Simpan(Request $permintaan, SimpanTargetPenjualan $simpan): RedirectResponse
    {
        $data = $permintaan->validate([
            'Periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'Cakupan' => ['required', Rule::enum(CakupanTargetPenjualan::class)],
            'Sasaran' => ['required', 'string', 'size:26'],
            'Nilai' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ], [
            'Periode.*' => 'Pilih periode bulan.',
            'Cakupan.*' => 'Pilih target untuk outlet atau karyawan.',
            'Sasaran.*' => 'Pilih outlet atau karyawan.',
            'Nilai.*' => 'Isi target dalam rupiah, misal 50000000.',
        ]);
        $simpan->Jalankan($data['Periode'], CakupanTargetPenjualan::from($data['Cakupan']), $data['Sasaran'], Uang::Dari($data['Nilai']), $this->Pelaku()->Id, $this->IdOutletBoleh());

        return to_route('kelola.karyawan.target', ['periode' => $data['Periode']])->with('Kilat', 'Target penjualan disimpan.');
    }

    public function Hapus(string $target, SimpanTargetPenjualan $simpan): RedirectResponse
    {
        $model = TargetPenjualan::query()->where('Uuid', $target)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($model->IdOutlet !== null && $boleh !== null && ! in_array($model->IdOutlet, $boleh, true), 404);
        $simpan->Hapus($model);

        return to_route('kelola.karyawan.target', ['periode' => $model->Periode])->with('Kilat', 'Target penjualan dihapus.');
    }
}
