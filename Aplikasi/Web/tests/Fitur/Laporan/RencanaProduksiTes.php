<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\StatusPesananPenjualan;
use App\Domain\Penjualan\Model\PesananPenjualan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * §9.11 Bakery: rencana produksi harian (tab "Rencana produksi" di laporan stok). Permintaan = rata-rata pemakaian pada
 * hari yang sama dalam 4 minggu terakhir vs pre-order yang diambil pada tanggal itu; rekomendasi = kebutuhan − saldo.
 * Hanya produk jenis Produksi. "Buat draf produksi" membuka formulir order produksi yang sudah terisi.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant bakery: roti Produksi (stok awal 25) terjual Jumat 25 Sep 8, 2 Okt 6, 9 Okt 10 (18 Sep tanpa penjualan) → saldo 1.
 * Roti Stok biasa ikut terjual supaya terbukti tidak masuk rencana. Waktu akhir: Rabu 14 Okt 2026 pukul 10.00 WIB.
 *
 * @return array<string, mixed>
 */
function SiapkanBakeryRencana(mixed $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Bakery Rencana Klaten');
    $roti = BantuanKatalog::BuatProduk(['Nama' => 'Roti Tawar Gandum Utuh', 'Jenis' => JenisProduk::Produksi], '15000.00');
    BantuanPenjualan::IsiStok($k['Gudang'], $roti, '25', '8000', $k['Pemilik']->Id);
    $biasa = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Air Mineral Botol 600 ml', '50', '2000', '4000.00');

    foreach ([['2026-09-25', '8'], ['2026-10-02', '6'], ['2026-10-09', '10']] as [$tanggal, $jumlah]) {
        $tes->travelTo(CarbonImmutable::parse("{$tanggal} 10:00:00", 'Asia/Jakarta'));
        BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $roti, 'Jumlah' => $jumlah, 'Harga' => '15000.00']]]);
        BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $biasa, 'Jumlah' => '2', 'Harga' => '4000.00']]]);
    }

    $tes->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));

    return [...$k, 'Roti' => $roti, 'Biasa' => $biasa];
}

/** Pre-order `$jumlah` roti yang diambil `$tanggalAmbil`, dibuat kasir offline lewat outbox. */
function BuatPreOrderRoti(mixed $tes, array $k, string $jumlah, string $tanggalAmbil): void
{
    $pelanggan = Pelanggan::query()->firstOrCreate(['NoHp' => '6281355551234'], ['Nama' => 'Ibu Wati Pemesan']);
    $waktu = CarbonImmutable::now()->subMinutes(5);
    $item = [
        'Jenis' => 'PesananPenjualan.Buat',
        'Uuid' => BantuanKasir::Uuid(),
        'Data' => [
            'UuidShift' => $k['UuidShift'],
            'UuidPengguna' => $k['Kasir']->Uuid,
            'UuidPelanggan' => $pelanggan->Uuid,
            'Nomor' => str_replace('INV/', 'SO/', BantuanPenjualan::Nomor($k, null, $waktu)),
            'DipesanPada' => $waktu->utc()->toIso8601ZuluString(),
            'TanggalAmbil' => $tanggalAmbil,
            'Catatan' => null,
            'TotalPesanan' => number_format((float) $jumlah * 15000, 2, '.', ''),
            'Baris' => [[
                'Uuid' => BantuanKasir::Uuid(),
                'UuidProduk' => $k['Roti']->Uuid,
                'UuidProdukSatuan' => null,
                'Jumlah' => $jumlah,
                'HargaSatuan' => '15000.00',
                'HargaPilihan' => '0.00',
                'Pilihan' => [],
                'Catatan' => null,
            ]],
            'Pembayaran' => [['Uuid' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => $k['Tunai']->Uuid, 'Jumlah' => '50000.00', 'Referensi' => null]],
        ],
    ];
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
}

