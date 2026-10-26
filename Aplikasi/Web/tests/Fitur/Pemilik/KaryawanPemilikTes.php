<?php

declare(strict_types=1);

use App\Domain\Karyawan\Enum\CakupanTargetPenjualan;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\TargetPenjualan;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * OWN-10: pantau karyawan di Aplikasi Pemilik — kehadiran hari ini (hadir, terlambat, belum masuk), komisi & target
 * bulan berjalan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Rabu 7 Oktober 2026 pukul 12.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

function TokenPemilikKaryawanUji(object $tes, Pengguna $pengguna): string
{
    $pengguna->forceFill(['EmailDiverifikasiPada' => now()])->save();
    $token = $tes->postJson('/api/pemilik/v1/masuk', ['Email' => $pengguna->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP Uji'])->assertOk()->json('Token');

    return is_string($token) ? $token : '';
}

it('kehadiran hari ini (terlambat, sedang bekerja, belum masuk dijadwalkan), target bulan berjalan', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Kedai Pantau');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $rina = Karyawan::query()->create(['Nama' => 'Rina', 'IdOutlet' => $k['Outlet']->Id]);
    $budi = Karyawan::query()->create(['Nama' => 'Budi', 'IdOutlet' => $k['Outlet']->Id]);
    $nonaktif = Karyawan::query()->create(['Nama' => 'Lama', 'IdOutlet' => $k['Outlet']->Id, 'Status' => 'Nonaktif']);

    foreach ([[$rina, '08:00'], [$budi, '09:00'], [$nonaktif, '09:00']] as [$orang, $jam]) {
        JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-07', 'JamMulai' => $jam, 'JamSelesai' => '16:00']);
    }

    // Rina masuk 08.20 WIB (terlambat 20 menit), belum keluar.
    Absensi::query()->create([
        'Uuid' => (string) Str::ulid(), 'IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'IdPerangkat' => null,
        'TanggalBisnis' => '2026-10-07', 'MasukPada' => CarbonImmutable::parse('2026-10-07 01:20:00', 'UTC'), 'Sumber' => Absensi::SUMBER_WEB,
    ]);
    TargetPenjualan::query()->create(['Periode' => '2026-10', 'Cakupan' => CakupanTargetPenjualan::Outlet, 'IdOutlet' => $k['Outlet']->Id, 'KunciSasaran' => 'Outlet:'.$k['Outlet']->Id, 'Nilai' => '10000000.00']);

    $token = TokenPemilikKaryawanUji($this, $k['Pemilik']);
    $this->withToken($token)->withHeaders(['X-Tenant' => $k['Tenant']->Uuid, 'X-Versi-Aplikasi' => '1.0.0'])
        ->getJson('/api/pemilik/v1/karyawan')
        ->assertOk()
        ->assertJsonPath('Tanggal', '2026-10-07')
        ->assertJsonPath('Periode', '2026-10')
        ->assertJsonPath('Ringkasan', ['Hadir' => 1, 'SedangBekerja' => 1, 'Terlambat' => 1, 'BelumMasuk' => 1])
        ->assertJsonPath('Kehadiran.0.NamaKaryawan', 'Rina')
        ->assertJsonPath('Kehadiran.0.JamMasuk', '08:20')
        ->assertJsonPath('Kehadiran.0.JamKeluar', null)
        ->assertJsonPath('Kehadiran.0.TerlambatMenit', 20)
        ->assertJsonPath('Kehadiran.0.Sumber', 'Web')
        ->assertJsonPath('BelumMasuk', [['NamaKaryawan' => 'Budi', 'NamaOutlet' => $k['Outlet']->Nama, 'Jadwal' => '09:00–16:00']])
        ->assertJsonPath('Komisi', [])
        ->assertJsonPath('Target.0.NamaSasaran', $k['Outlet']->Nama)
        ->assertJsonPath('Target.0.Nilai', '10000000.00');

    // Tenant lain (bukan milik pengguna) = 403.
    $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Pantau');
    $this->withToken($token)->withHeaders(['X-Tenant' => $lain['Tenant']->Uuid, 'X-Versi-Aplikasi' => '1.0.0'])->getJson('/api/pemilik/v1/karyawan')->assertForbidden();
});

it('OWN-11 insight mingguan: minggu lalu vs sebelumnya untuk Aplikasi Pemilik, tanpa penjualan = null', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($this, 'Toko Insight Owner');
    BantuanFitur::Beri($k['Tenant']->Id, 'laporan.insight');
    $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Pandan Wangi 5 kg', '40', '60000', '75000.00');
    $token = TokenPemilikKaryawanUji($this, $k['Pemilik']);
    $ambil = fn () => $this->withToken($token)->withHeaders(['X-Tenant' => $k['Tenant']->Uuid, 'X-Versi-Aplikasi' => '1.0.0'])->getJson('/api/pemilik/v1/insight');

    $ambil()->assertOk()->assertJsonPath('Insight', null);

    // Minggu 21–27 Sep: 2 karung; minggu 28 Sep–4 Okt: 4 karung.
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '2', 'Harga' => '75000.00']]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-29 05:00:00', 'UTC'));
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '4', 'Harga' => '75000.00']]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
    $ambil()->assertOk()
        ->assertJsonPath('Insight.Dari', '2026-09-28')
        ->assertJsonPath('Insight.Sampai', '2026-10-04')
        ->assertJsonPath('Insight.Bersih', '300000.00')
        ->assertJsonPath('Insight.BersihSebelumnya', '150000.00')
        ->assertJsonPath('Insight.PersenPerubahan', '100.0')
        ->assertJsonPath('Insight.Terlaris.0.NamaProduk', 'Beras Pandan Wangi 5 kg');
});
