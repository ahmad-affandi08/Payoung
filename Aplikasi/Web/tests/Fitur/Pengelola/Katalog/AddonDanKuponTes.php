<?php

declare(strict_types=1);

use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisKupon;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\KuponLangganan;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

function MasukSebagaiKatalog(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

beforeEach(function (): void {
    // Waktu dibekukan agar tanggal di test tidak kedaluwarsa seiring waktu (BR tanggal berlaku tidak boleh lewat).
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanKatalogBawaan::class)->Jalankan();
});

describe('Add-on (P-04)', function (): void {
    it('Keuangan menambah add-on tambahan batas dan add-on fitur, lalu mengarsipkannya', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        MasukSebagaiKatalog($this, $keuangan);

        $this->post(BantuanPengelola::Url('/katalog/add-on'), [
            'Kode' => 'OUTLET_UJI', 'Nama' => 'Outlet tambahan', 'HargaBulanan' => '99000',
            'KunciFitur' => null, 'TambahanBatas' => ['BatasOutlet' => 1], 'Status' => 'Aktif',
        ])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/katalog/add-on'), [
            'Kode' => 'PESAN_MANDIRI_UJI', 'Nama' => 'Self-order QR', 'HargaBulanan' => '49000',
            'KunciFitur' => 'kanal.self-order', 'TambahanBatas' => [], 'Status' => 'Aktif',
        ])->assertSessionHasNoErrors();
        $this->put(BantuanPengelola::Url('/katalog/add-on/PESAN_MANDIRI_UJI'), [
            'Kode' => 'PESAN_MANDIRI_UJI', 'Nama' => 'Self-order QR', 'HargaBulanan' => '59000',
            'KunciFitur' => 'kanal.self-order', 'TambahanBatas' => [], 'Status' => 'Diarsipkan',
        ])->assertSessionHasNoErrors();

        expect(Addon::query()->where('Kode', 'OUTLET_UJI')->sole()->TambahanBatas)->toBe(['BatasOutlet' => 1])
            ->and(Addon::query()->where('Kode', 'PESAN_MANDIRI_UJI')->sole()->Status)->toBe(StatusPaket::Diarsipkan);
        $log = LogAuditPengelola::query()->where('Aksi', 'katalog.addon.ubah')->sole();
        expect($log->NilaiLama['HargaBulanan'] ?? null)->toBe('49000.00')->and($log->NilaiBaru['HargaBulanan'] ?? null)->toBe('59000.00');
    });

    it('menolak add-on kosong, fitur tidak dikenal, harga negatif', function (array $data, string $bidang): void {
        MasukSebagaiKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan))
            ->post(BantuanPengelola::Url('/katalog/add-on'), [
                'Kode' => 'UJI', 'Nama' => 'Uji', 'HargaBulanan' => '1000', 'KunciFitur' => null, 'TambahanBatas' => [], 'Status' => 'Aktif', ...$data,
            ])
            ->assertSessionHasErrors($bidang);
    })->with([
        'kosong' => [[], 'KunciFitur'],
        'fitur tidak dikenal' => [['KunciFitur' => 'pos.terbang'], 'KunciFitur'],
        'harga negatif' => [['HargaBulanan' => '-1', 'TambahanBatas' => ['BatasOutlet' => 1]], 'HargaBulanan'],
    ]);

    it('izin: Dukungan hanya melihat add-on', function (): void {
        MasukSebagaiKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan));

        $this->get(BantuanPengelola::Url('/katalog/add-on'))->assertOk();
        $this->post(BantuanPengelola::Url('/katalog/add-on'), [
            'Kode' => 'UJI', 'Nama' => 'Uji', 'HargaBulanan' => '1000', 'TambahanBatas' => ['BatasOutlet' => 1], 'Status' => 'Aktif',
        ])->assertForbidden();
    });
});

