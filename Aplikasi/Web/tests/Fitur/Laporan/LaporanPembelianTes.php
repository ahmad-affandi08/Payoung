<?php

declare(strict_types=1);

use App\Domain\Pembelian\Model\PesananPembelian;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * §10.10 laporan pembelian: per pemasok, per produk, dan PO outstanding (`/kelola/laporan/pembelian`). Faktur yang
 * dibatalkan tidak ikut; PO outstanding = keadaan saat ini; ekspor CSV ikut tab; dibatasi outlet akses.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Pemasok A: faktur minyak 24 @ 31.500 (kemarin). Pemasok B: minyak 12 @ 33.000 + gula 10 @ 14.000 (2 hari lalu).
 * PO ke A: gula 20 @ 14.000, diterima 5 (sisa 15), perkiraan tiba 3 hari lalu (tanggal kalender WIB, sama dengan tanggal bisnis laporan tanpa outlet).
 *
 * @return array<string, mixed>
 */
function SiapkanPembelianLaporan(mixed $tes): array
{
    $t = BantuanPembelian::SiapkanTenant();
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Lokal Kemasan 1 kg']);
    $a = BantuanPembelian::BuatPemasok('PT Sumber Pangan Nusantara');
    $b = BantuanPembelian::BuatPemasok('CV Gula Manis Sejahtera');
    $idPemilik = $t['Pemilik']->Id;
    $grnA = BantuanPembelian::TerimaTanpaPo($a, $t['Gudang'], [[$minyak, '24', '31500']], $idPemilik, tanggal: BantuanPembelian::Hari(1));
    BantuanPembelian::Fakturkan($a, [$grnA], $idPemilik, tanggal: BantuanPembelian::Hari(1));
    $grnB = BantuanPembelian::TerimaTanpaPo($b, $t['Gudang'], [[$minyak, '12', '33000'], [$gula, '10', '14000']], $idPemilik, tanggal: BantuanPembelian::Hari(2));
    BantuanPembelian::Fakturkan($b, [$grnB], $idPemilik, tanggal: BantuanPembelian::Hari(2));
    $po = BantuanPembelian::BuatPoDisetujui($a, $t['Gudang'], [[$gula, '20', '14000']], $idPemilik);
    BantuanPembelian::TerimaDariPo($po, ['5'], $idPemilik);
    DB::table('PesananPembelian')->where('Id', $po->Id)->update(['PerkiraanTiba' => CarbonImmutable::now('Asia/Jakarta')->startOfDay()->subDays(3)->toDateString()]);
    BantuanPersediaan::MasukSebagai($tes, $t['Tenant']->Id);

    return [...$t, 'Minyak' => $minyak, 'Gula' => $gula, 'A' => $a, 'B' => $b, 'Po' => $po];
}

it('per pemasok: faktur, total, retur, pembelian bersih, dibayar, sisa hutang; urut pembelian bersih; ekspor CSV', function (): void {
    $k = SiapkanPembelianLaporan($this);

    $this->get('/kelola/laporan/pembelian')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Laporan/Pembelian')
        ->where('Saring.Tab', 'pemasok')
        ->has('Isi', 2)
        ->where('Isi.0.NamaPemasok', 'PT Sumber Pangan Nusantara')
        ->where('Isi.0.Kunci', $k['A']->Uuid)
        ->where('Isi.0.JumlahFaktur', 1)
        ->where('Isi.0.Total', '756000.00')
        ->where('Isi.0.Retur', '0.00')
        ->where('Isi.0.Bersih', '756000.00')
        ->where('Isi.0.Dibayar', '0.00')
        ->where('Isi.0.SisaHutang', '756000.00')
        ->where('Isi.1.NamaPemasok', 'CV Gula Manis Sejahtera')
        ->where('Isi.1.Total', '536000.00')
        ->where('Isi.1.SisaHutang', '536000.00'));

    $csv = $this->get('/kelola/laporan/pembelian/ekspor?tab=pemasok')->assertOk()->streamedContent();
    expect($csv)->toContain('Pemasok')->toContain('PT Sumber Pangan Nusantara')->toContain('Sisa hutang')->toContain('756000.00');
});

