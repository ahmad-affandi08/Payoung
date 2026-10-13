<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Referensi\Enum\JenisHariLibur;
use App\Domain\Referensi\Model\HariLibur;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * X6 insight produk: tab "Analisis ABC" & "Menu engineering" di laporan penjualan, tab "Saran restock" di laporan stok
 * (laju pemakaian 28 hari sampai kemarin × cakupan 7/14/30 hari − saldo). Ekspor CSV ikut tab.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

/**
 * Minyak: stok 12, terjual 10 @ 38.500 (HPP 30.000). Teh: stok 10, terjual 1 @ 10.000 (HPP 3.000).
 *
 * @return array<string, mixed>
 */
function SiapkanPenjualanInsight(mixed $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Warung Kopi Insight Klaten');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '12');
    $teh = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Teh Melati Celup Isi 25', '10', '3000', '10000.00');
    BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '10', 'Harga' => '38500.00']]]);
    BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $teh, 'Jumlah' => '1', 'Harga' => '10000.00']]]);

    return [...$k, 'Minyak' => $minyak, 'Teh' => $teh];
}

it('analisis ABC: produk penyumbang ±80% omzet kelas A, sisanya setelah 95% kelas C; ekspor CSV', function (): void {
    $k = SiapkanPenjualanInsight($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=abc')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Laporan/Penjualan')
        ->where('Saring.Tab', 'abc')
        ->where('Isi.Baris.0.NamaProduk', $k['Minyak']->Nama)
        ->where('Isi.Baris.0.Bersih', '385000.00')
        ->where('Isi.Baris.0.Porsi', '97.47')
        ->where('Isi.Baris.0.Kelas', 'A')
        ->where('Isi.Baris.1.NamaProduk', 'Teh Melati Celup Isi 25')
        ->where('Isi.Baris.1.Kelas', 'C')
        ->where('Isi.Baris.1.PorsiKumulatif', '100.00')
        ->where('Isi.Ringkasan.A.Jumlah', 1)
        ->where('Isi.Ringkasan.B.Jumlah', 0)
        ->where('Isi.Ringkasan.C.Bersih', '10000.00'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=abc')->assertOk()->streamedContent();
    expect($csv)->toContain('Kelas')->toContain('97.47')->toContain('Teh Melati Celup Isi 25');
});

it('menu engineering: populer & margin tinggi = Star, keduanya rendah = Dog, ambang dari data', function (): void {
    $k = SiapkanPenjualanInsight($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=menu')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'menu')
        ->where('Isi.BatasPorsiQty', '35.00')
        ->where('Isi.RataRataMargin', '8363.64')
        ->where('Isi.Baris.0.NamaProduk', $k['Minyak']->Nama)
        ->where('Isi.Baris.0.MarginPerUnit', '8500.00')
        ->where('Isi.Baris.0.Kelas', 'Star')
        ->where('Isi.Baris.1.MarginPerUnit', '7000.00')
        ->where('Isi.Baris.1.Populer', false)
        ->where('Isi.Baris.1.Kelas', 'Dog'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=menu')->assertOk()->streamedContent();
    expect($csv)->toContain('Margin per unit')->toContain('Star')->toContain('Dog');
});

it('saran restock: pemakaian hari ini belum dihitung, besok jadi laju harian; cakupan hari mengubah saran beli', function (): void {
    $k = SiapkanPenjualanInsight($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    // Hari penjualan: periode dasar sampai kemarin, jadi belum ada pemakaian.
    $this->get('/kelola/laporan/stok?tab=restock')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'restock')
        ->where('Saring.Hari', 14)
        ->where('Restock.HariDasar', 28)
        ->where('Restock.Baris', []));

    $this->travelTo(CarbonImmutable::parse('2026-10-08 05:00:00', 'UTC'));

    // Minyak: 10 ÷ 28 = 0,3571/hari, saldo 2 → habis 5 hari lagi; 14 hari: 4,9994 − 2 → 3 (dibulatkan ke atas).
    $this->get('/kelola/laporan/stok?tab=restock')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Restock.Baris.0.NamaProduk', $k['Minyak']->Nama)
        ->where('Restock.Baris.0.RataPerHari', '0.3571')
        ->where('Restock.Baris.0.Saldo', '2.0000')
        ->where('Restock.Baris.0.HariHabis', 5)
        ->where('Restock.Baris.0.SaranBeli', '3.0000')
        ->where('Restock.Baris.1.NamaProduk', 'Teh Melati Celup Isi 25')
        ->where('Restock.Baris.1.SaranBeli', '0.0000'));

    // 30 hari: 10,713 − 2 → 9; nilai di luar 7/14/30 kembali ke 14.
    $this->get('/kelola/laporan/stok?tab=restock&hari=30')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Hari', 30)
        ->where('Restock.Baris.0.SaranBeli', '9.0000'));
    $this->get('/kelola/laporan/stok?tab=restock&hari=999')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Hari', 14));

    $csv = $this->get('/kelola/laporan/stok/ekspor?tab=restock&hari=30')->assertOk()->streamedContent();
    expect($csv)->toContain('Saran beli')->toContain('9.0000');
});