describe('Kupon langganan (P-04)', function (): void {
    it('membuat kupon persen untuk paket tertentu dan kupon nominal untuk semua paket', function (): void {
        MasukSebagaiKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan));

        $this->post(BantuanPengelola::Url('/katalog/kupon'), [
            'Kode' => 'hemat50', 'Jenis' => 'Persen', 'Nilai' => '50', 'DurasiBulan' => 3, 'Kuota' => 100,
            'DaftarKodePaket' => ['STARTER', 'PRO'], 'BerlakuSampai' => '2026-12-31', 'Aktif' => true,
        ])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/katalog/kupon'), [
            'Kode' => 'POTONG-50RB', 'Jenis' => 'Nominal', 'Nilai' => '50000', 'DurasiBulan' => 1, 'Kuota' => null,
            'DaftarKodePaket' => [], 'BerlakuSampai' => null, 'Aktif' => true,
        ])->assertSessionHasNoErrors();

        $persen = KuponLangganan::query()->where('Kode', 'HEMAT50')->sole();
        expect($persen->Jenis)->toBe(JenisKupon::Persen)
            ->and($persen->Nilai)->toBe('50.00')
            ->and($persen->DaftarKodePaket)->toBe(['STARTER', 'PRO'])
            ->and(KuponLangganan::query()->where('Kode', 'POTONG-50RB')->sole()->DaftarKodePaket)->toBeNull();
    });

    it('menolak persen di luar 0–100, nominal nol, paket tidak dikenal, dan tanggal lewat', function (array $data, string $bidang): void {
        MasukSebagaiKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan))
            ->post(BantuanPengelola::Url('/katalog/kupon'), [
                'Kode' => 'UJI', 'Jenis' => 'Persen', 'Nilai' => '10', 'DurasiBulan' => 1, 'Aktif' => true, ...$data,
            ])
            ->assertSessionHasErrors($bidang);
    })->with([
        'persen 150' => [['Nilai' => '150'], 'Nilai'],
        'nominal nol' => [['Jenis' => 'Nominal', 'Nilai' => '0'], 'Nilai'],
        'paket tidak dikenal' => [['DaftarKodePaket' => ['PLATINUM']], 'DaftarKodePaket'],
        'tanggal lewat' => [['BerlakuSampai' => '2020-01-01'], 'BerlakuSampai'],
        'kode tidak valid' => [['Kode' => 'a b'], 'Kode'],
    ]);

    it('kupon dinonaktifkan, bukan dihapus. Kode tidak bisa diubah', function (): void {
        MasukSebagaiKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $data = ['Kode' => 'HEMAT10', 'Jenis' => 'Persen', 'Nilai' => '10', 'DurasiBulan' => 1, 'Aktif' => true];
        $this->post(BantuanPengelola::Url('/katalog/kupon'), $data);

        $this->put(BantuanPengelola::Url('/katalog/kupon/HEMAT10'), [...$data, 'Aktif' => false])->assertSessionHasNoErrors();
        $this->put(BantuanPengelola::Url('/katalog/kupon/HEMAT10'), [...$data, 'Kode' => 'HEMAT20'])->assertSessionHasErrors('Kode');

        expect(KuponLangganan::query()->where('Kode', 'HEMAT10')->sole()->Aktif)->toBeFalse();
    });
    it('kupon yang sudah kedaluwarsa tetap bisa dinonaktifkan, dan perubahannya tercatat di audit', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        MasukSebagaiKatalog($this, $keuangan);
        $data = ['Kode' => 'AKHIR-TAHUN', 'Jenis' => 'Persen', 'Nilai' => '10', 'DurasiBulan' => 1, 'BerlakuSampai' => '2026-12-31', 'Aktif' => true];
        $this->post(BantuanPengelola::Url('/katalog/kupon'), $data)->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2027-02-01 09:00', 'Asia/Jakarta'));
        MasukSebagaiKatalog($this, $keuangan)->put(BantuanPengelola::Url('/katalog/kupon/AKHIR-TAHUN'), [...$data, 'Aktif' => false])->assertSessionHasNoErrors();

        expect(KuponLangganan::query()->where('Kode', 'AKHIR-TAHUN')->sole()->Aktif)->toBeFalse();
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'katalog.kupon.ubah']);
    });
});