it('periode memakai tanggal faktur: faktur di luar periode tidak ikut', function (): void {
    SiapkanPembelianLaporan($this);
    $hari = BantuanPembelian::Hari(1)->toDateString();

    $this->get("/kelola/laporan/pembelian?dari={$hari}&sampai={$hari}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->has('Isi', 1)
        ->where('Isi.0.NamaPemasok', 'PT Sumber Pangan Nusantara'));
});

it('per produk: jumlah, nilai beli, harga rata-rata/terendah/tertinggi per satuan dasar, jumlah faktur dan pemasok; ekspor CSV', function (): void {
    $k = SiapkanPembelianLaporan($this);

    $this->get('/kelola/laporan/pembelian?tab=produk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'produk')
        ->has('Isi', 2)
        ->where('Isi.0.NamaProduk', $k['Minyak']->Nama)
        ->where('Isi.0.Qty', '36.0000')
        ->where('Isi.0.Nilai', '1152000.00')
        ->where('Isi.0.Bersih', '1152000.00')
        ->where('Isi.0.HargaRata', '32000.00')
        ->where('Isi.0.HargaMin', '31500.00')
        ->where('Isi.0.HargaMax', '33000.00')
        ->where('Isi.0.JumlahFaktur', 2)
        ->where('Isi.0.JumlahPemasok', 2)
        ->where('Isi.1.NamaProduk', $k['Gula']->Nama)
        ->where('Isi.1.Nilai', '140000.00')
        ->where('Isi.1.JumlahPemasok', 1));

    $csv = $this->get('/kelola/laporan/pembelian/ekspor?tab=produk')->assertOk()->streamedContent();
    expect($csv)->toContain('Nilai beli')->toContain('Harga tertinggi')->toContain('1152000.00');
});

it('PO outstanding: hanya PO disetujui/diterima sebagian dengan baris belum lengkap; nilai sisa, umur, keterlambatan; PO lengkap hilang', function (): void {
    $k = SiapkanPembelianLaporan($this);

    $this->get('/kelola/laporan/pembelian?tab=po')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'po')
        ->has('Isi', 1)
        ->where('Isi.0.Nomor', $k['Po']->Nomor)
        ->where('Isi.0.NamaPemasok', 'PT Sumber Pangan Nusantara')
        ->where('Isi.0.Status', 'DiterimaSebagian')
        ->where('Isi.0.JumlahBaris', 1)
        ->where('Isi.0.JumlahBarisSisa', 1)
        ->where('Isi.0.Total', '280000.00')
        ->where('Isi.0.NilaiSisa', '210000.00')
        ->where('Isi.0.HariTerlambat', 3));

    $csv = $this->get('/kelola/laporan/pembelian/ekspor?tab=po')->assertOk()->streamedContent();
    expect($csv)->toContain('Nilai sisa')->toContain('210000.00')->toContain($k['Po']->Nomor);

    // Sisa 15 diterima semua: PO tidak outstanding lagi.
    BantuanPembelian::TerimaDariPo(PesananPembelian::query()->whereKey($k['Po']->Id)->firstOrFail(), ['15'], $k['Pemilik']->Id);
    $this->get('/kelola/laporan/pembelian?tab=po')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Isi', 0));
});

it('outlet di luar akses menghasilkan laporan kosong; tab tidak dikenal kembali ke per pemasok', function (): void {
    SiapkanPembelianLaporan($this);

    $this->get('/kelola/laporan/pembelian?outlet=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Isi', 0));
    $this->get('/kelola/laporan/pembelian?tab=tidak-ada')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Tab', 'pemasok'));
});
