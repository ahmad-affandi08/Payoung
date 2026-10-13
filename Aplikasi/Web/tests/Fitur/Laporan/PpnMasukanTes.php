<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Aksi\BatalkanFakturPembelian;
use App\Domain\Pembelian\Aksi\SimpanReturPembelian;
use App\Domain\Pembelian\Data\DataBarisReturPembelian;
use App\Domain\Pembelian\Data\DataReturPembelian;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * TAX-05 PPN masukan di Laporan pajak: PPN faktur pembelian per bulan × tarif × dapat/tidaknya dikreditkan, dikurangi
 * PPN retur pembelian; faktur dibatalkan tidak dihitung; selisih terhadap PPN keluaran (kurang/lebih bayar).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('PPN masukan: dikreditkan vs tidak dikreditkan, retur mengurangi, faktur batal tidak ikut, selisih lebih bayar; ekspor CSV', function (): void {
    $t = BantuanPembelian::SiapkanTenant();
    $id = $t['Pemilik']->Id;
    $plastik = BantuanKatalog::BuatProduk(['Nama' => 'Kantong Plastik HD 24x40 isi 100']);
    $sabun = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Cuci Piring Jeruk Nipis 780 ml']);

    // Outlet non-PKP: PPN 33.000 tidak dapat dikreditkan (menjadi beban).
    $cv = BantuanPembelian::BuatPemasok('CV Makmur Plastik', true);
    $grnCv = BantuanPembelian::TerimaTanpaPo($cv, $t['Gudang'], [[$plastik, '10', '15000'], [$plastik, '5', '30000']], $id);
    $fakturCv = BantuanPembelian::Fakturkan($cv, [$grnCv], $id);
    expect($fakturCv->Pajak)->toBe('33000.00')->and($fakturCv->PpnDikreditkan)->toBeFalse();

    // Outlet PKP: PPN 63.360 dapat dikreditkan.
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $t['Outlet']->refresh()->forceFill(['ProfilPajak' => ['Pkp' => true]])->save();
    $wings = BantuanPembelian::BuatPemasok('PT Wings Surya Distribusi', true);
    $grnWings = BantuanPembelian::TerimaTanpaPo($wings, $t['Gudang'], [[$sabun, '48', '12000']], $id);
    $fakturWings = BantuanPembelian::Fakturkan($wings, [$grnWings], $id);
    expect($fakturWings->Pajak)->toBe('63360.00')->and($fakturWings->PpnDikreditkan)->toBeTrue();

    // Retur 12 sabun: PPN retur mengikuti faktur asalnya (dikreditkan).
    $retur = app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian(
        $grnWings->Uuid,
        BantuanPembelian::Hari(),
        'Dus penyok saat diterima',
        [new DataBarisReturPembelian($grnWings->Detail()->firstOrFail()->Id, Kuantitas::Dari('12'))],
        $id,
    ));
    expect($retur->Pajak)->not->toBe('0.00');

    // Faktur ketiga dibatalkan: tidak ikut.
    $grnBatal = BantuanPembelian::TerimaTanpaPo($wings, $t['Gudang'], [[$sabun, '10', '12000']], $id);
    $fakturBatal = BantuanPembelian::Fakturkan($wings, [$grnBatal], $id);
    app(BatalkanFakturPembelian::class)->Jalankan($fakturBatal, 'Faktur salah input', $id);

    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
    $bersih = Uang::Dari('63360.00')->Kurangi(Uang::Dari($retur->Pajak))->KeString();

    $this->get('/kelola/laporan/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Laporan/Pajak')
        ->has('PpnMasukan', 2)
        ->where('PpnMasukan.0.Dikreditkan', false)
        ->where('PpnMasukan.0.Tarif', '12.000000')
        ->where('PpnMasukan.0.JumlahFaktur', 1)
        ->where('PpnMasukan.0.Pajak', '33000.00')
        ->where('PpnMasukan.0.PajakBersih', '33000.00')
        ->where('PpnMasukan.1.Dikreditkan', true)
        ->where('PpnMasukan.1.JumlahFaktur', 1)
        ->where('PpnMasukan.1.Pajak', '63360.00')
        ->where('PpnMasukan.1.PajakRetur', $retur->Pajak)
        ->where('PpnMasukan.1.PajakBersih', $bersih)
        ->where('RingkasanPpn.PpnKeluaran', '0.00')
        ->where('RingkasanPpn.PpnMasukanKredit', $bersih)
        ->where('RingkasanPpn.PpnMasukanTanpaKredit', '33000.00')
        ->where('RingkasanPpn.Selisih', '-'.$bersih));

    $csv = $this->get('/kelola/laporan/pajak/ekspor?jenis=ppn-masukan')->assertOk()->streamedContent();
    expect($csv)->toContain('PPN masukan bersih')->toContain('Dapat dikreditkan')->toContain('63360.00')->toContain('33000.00');
});

it('PPN masukan: faktur pemasok non-PKP tanpa PPN tidak muncul, hasil kosong dan selisih nol', function (): void {
    $t = BantuanPembelian::SiapkanTenant();
    $id = $t['Pemilik']->Id;
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $pemasok = BantuanPembelian::BuatPemasok('PT Sumber Pangan Nusantara');
    $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$minyak, '24', '31500']], $id, tanggal: BantuanPembelian::Hari(40));
    BantuanPembelian::Fakturkan($pemasok, [$grn], $id, tanggal: BantuanPembelian::Hari(40));
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    // Pemasok non-PKP: faktur tanpa PPN tidak masuk laporan PPN masukan.
    $this->get('/kelola/laporan/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('PpnMasukan', [])
        ->where('RingkasanPpn.Selisih', '0.00'));
});
