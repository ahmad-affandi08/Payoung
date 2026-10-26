<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-12 (v3.37) nota tagihan pelanggan: semua piutang terbuka satu pelanggan dengan dibayar, sisa, hari lewat jatuh
 * tempo, total sisa & total yang sudah lewat; izin `pelanggan.lihat`; pelanggan tenant lain 404.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Siang hari WIB: tanggal bisnis = tanggal kalender (jauh dari jam tutup buku).
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Jakarta'));
});

it('nota tagihan: piutang terbuka pelanggan, dibayar & sisa, hari lewat jatuh tempo, total', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Grosir Sembako Nota');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya Abadi Sentosa', 'NoHp' => '6281355550001', 'Alamat' => 'Pasar Gede Blok B-12, Solo', 'LimitKredit' => '5000000', 'TerminHari' => 14]);
    $kirim = fn (string $jumlah, string $total) => BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $produk, 'Jumlah' => $jumlah, 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => $total]]],
        ['UuidPelanggan' => $toko->Uuid],
    );
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$kirim('2', '77000.00'), $kirim('10', '385000.00')]))->toBe([['Diterima', null], ['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pertama = Piutang::query()->where('Jumlah', '77000.00')->sole();
    // Sebagian piutang pertama sudah dibayar (dicatat langsung untuk uji tampilan; alur pelunasan diuji di PelunasanPiutangTes).
    $pertama->forceFill(['JumlahDibayar' => '27000.00', 'Status' => 'DibayarSebagian'])->save();

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $this->travel(20)->days();
    $this->get("/kelola/piutang/tagihan/{$toko->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Piutang/NotaTagihan')->where('Tanggal', '2026-10-21')
        ->where('Pelanggan.Nama', 'Toko Makmur Jaya Abadi Sentosa')
        ->where('Pelanggan.Alamat', 'Pasar Gede Blok B-12, Solo')
        ->count('Baris', 2)
        ->where('Baris.0.Jumlah', '77000.00')
        ->where('Baris.0.Dibayar', '27000.00')
        ->where('Baris.0.Sisa', '50000.00')
        ->where('Baris.0.HariLewat', 6)
        ->where('TotalSisa', '435000.00')
        ->where('TotalLewat', '435000.00'));

    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Jakarta'));
    $this->get("/kelola/piutang/tagihan/{$toko->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Baris.0.HariLewat', 0)
        ->where('TotalLewat', '0.00'));
});

it('nota tagihan: kasir tanpa izin. Pelanggan tenant lain 404', function (): void {
    $a = BantuanPenjualan::Siapkan($this, 'Toko Nota A');
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur', 'NoHp' => '6281355550002']);

    BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->get("/kelola/piutang/tagihan/{$toko->Uuid}")->assertForbidden();

    $b = BantuanPenjualan::Siapkan($this, 'Toko Nota B');
    BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
    $this->get("/kelola/piutang/tagihan/{$toko->Uuid}")->assertNotFound();
});
