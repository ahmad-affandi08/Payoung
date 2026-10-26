<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Persediaan\Aksi\AjukanTinjauanStokOpname;
use App\Domain\Persediaan\Aksi\BatalkanStokOpname;
use App\Domain\Persediaan\Aksi\KembalikanStokOpname;
use App\Domain\Persediaan\Aksi\MulaiStokOpname;
use App\Domain\Persediaan\Aksi\SetujuiStokOpname;
use App\Domain\Persediaan\Aksi\SimpanHitungStokOpname;
use App\Domain\Persediaan\Data\DataHitungOpname;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b stok opname (BR-05.3, J-05.4/J-05.5): snapshot saat mulai, lembar hitung disimpan berkali-kali, tinjau,
 * setujui → OpnameLebih/OpnameKurang sebesar fisik − (snapshot + mutasi selama opname), dinilai HPP berjalan.
 * Satu opname aktif per lokasi (dan kategori). Invarian stok & jurnal di akhir setiap skenario.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanOpname(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
        BantuanStokAwal::Baris($p['BahanBaku'], '25.5', '14750'),
        BantuanStokAwal::Baris($p['Batch'], '40', '19500', 'UHT-2609A', '2027-03-31'),
        BantuanStokAwal::Baris($p['Seri'], '2', '675000', nomorSeri: ['RC-0001', 'RC-0002']),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p];
}

/** @param list<DataHitungOpname> $hitung */
function Hitung(StokOpname $opname, array $hitung, int $idPengguna): StokOpname
{
    return app(SimpanHitungStokOpname::class)->Jalankan($opname, $hitung, $idPengguna);
}

function UrutanBaris(StokOpname $opname, int $idProduk, ?string $nomorSeri = null): int
{
    return StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $idProduk)
        ->when($nomorSeri !== null, fn ($k) => $k->where('NomorSeri', $nomorSeri))->value('Urutan');
}

