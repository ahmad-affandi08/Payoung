<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Promo\Model\PromoPemakaian;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * v3.29 (F-17 bagian 3, F-16c): penjualan kasir kanal Antar (bukan pesanan online) membawa ongkir yang diisi kasir dan
 * potongan gratis ongkir dari promo. Server menerima `BiayaKirim`/`DiskonKirim`, mengenali promo gratis ongkir dari
 * hitungannya sendiri, dan mencatat pemakaian promonya (kuota & laporan efektivitas).
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('kanal Antar berongkir Rp 15.000 digratiskan promo: diterima, pemakaian promo tercatat, tanpa tinjauan promo', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    $promo = BantuanTokoOnline::BuatPromoGratisOngkir($k, minimal: '50000.00');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $roti = BantuanKatalog::BuatProduk(['Nama' => 'Roti Sobek Cokelat Keju Isi 10', 'Jenis' => JenisProduk::NonStok], '30000.00');

    $item = BantuanPenjualan::Item($k, [
        'BiayaKirim' => '15000.00',
        'DiskonKirim' => '15000.00',
        'Baris' => [['Produk' => $roti, 'Jumlah' => '2', 'Harga' => '30000.00']],
        'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '60000.00']],
    ], ['Kanal' => 'Antar']);
    expect($item['Data']['Ringkasan']['TotalAkhir'])->toBe('60000.00')
        ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $pakai = PromoPemakaian::query()->where('IdPenjualan', $p->Id)->get();

    expect($p->Kanal)->toBe(KanalPenjualan::Antar)
        ->and([$p->BiayaKirim, $p->DiskonKirim, $p->TotalAkhir])->toBe(['15000.00', '15000.00', '60000.00'])
        ->and($p->AlasanTinjauan ?? '')->not->toContain('Promo')
        ->and($pakai)->toHaveCount(1)
        ->and($pakai->first()?->IdPromo)->toBe($promo->Id);
});

it('retur penjualan berongkir: bagian ongkir dibalik ke Pendapatan Pengiriman, bukan Retur Penjualan. Laporan retur tanpa ongkir', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter', '10', '30000', '50000.00');
    $item = BantuanPenjualan::Item($k, [
        'BiayaKirim' => '10000.00',
        'Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '50000.00']],
    ], ['Kanal' => 'Antar']);
    expect($item['Data']['Ringkasan']['TotalAkhir'])->toBe('110000.00')
        ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

    // Retur 1 dari 2: separuh baris (55.000 = 50.000 barang + 5.000 ongkir), lalu sisanya.
    $satu = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $dua = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => PenjualanDetail::query()->whereKey($d->Id)->sole(), 'Jumlah' => '1']]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$dua]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $r1 = ReturPenjualan::query()->where('Uuid', $satu['Uuid'])->sole();
    $r2 = ReturPenjualan::query()->where('Uuid', $dua['Uuid'])->sole();
    $saldo = function (PeranAkun $peran, int $idJurnal) use ($k): string {
        $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $k['Outlet']->Id);
        $total = Uang::Nol();
        foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get() as $b) {
            $total = $total->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
        }

        return $total->KeString();
    };

    expect([$r1->TotalRefund, $r1->TotalBiayaKirim, $r2->TotalBiayaKirim])->toBe(['55000.00', '5000.00', '5000.00'])
        ->and($saldo(PeranAkun::ReturPenjualan, (int) $r1->IdJurnal))->toBe('50000.00')
        ->and($saldo(PeranAkun::PendapatanPengiriman, (int) $r1->IdJurnal))->toBe('5000.00')
        ->and($saldo(PeranAkun::PendapatanPengiriman, (int) $r2->IdJurnal))->toBe('5000.00')
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
});