it('slow & fast moving: perputaran = pemakaian ÷ saldo; kelas Cepat/Lambat/Mati; pemakaian hari ini belum dihitung; rentang 30/60/90; ekspor', function (): void {
    $k = SiapkanPenjualanInsight($this);
    $sabun = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Sabun Cuci Piring 5 Liter', '12', '7500', '9000.00');
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    // Hari penjualan: periode dasar sampai kemarin, jadi belum ada pemakaian — semua yang bersaldo dianggap tidak bergerak.
    $this->get('/kelola/laporan/stok?tab=perputaran')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'perputaran')
        ->where('Saring.Hari', 30)
        ->where('Perputaran.Rentang', 30)
        ->where('Perputaran.Ringkasan.Mati.Jumlah', 3)
        ->where('Perputaran.Ringkasan.Cepat.Jumlah', 0));

    $this->travelTo(CarbonImmutable::parse('2026-10-08 05:00:00', 'UTC'));

    // Minyak: terpakai 10, saldo 2 → 5,0000 (Cepat). Teh: terpakai 1, saldo 9 → 0,1111 (Lambat). Sabun: tak terpakai (Mati).
    $this->get('/kelola/laporan/stok?tab=perputaran')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Perputaran.Baris.0.NamaProduk', $k['Minyak']->Nama)
        ->where('Perputaran.Baris.0.Kelas', 'Cepat')
        ->where('Perputaran.Baris.0.Pakai', '10.0000')
        ->where('Perputaran.Baris.0.Saldo', '2.0000')
        ->where('Perputaran.Baris.0.Perputaran', '5.0000')
        ->where('Perputaran.Baris.0.RataPerHari', '0.3333')
        ->where('Perputaran.Baris.0.HariCover', 6)
        ->where('Perputaran.Baris.1.NamaProduk', 'Teh Melati Celup Isi 25')
        ->where('Perputaran.Baris.1.Kelas', 'Lambat')
        ->where('Perputaran.Baris.1.Perputaran', '0.1111')
        ->where('Perputaran.Baris.2.NamaProduk', 'Sabun Cuci Piring 5 Liter')
        ->where('Perputaran.Baris.2.Kelas', 'Mati')
        ->where('Perputaran.Baris.2.Perputaran', '0.0000')
        ->where('Perputaran.Baris.2.HariCover', null)
        ->where('Perputaran.Ringkasan.Cepat.Jumlah', 1)
        ->where('Perputaran.Ringkasan.Lambat.Jumlah', 1)
        ->where('Perputaran.Ringkasan.Mati.Jumlah', 1)
        ->where('Perputaran.Ringkasan.Mati.Nilai', '90000.00')
        ->where('Perputaran.NilaiTertahan', '117000.00'));

    // Rentang 90 hari: rata-rata per hari ikut berubah; nilai di luar 30/60/90 kembali ke 30.
    $this->get('/kelola/laporan/stok?tab=perputaran&hari=90')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Hari', 90)
        ->where('Perputaran.Baris.0.RataPerHari', '0.1111'));
    $this->get('/kelola/laporan/stok?tab=perputaran&hari=14')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Hari', 30));

    $csv = $this->get('/kelola/laporan/stok/ekspor?tab=perputaran')->assertOk()->streamedContent();
    expect($csv)->toContain('Kelas')->toContain('Cepat')->toContain('Mati')->toContain('Sabun Cuci Piring 5 Liter');
});

it('saran restock musiman: laju dikali faktor Ramadan/Lebaran tahun lalu (selaras Lebaran, bukan tanggal Masehi)', function (): void {
    HariLibur::query()->create(['Tanggal' => '2026-03-20', 'Nama' => 'Hari Raya Idul Fitri 1447 H', 'Jenis' => JenisHariLibur::Nasional, 'Status' => StatusDataMaster::Terbit]);
    HariLibur::query()->create(['Tanggal' => '2027-03-09', 'Nama' => 'Cuti Bersama Idul Fitri', 'Jenis' => JenisHariLibur::CutiBersama, 'Status' => StatusDataMaster::Terbit]);
    HariLibur::query()->create(['Tanggal' => '2027-03-10', 'Nama' => 'Hari Raya Idul Fitri 1448 H', 'Jenis' => JenisHariLibur::Nasional, 'Status' => StatusDataMaster::Terbit]);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Musiman Boyolali');
    $sirup = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Sirup Markisa Botol 600 ml', '100', '15000', '22000.00');
    $jual = function (string $waktu) use ($k, $sirup): void {
        $this->travelTo(CarbonImmutable::parse($waktu, 'UTC'));
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $sirup, 'Jumlah' => '28', 'Harga' => '22000.00']]]);
    };
    // Geser 355 hari: dasar tahun lalu 2 Feb–1 Mar 2026 (28 = 1/hari); cakupan 2–15 Mar 2026 (28 dalam 14 hari = 2/hari).
    $jual('2026-02-10 05:00:00');
    $jual('2026-03-05 05:00:00');
    // Tahun ini: dasar 23 Jan–19 Feb 2027 = 1/hari.
    $jual('2027-02-10 05:00:00');
    $this->travelTo(CarbonImmutable::parse('2027-02-20 05:00:00', 'UTC'));
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    // Faktor 2,00 → 2/hari; saldo 16 → habis 8 hari lagi; 14 hari: 28 − 16 = 12.
    $this->get('/kelola/laporan/stok?tab=restock')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Restock.Musim.Jenis', 'Lebaran')
        ->where('Restock.Musim.SelisihHari', 355)
        ->where('Restock.Musim.Lebaran', '2027-03-10')
        ->where('Restock.Baris.0.RataPerHari', '1.0000')
        ->where('Restock.Baris.0.FaktorMusim', '2.00')
        ->where('Restock.Baris.0.RataPerkiraan', '2.0000')
        ->where('Restock.Baris.0.Saldo', '16.0000')
        ->where('Restock.Baris.0.HariHabis', 8)
        ->where('Restock.Baris.0.SaranBeli', '12.0000'));

    $csv = $this->get('/kelola/laporan/stok/ekspor?tab=restock')->assertOk()->streamedContent();
    expect($csv)->toContain('Faktor musim')->toContain('2.00');
});