describe('F-05b stok opname: snapshot, status, satu opname aktif', function (): void {
    it('mulai: snapshot per produk, per batch, per nomor seri. Nomor SO. Status Berlangsung', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, 'Opname akhir bulan', $t['Pemilik']->Id);
        $baris = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->orderBy('Urutan')->get();

        expect($opname->Status)->toBe(StatusStokOpname::Berlangsung)
            ->and($opname->Nomor)->toBe('SO/'.$t['Gudang']->Kode.'/'.CarbonImmutable::now('Asia/Jakarta')->format('ym').'/001')
            ->and($baris->count())->toBe(5)
            ->and($baris->firstWhere('IdProduk', $t['Produk']['Stok']->Id)->JumlahSistem)->toBe('100.0000')
            ->and($baris->firstWhere('IdProduk', $t['Produk']['Batch']->Id)->NomorBatch)->toBe('UHT-2609A')
            ->and($baris->where('IdProduk', $t['Produk']['Seri']->Id)->pluck('NomorSeri')->all())->toBe(['RC-0001', 'RC-0002'])
            ->and($baris->every(fn ($b) => $b->JumlahFisik === null && $b->IdMutasiSnapshot > 0))->toBeTrue();
    });

    it('satu opname aktif per lokasi. Opname kategori boleh berbarengan dengan kategori lain, tidak dengan seluruh produk', function (): void {
        $t = SiapkanOpname();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $sembako = BantuanKatalog::BuatKategori('Sembako');
        $opnameMinuman = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $minuman->Uuid, true, null, $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id)))->toBe('OpnameAktifSudahAda')
            ->and(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $minuman->Uuid, false, null, $t['Pemilik']->Id)))->toBe('OpnameAktifSudahAda')
            ->and(app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $sembako->Uuid, false, null, $t['Pemilik']->Id)->Status)->toBe(StatusStokOpname::Berlangsung);

        app(BatalkanStokOpname::class)->Jalankan($opnameMinuman, 'Salah pilih kategori', $t['Pemilik']->Id);
        expect(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id)))->toBe('OpnameAktifSudahAda');
    });

    it('opname kategori hanya memuat & menerima produk kategori itu (termasuk sub-kategori)', function (): void {
        $t = SiapkanOpname();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $susu = BantuanKatalog::BuatKategori('Susu', $minuman);
        $t['Produk']['Batch']->forceFill(['IdKategori' => $susu->Id])->save();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $minuman->Uuid, false, null, $t['Pemilik']->Id);

        expect(StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->pluck('IdProduk')->unique()->values()->all())->toBe([$t['Produk']['Batch']->Id])
            ->and($opname->NamaKategori)->toBe('Minuman')
            ->and(B::KodeGalat(fn () => Hitung($opname, [new DataHitungOpname(null, $t['Produk']['Stok']->Id, Kuantitas::Dari('3'))], $t['Pemilik']->Id)))->toBe('ProdukDiLuarKategori');
    });

    it('alur status: hitung berkali-kali → tinjau → kembali hitung → tinjau → setujui. Hitung saat ditinjau ditolak', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id)))->toBe('OpnameBelumDihitung');

        $u = UrutanBaris($opname, $t['Produk']['Stok']->Id);
        Hitung($opname, [new DataHitungOpname($u, null, Kuantitas::Dari('90'))], $t['Pemilik']->Id);
        $opname = Hitung($opname, [new DataHitungOpname($u, null, Kuantitas::Dari('97'))], $t['Pemilik']->Id);
        expect($opname->JumlahDihitung)->toBe(1);

        $opname = app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        expect($opname->Status)->toBe(StatusStokOpname::Ditinjau)
            ->and(B::KodeGalat(fn () => Hitung($opname, [new DataHitungOpname($u, null, Kuantitas::Dari('1'))], $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');

        app(KembalikanStokOpname::class)->Jalankan($opname, 'Hitung ulang rak minyak', $t['Pemilik']->Id);
        Hitung($opname, [new DataHitungOpname($u, null, Kuantitas::Dari('98'))], $t['Pemilik']->Id);
        app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        $opname = app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        $ulang = app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);

        expect($opname->Status)->toBe(StatusStokOpname::Disetujui)
            ->and($ulang->Id)->toBe($opname->Id)
            ->and(MutasiStok::query()->where('JenisReferensi', 'StokOpname')->count())->toBe(1)
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['98.0000', '3773000.00'])
            ->and($opname->TotalNilaiKurang)->toBe('77000.00')
            ->and(B::KodeGalat(fn () => app(BatalkanStokOpname::class)->Jalankan($opname, 'Terlambat', $t['Pemilik']->Id)))->toBe('StatusTidakSesuai')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05b stok opname: BR-05.3 selisih = fisik − (snapshot + mutasi selama opname)', function (): void {
    it('penjualan & mutasi lain selama opname tidak dianggap selisih. Jurnal kurang → Susut, lebih → Selisih HPP', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);

        // Selama opname: 10 minyak terjual, 2,5 kg gula dipakai (penjualan tiruan buku stok).
        BantuanStokAwal::Jual($t['Produk']['Stok'], $t['Gudang'], '10');
        BantuanStokAwal::Jual($t['Produk']['BahanBaku'], $t['Gudang'], '2.5');

        // Fisik dihitung setelah penjualan: minyak 88 (hilang 2), gula 24 (lebih 1).
        Hitung($opname, [
            new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Stok']->Id), null, Kuantitas::Dari('88')),
            new DataHitungOpname(UrutanBaris($opname, $t['Produk']['BahanBaku']->Id), null, Kuantitas::Dari('24')),
        ], $t['Pemilik']->Id);
        // Penjualan lagi setelah dihitung tetap tercatat sebagai mutasi selama opname.
        BantuanStokAwal::Jual($t['Produk']['Stok'], $t['Gudang'], '1');
        Hitung($opname, [new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Stok']->Id), null, Kuantitas::Dari('87'))], $t['Pemilik']->Id);

        app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        $opname = app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        $minyak = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['Stok']->Id)->sole();
        $gula = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['BahanBaku']->Id)->sole();
        $jurnal = Jurnal::query()->where('JenisSumber', 'StokOpname')->where('IdSumber', $opname->Id)->sole();

        expect($minyak->MutasiSelamaOpname)->toBe('-11.0000')
            ->and($minyak->Selisih)->toBe('-2.0000')
            ->and($minyak->NilaiSelisih)->toBe('-77000.00')
            ->and($gula->MutasiSelamaOpname)->toBe('-2.5000')
            ->and($gula->Selisih)->toBe('1.0000')
            ->and($gula->NilaiSelisih)->toBe('14750.00')
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('87.0000')
            ->and(B::Saldo($t['Produk']['BahanBaku'], $t['Gudang'])[0])->toBe('24.0000')
            ->and(MutasiStok::query()->where('JenisReferensi', 'StokOpname')->orderBy('Id')->pluck('JenisMutasi')->map(fn ($j) => $j->value)->all())->toEqualCanonicalizing(['OpnameKurang', 'OpnameLebih'])
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanBahanBaku', '14750.00', '0.00', $t['Outlet']->Id],
                ['PersediaanBarangDagang', '0.00', '77000.00', $t['Outlet']->Id],
                ['SelisihHpp', '0.00', '14750.00', $t['Outlet']->Id],
                ['SusutPersediaan', '77000.00', '0.00', $t['Outlet']->Id],
            ])
            ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([]);
    });

    it('baris belum dihitung tidak disesuaikan. Produk tanpa snapshot yang ditemukan menjadi opname lebih bernilai HPP berjalan', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);
        // Produksi belum pernah punya saldo di lokasi ini: tidak ada di snapshot, HPP belum diketahui → nilai 0.
        Hitung($opname, [new DataHitungOpname(null, $t['Produk']['Produksi']->Id, Kuantitas::Dari('4'))], $t['Pemilik']->Id);
        app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);

        expect(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['100.0000', '3850000.00'])
            ->and(B::Saldo($t['Produk']['Produksi'], $t['Gudang']))->toBe(['4.0000', '0.00'])
            ->and(StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['Produksi']->Id)->value('DariSnapshot'))->toBeFalse()
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('batch & nomor seri: batch kurang, nomor seri hilang, dan nomor seri ditemukan', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);

        Hitung($opname, [
            new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Batch']->Id), null, Kuantitas::Dari('37')),
            new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Seri']->Id, 'RC-0001'), null, Kuantitas::Dari('1')),
            new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Seri']->Id, 'RC-0002'), null, Kuantitas::Dari('0')),
            new DataHitungOpname(null, $t['Produk']['Seri']->Id, Kuantitas::Dari('1'), nomorSeri: 'RC-0099'),
            new DataHitungOpname(null, $t['Produk']['Batch']->Id, Kuantitas::Dari('6'), 'UHT-2610B', CarbonImmutable::parse('2027-06-30')),
        ], $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => Hitung($opname, [new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Seri']->Id, 'RC-0001'), null, Kuantitas::Dari('2'))], $t['Pemilik']->Id)))->toBe('JumlahTidakValid');

        app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);

        expect(NomorSeri::query()->where('Nomor', 'RC-0002')->value('Status'))->toBe(StatusNomorSeri::Keluar)
            ->and(NomorSeri::query()->where('Nomor', 'RC-0099')->sole()->IdGudang)->toBe($t['Gudang']->Id)
            ->and(B::Saldo($t['Produk']['Batch'], $t['Gudang'])[0])->toBe('43.0000')
            ->and(B::Saldo($t['Produk']['Seri'], $t['Gudang'])[0])->toBe('2.0000')
            ->and(MutasiStok::query()->where('JenisReferensi', 'StokOpname')->where('JenisMutasi', JenisMutasi::OpnameKurang->value)->count())->toBe(2)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('persetujuan bertanggal di periode terkunci ditolak PeriodeTerkunci', function (): void {
        $t = SiapkanOpname();
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id);
        Hitung($opname, [new DataHitungOpname(UrutanBaris($opname, $t['Produk']['Stok']->Id), null, Kuantitas::Dari('99'))], $t['Pemilik']->Id);
        app(AjukanTinjauanStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id);
        BantuanPersediaan::KunciPeriode(CarbonImmutable::now('Asia/Jakarta')->format('Y-m'));

        expect(B::KodeGalat(fn () => app(SetujuiStokOpname::class)->Jalankan($opname, $t['Pemilik']->Id)))->toBe('PeriodeTerkunci')
            ->and(StokOpname::query()->findOrFail($opname->Id)->Status)->toBe(StatusStokOpname::Ditinjau)
            ->and(MutasiStok::query()->where('JenisReferensi', 'StokOpname')->count())->toBe(0);
    });
});
