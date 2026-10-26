<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Satu penjualan di-void dan satu penjualan diretur sebagian (tenant bawaan `Siapkan`).
 *
 * @return array{0: array<string, mixed>, 1: array{Jenis: string, Uuid: string, Data: array<string, mixed>}, 2: array{Jenis: string, Uuid: string, Data: array<string, mixed>}, 3: string, 4: string}
 */
function SiapkanVoidRetur(mixed $tes, string $namaUsaha = 'Toko Kelontong Berkah Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $divoid = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
    $asal = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '4', 'Harga' => '38500.00']]]);
    $void = BantuanPenjualan::ItemVoid($k, $divoid, ['Alasan' => 'Pelanggan membatalkan setelah bayar tunai']);
    $retur = BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $asal->Detail()->firstOrFail(), 'Jumlah' => '1', 'Kondisi' => 'Rusak']]);

    if (BantuanKasir::KirimRingkas($tes, $k['Token'], [$void, $retur]) !== [['Diterima', null], ['Diterima', null]]) {
        throw new RuntimeException('Void/retur uji gagal.');
    }

    return [$k, $void, $retur, $divoid->Uuid, $asal->Uuid];
}

describe('F-09 halaman back-office void & retur', function (): void {
    it('daftar Void & Retur (TabelData): jenis, nomor, kasir, penyetuju, nominal, alasan, jeda sejak bayar. Cari, filter jenis/tanggal, urut nominal; JSON {Data, Meta}', function (): void {
        [$k, $void, $retur, $uuidDivoid, $uuidAsal] = SiapkanVoidRetur($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->get('/kelola/penjualan/void-retur')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penjualan/VoidRetur')
            ->where('VoidRetur.Meta.Total', 2)
            ->has('OpsiOutlet', 1));

        $json = fn (string $query) => $this->getJson('/kelola/penjualan/void-retur'.$query)->assertOk();
        $semua = collect($json('?urut=Nominal')->json('Data'));
        $barisVoid = $semua->firstWhere('Jenis', 'Void');
        $barisRetur = $semua->firstWhere('Jenis', 'Retur');

        expect($semua->pluck('Jenis')->all())->toBe(['Retur', 'Void'])
            ->and($barisVoid)->toMatchArray([
                'Uuid' => $void['Uuid'],
                'UuidPenjualan' => $uuidDivoid,
                'Tautan' => "/kelola/penjualan/{$uuidDivoid}",
                'NamaKasir' => $k['Kasir']->Nama,
                'NamaPenyetuju' => $k['Supervisor']->Nama,
                'Nominal' => '77000.00',
                'RefundTunai' => '77000.00',
                'Alasan' => 'Pelanggan membatalkan setelah bayar tunai',
            ])
            ->and($barisVoid['JedaDetik'])->toBeGreaterThanOrEqual(0)
            ->and($barisRetur)->toMatchArray([
                'Uuid' => $retur['Uuid'],
                'Nomor' => $retur['Data']['Nomor'],
                'UuidPenjualan' => $uuidAsal,
                'Tautan' => "/kelola/penjualan/retur/{$retur['Uuid']}",
                'Nominal' => '38500.00',
            ])
            ->and(array_column($json('?saring[Jenis]=Retur')->json('Data'), 'Jenis'))->toBe(['Retur'])
            ->and(array_column($json('?saring[Jenis]=Void')->json('Data'), 'Jenis'))->toBe(['Void'])
            ->and(array_column($json('?cari='.urlencode($retur['Data']['Nomor']))->json('Data'), 'Uuid'))->toBe([$retur['Uuid']])
            ->and($json('?cari='.urlencode($k['Kasir']->Nama))->json('Meta.Total'))->toBe(2)
            ->and($json('?saring[TanggalBisnis]=2000-01-01..2000-01-31')->json('Meta.Total'))->toBe(0)
            ->and($json('?urut=Id;DROP TABLE VoidPenjualan&saring[IdTenant]=1')->json('Meta.Total'))->toBe(2);
    });

    it('detail penjualan menampilkan void & retur. Detail retur menampilkan baris, refund, mutasi stok ke lokasi Toko, jurnal, dan penjualan asal', function (): void {
        [$k, $void, $retur, $uuidDivoid, $uuidAsal] = SiapkanVoidRetur($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);

        $this->get("/kelola/penjualan/{$uuidDivoid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penjualan/Detail')
            ->where('Penjualan.Status', 'Void')
            ->where('Void.Uuid', $void['Uuid'])
            ->where('Void.NamaPenyetuju', $k['Supervisor']->Nama)
            ->where('Void.RefundTunai', '77000.00')
            ->has('MutasiStok', 2)
            ->has('Jurnal', 2)
            ->where('Retur', []));

        $this->get("/kelola/penjualan/{$uuidAsal}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Penjualan.Status', 'DireturSebagian')
            ->where('Penjualan.LabelStatus', 'Diretur sebagian')
            ->where('Void', null)
            ->where('Baris.0.JumlahDiretur', '1.0000')
            ->where('Retur.0.Uuid', $retur['Uuid'])
            ->where('Retur.0.TotalRefund', '38500.00'));

        $this->get("/kelola/penjualan/retur/{$retur['Uuid']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penjualan/Retur')
            ->where('Retur.Nomor', $retur['Data']['Nomor'])
            ->where('Retur.UuidPenjualan', $uuidAsal)
            ->where('Retur.NamaPenyetuju', $k['Supervisor']->Nama)
            ->where('Retur.TotalRefund', '38500.00')
            ->where('Retur.PerluTinjauan', true)
            ->where('Retur.UuidShift', $k['UuidShift'])
            ->where('Baris.0.Kondisi', 'Rusak')
            ->where('Baris.0.Jumlah', '1.0000')
            ->where('Refund.0.Jumlah', '38500.00')
            ->where('MutasiStok.0.Jumlah', '1.0000')
            ->where('MutasiStok.0.TautanKartuStok', fn (?string $t): bool => is_string($t) && str_contains($t, '/kelola/persediaan/kartu-stok?'))
            ->where('Jurnal.0.Nomor', fn (?string $n): bool => is_string($n) && str_starts_with($n, 'JU/')));

        $this->get('/kelola/penjualan/retur/'.BantuanKasir::Uuid())->assertNotFound();
    });

    it('izin laporan.penjualan.lihat (Kasir & Supervisor 403). Pengguna per outlet hanya melihat void/retur outletnya. Tenant lain 404', function (): void {
        [$k, , $retur] = SiapkanVoidRetur($this);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/penjualan/void-retur')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get("/kelola/penjualan/retur/{$retur['Uuid']}")->assertForbidden();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanOrganisasi::Masuk($this, $manajerCabang, $k['Tenant']->Id);
        $this->getJson('/kelola/penjualan/void-retur')->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->get("/kelola/penjualan/retur/{$retur['Uuid']}")->assertNotFound();

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/penjualan/retur/{$retur['Uuid']}")->assertNotFound();
        $this->getJson('/kelola/penjualan/void-retur')->assertOk()->assertJsonPath('Meta.Total', 0);
    });

    it('pengaturan kasir BatasHariRetur: bawaan 7, tersimpan & tercatat, di luar 0–365 ditolak, diteruskan ke data-awal', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);

        $this->get('/kelola/kasir/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('BatasHariRetur', 7));
        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Pengaturan.BatasHariRetur'))->toBe(7);

        $isi = ['BatasKasKeluar' => '200000', 'ShiftBersama' => false];
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasHariRetur' => 366])->assertSessionHasErrors('BatasHariRetur');
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasHariRetur' => -1])->assertSessionHasErrors('BatasHariRetur');
        $this->put('/kelola/kasir/pengaturan', $isi + ['BatasHariRetur' => 14])->assertRedirect('/kelola/kasir/pengaturan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->batasHariRetur)->toBe(14)
            ->and($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Pengaturan.BatasHariRetur'))->toBe(14);

        // Bidang yang tidak dikirim dipertahankan.
        $this->put('/kelola/kasir/pengaturan', $isi)->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(app(PengaturanKasirTenant::class)->Ambil()->batasHariRetur)->toBe(14);
    });
});