describe('tab Rencana produksi', function (): void {
    it('rata-rata Jumat dalam 4 minggu (0, 8, 6, 10 → 6) dikurangi saldo 1 = produksi 5; hanya produk jenis Produksi', function (): void {
        $k = SiapkanBakeryRencana($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2026-10-16')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Laporan/Stok')
            ->where('Saring.Tab', 'rencana')
            ->where('Saring.Tanggal', '2026-10-16')
            ->where('Rencana.Target', '2026-10-16')
            ->where('Rencana.Minggu', 4)
            ->has('Rencana.Baris', 1)
            ->where('Rencana.Baris.0.NamaProduk', 'Roti Tawar Gandum Utuh')
            ->where('Rencana.Baris.0.Sampel', 4)
            ->where('Rencana.Baris.0.RataHariSama', '6.0000')
            ->where('Rencana.Baris.0.PreOrder', '0.0000')
            ->where('Rencana.Baris.0.Kebutuhan', '6.0000')
            ->where('Rencana.Baris.0.Saldo', '1.0000')
            ->where('Rencana.Baris.0.Rekomendasi', '5.0000')
            ->where('Rencana.Baris.0.SimbolSatuan', 'pcs')
            ->where('Restock', null)
            ->where('Nilai', null));
    });

    it('pre-order yang diambil pada tanggal itu menaikkan kebutuhan bila lebih besar dari rata-rata; tanggal lain dan yang dibatalkan tidak', function (): void {
        $k = SiapkanBakeryRencana($this);
        BuatPreOrderRoti($this, $k, '9', '2026-10-16');
        BuatPreOrderRoti($this, $k, '40', '2026-10-17');
        BuatPreOrderRoti($this, $k, '25', '2026-10-16');
        PesananPenjualan::query()->where('TotalPesanan', '375000.00')->update(['Status' => StatusPesananPenjualan::Dibatalkan->value]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2026-10-16')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Rencana.Baris.0.PreOrder', '9.0000')
            ->where('Rencana.Baris.0.Kebutuhan', '9.0000')
            ->where('Rencana.Baris.0.Rekomendasi', '8.0000'));
        // Sabtu 17 Okt: tak ada riwayat Sabtu, kebutuhan seluruhnya dari pre-order 40.
        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2026-10-17')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Rencana.Baris.0.RataHariSama', '0.0000')
            ->where('Rencana.Baris.0.PreOrder', '40.0000')
            ->where('Rencana.Baris.0.Rekomendasi', '39.0000'));
    });

    it('tanpa tanggal rencana untuk besok; tanggal lampau dipaksa ke hari ini; terlalu jauh dipotong 60 hari', function (): void {
        $k = SiapkanBakeryRencana($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/laporan/stok?tab=rencana')->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Tanggal', '2026-10-15')->where('Rencana.Target', '2026-10-15'));
        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2026-01-01')->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Tanggal', '2026-10-14'));
        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2027-06-01')->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Tanggal', '2026-12-13'));
        // Tab lain tetap tidak boleh melewati hari ini.
        $this->get('/kelola/laporan/stok?tab=kritis&tanggal=2027-06-01')->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Tanggal', '2026-10-14'));
    });

    it('ekspor CSV mengikuti tab: kolom, nilai, dan tanggal rencana', function (): void {
        $k = SiapkanBakeryRencana($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $csv = $this->get('/kelola/laporan/stok/ekspor?tab=rencana&tanggal=2026-10-16&format=csv')->assertOk()->streamedContent();
        expect($csv)->toContain('Rekomendasi produksi')->toContain('Roti Tawar Gandum Utuh')->toContain('6.0000')->toContain('5.0000')
            ->and($csv)->not->toContain('Air Mineral Botol');
    });

    it('isolasi tenant: penjualan dan stok tenant lain tidak ikut rencana', function (): void {
        $k = SiapkanBakeryRencana($this);
        $lain = BantuanOrganisasi::BuatTenant('Bakery Lain Sukoharjo');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);

        $this->get('/kelola/laporan/stok?tab=rencana&tanggal=2026-10-16')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Rencana.Baris', 0));
        expect($k['Tenant']->Id)->not->toBe($lain['Tenant']->Id);
    });
});

describe('formulir order produksi terisi dari rencana', function (): void {
    it('produk, lokasi, jumlah, dan tanggal terisi; bahan dimuat dari resep oleh halaman', function (): void {
        $k = SiapkanBakeryRencana($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $kueri = http_build_query(['produk' => $k['Roti']->Uuid, 'gudang' => $k['Gudang']->Uuid, 'jumlah' => '5', 'tanggal' => '2026-10-16']);

        $this->get("/kelola/persediaan/produksi/buat?{$kueri}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Produksi/Form')
            ->where('Mode', 'Buat')
            ->where('Order', null)
            ->where('Awal.UuidGudang', $k['Gudang']->Uuid)
            ->where('Awal.Tanggal', '2026-10-16')
            ->where('Awal.JumlahHasil', '5')
            ->where('Awal.Produk.Uuid', $k['Roti']->Uuid)
            ->where('Awal.Produk.Nama', 'Roti Tawar Gandum Utuh')
            ->where('Awal.Produk.Pelacakan', 'Tidak'));
    });

    it('isian awal tidak sah diabaikan: produk bukan Produksi, jumlah nol/format salah, atau lokasi di luar akses → formulir kosong biasa', function (): void {
        $k = SiapkanBakeryRencana($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $dasar = ['produk' => $k['Roti']->Uuid, 'gudang' => $k['Gudang']->Uuid, 'jumlah' => '5'];
        $awalKosong = fn (array $kueri) => $this->get('/kelola/persediaan/produksi/buat?'.http_build_query($kueri))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Awal', null));

        $awalKosong([...$dasar, 'produk' => $k['Biasa']->Uuid]);
        $awalKosong([...$dasar, 'jumlah' => 'banyak']);
        $awalKosong([...$dasar, 'gudang' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']);
        $awalKosong([...$dasar, 'tanggal' => '16/10/2026']);
        $awalKosong(['produk' => $k['Roti']->Uuid]);
        $this->get('/kelola/persediaan/produksi/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Awal', null));
    });

    it('Kasir tanpa izin kelola persediaan tidak membuka formulir (tetap 403)', function (): void {
        $k = SiapkanBakeryRencana($this);
        $kasir = Pengguna::query()->whereKey($k['Kasir']->Id)->sole();
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get('/kelola/persediaan/produksi/buat?produk='.$k['Roti']->Uuid)->assertForbidden();
    });
});
