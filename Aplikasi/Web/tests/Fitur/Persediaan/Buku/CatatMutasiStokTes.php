<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Mesin buku stok `CatatMutasiStok` (BR-05.1, BR-04.2/04.3, DesainF05a C.2/C.3). Produk tanpa pelacakan; batch/seri
 * di PelacakanBukuTes. Setiap skenario diakhiri pemeriksaan invarian SQL mentah (SaldoStok = Σ MutasiStok, rantai
 * SaldoSetelah/NilaiSetelah, Q=0⇒N=0, lapisan FIFO = saldo).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Gudang: Gudang, Produk: array<string, Produk>, Gudang2: Gudang}
 */
function SiapkanBukuStokUji(MetodeHpp $metode = MetodeHpp::RataRata, bool $bolehMinus = false, string $nama = 'Toko Sembako Berkah Jaya'): array
{
    $t = BantuanPersediaan::SiapkanTenant($nama, $metode, $bolehMinus);
    $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

    return [
        'Tenant' => $t['Tenant'],
        'Pemilik' => $t['Pemilik'],
        'Gudang' => $t['Gudang'],
        'Produk' => $produk,
        'Gudang2' => BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Toko'),
    ];
}

describe('F-05a buku stok: pencatatan (BR-05.1, DesainF05a C.2)', function (): void {
    it('BR-05.1: setiap atribut baris mutasi tersimpan dan SaldoStok = Σ MutasiStok', function (): void {
        $b = SiapkanBukuStokUji();
        $minyak = $b['Produk']['Stok'];
        $dokumen = new DataDokumenMutasi(
            JenisReferensiMutasi::StokAwal,
            4401,
            '01K5STOKAWALTIMA000000000A',
            'SA/2026/09/0001',
            CarbonImmutable::parse('2026-09-20'),
            $b['Pemilik']->Id,
            null,
            [new DataBarisMutasi('P/77', $minyak->Id, $b['Gudang']->Id, JenisMutasi::StokAwal, Kuantitas::Dari('10'), ModeNilaiMutasi::Ditentukan,
                Uang::Dari('12345.68'), BigDecimal::of('1234.5678'), idReferensiDetail: 77)],
        );

        $hasil = app(CatatMutasiStok::class)->Jalankan($dokumen);
        $baris = $hasil->baris['P/77'];
        $m = MutasiStok::query()->findOrFail($baris->idMutasiStok);
        $saldo = BantuanBuku::AmbilSaldo($minyak->Id, $b['Gudang']->Id);

        expect($hasil->sudahAda)->toBeFalse()
            ->and($hasil->TotalHpp()->KeString())->toBe('12345.68')
            ->and($hasil->TotalNilaiDiminta()->KeString())->toBe('12345.68')
            ->and($hasil->TotalSelisih()->KeString())->toBe('0.00')
            ->and($baris->hppTidakDiketahui)->toBeFalse()
            ->and($m->IdTenant)->toBe($b['Tenant']->Id)
            ->and($m->IdProduk)->toBe($minyak->Id)
            ->and($m->IdGudang)->toBe($b['Gudang']->Id)
            ->and($m->IdBatchStok)->toBeNull()
            ->and($m->IdNomorSeri)->toBeNull()
            ->and($m->JenisMutasi)->toBe(JenisMutasi::StokAwal)
            ->and($m->Jumlah)->toBe('10.0000')
            ->and($m->HppSatuan)->toBe('1234.567800')
            ->and($m->TotalHpp)->toBe('12345.68')
            ->and($m->SelisihHpp)->toBe('0.00')
            ->and($m->SaldoSetelah)->toBe('10.0000')
            ->and($m->NilaiSetelah)->toBe('12345.68')
            ->and($m->HppRataRataSetelah)->toBe('1234.568000')
            ->and($m->JenisReferensi)->toBe(JenisReferensiMutasi::StokAwal)
            ->and($m->IdReferensi)->toBe(4401)
            ->and($m->IdReferensiDetail)->toBe(77)
            ->and($m->UuidReferensi)->toBe('01K5STOKAWALTIMA000000000A')
            ->and($m->NomorReferensi)->toBe('SA/2026/09/0001')
            ->and($m->KunciBaris)->toBe('P/77')
            ->and($m->IdMutasiAsal)->toBeNull()
            ->and($m->TanggalBisnis->toDateString())->toBe('2026-09-20')
            ->and($m->DibuatOleh)->toBe($b['Pemilik']->Id)
            ->and($m->DibuatPada)->not->toBeNull()
            ->and($saldo?->JumlahTersedia)->toBe('10.0000')
            ->and($saldo?->NilaiPersediaan)->toBe('12345.68')
            ->and($saldo?->HppRataRata)->toBe('1234.568000')
            ->and($saldo?->JumlahDipesan)->toBe('0.0000')
            ->and($saldo?->IdMutasiStokTerakhir)->toBe($m->Id)
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });

    it('BR-04.2 contoh #1 rata-rata bergerak lewat buku stok: stok awal, jual, terima, jual habis → Q 0, N 0', function (): void {
        $b = SiapkanBukuStokUji();
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;

        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $id, $g, '10', '12345.68', hppSatuan: '1234.5678')], JenisReferensiMutasi::StokAwal);
        $jual = BantuanBuku::CatatKeluar($id, $g, '3');
        $terima = BantuanBuku::CatatMasuk($id, $g, '5', '6500.00', JenisMutasi::PenerimaanPembelian);
        $saldoTengah = BantuanBuku::AmbilSaldo($id, $g);
        $habis = BantuanBuku::CatatKeluar($id, $g, '12');
        $saldo = BantuanBuku::AmbilSaldo($id, $g);

        expect($jual->TotalHpp()->KeString())->toBe('-3703.70')
            ->and((string) $jual->baris['K/1']->hppSatuan)->toBe('1234.568000')
            ->and($terima->TotalHpp()->KeString())->toBe('6500.00')
            ->and($saldoTengah?->HppRataRata)->toBe('1261.831667')
            ->and($saldoTengah?->NilaiPersediaan)->toBe('15141.98')
            ->and($habis->TotalHpp()->KeString())->toBe('-15141.98')
            ->and($habis->baris['K/1']->saldoSetelah->KeString())->toBe('0.0000')
            ->and($saldo?->JumlahTersedia)->toBe('0.0000')
            ->and($saldo?->NilaiPersediaan)->toBe('0.00')
            ->and(MutasiStok::query()->where('IdProduk', $id)->count())->toBe(4)
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });

    it('contoh #2 FIFO lewat buku stok: jual 12 = 10000 + 2400, lapisan L1 habis, L2 tersisa 3 / 3600', function (): void {
        $b = SiapkanBukuStokUji(MetodeHpp::Fifo);
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;

        $l1 = BantuanBuku::CatatMasuk($id, $g, '10', '10000.00', JenisMutasi::PenerimaanPembelian);
        $l2 = BantuanBuku::CatatMasuk($id, $g, '5', '6000.00', JenisMutasi::PenerimaanPembelian);
        $jual = BantuanBuku::CatatKeluar($id, $g, '12');

        $lapisan = LapisanFifo::query()->where('IdProduk', $id)->orderBy('Id')->get()->all();
        $saldo = BantuanBuku::AmbilSaldo($id, $g);

        expect($jual->TotalHpp()->KeString())->toBe('-12400.00')
            ->and((string) $jual->baris['K/1']->hppSatuan)->toBe('1033.333333')
            ->and($lapisan)->toHaveCount(2)
            ->and($lapisan[0]->IdMutasiSumber)->toBe($l1->baris['M/1']->idMutasiStok)
            ->and($lapisan[0]->Habis)->toBeTrue()
            ->and($lapisan[0]->JumlahSisa)->toBe('0.0000')
            ->and($lapisan[0]->NilaiSisa)->toBe('0.00')
            ->and($lapisan[0]->JumlahAwal)->toBe('10.0000')
            ->and($lapisan[0]->HppSatuan)->toBe('1000.000000')
            ->and($lapisan[0]->TanggalMasuk->toDateString())->toBe('2026-09-24')
            ->and($lapisan[1]->IdMutasiSumber)->toBe($l2->baris['M/1']->idMutasiStok)
            ->and($lapisan[1]->Habis)->toBeFalse()
            ->and($lapisan[1]->JumlahSisa)->toBe('3.0000')
            ->and($lapisan[1]->NilaiSisa)->toBe('3600.00')
            ->and($saldo?->NilaiPersediaan)->toBe('3600.00')
            ->and($saldo?->HppRataRata)->toBe('1200.000000')
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id, true))->toBe([]);
    });

    it('FIFO pembalik ber-idMutasiAsal mengonsumsi tepat lapisan sumbernya. Lapisan yang sudah terpakai → LapisanSudahTerpakai', function (): void {
        $b = SiapkanBukuStokUji(MetodeHpp::Fifo);
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;

        $a = BantuanBuku::CatatMasuk($id, $g, '10', '10000.00', JenisMutasi::PenerimaanPembelian);
        $c = BantuanBuku::CatatMasuk($id, $g, '10', '12000.00', JenisMutasi::PenerimaanPembelian);
        BantuanBuku::CatatKeluar($id, $g, '3');

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('B/M/1', $id, $g, '-10', '10000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $a->baris['M/1']->idMutasiStok),
        ]));

        $balik = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('B/M/1', $id, $g, '-10', '12000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $c->baris['M/1']->idMutasiStok),
        ]);
        $m = MutasiStok::query()->findOrFail($balik->baris['B/M/1']->idMutasiStok);

        expect($galat->kode)->toBe('LapisanSudahTerpakai')
            ->and($balik->TotalHpp()->KeString())->toBe('-12000.00')
            ->and($balik->TotalSelisih()->KeString())->toBe('0.00')
            ->and($m->IdMutasiAsal)->toBe($c->baris['M/1']->idMutasiStok)
            ->and(BantuanBuku::AmbilSaldo($id, $g)?->NilaiPersediaan)->toBe('7000.00')
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id, true))->toBe([]);
    });

    it('dokumen banyak baris lintas produk & lokasi: diproses urut masukan, rantai per pasangan, IdMutasiStokTerakhir = baris terakhir pasangan', function (): void {
        $b = SiapkanBukuStokUji();
        $minyak = $b['Produk']['Stok']->Id;
        $gula = $b['Produk']['BahanBaku']->Id;
        $g1 = $b['Gudang']->Id;
        $g2 = $b['Gudang2']->Id;

        $hasil = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('L/1', $minyak, $g2, '24', '924000.00'),
            BantuanBuku::BuatBaris('L/2', $gula, $g1, '12.5', '178125.00'),
            BantuanBuku::BuatBaris('L/3', $minyak, $g1, '6', '231000.00'),
            BantuanBuku::BuatBaris('L/4', $minyak, $g2, '-4', null),
            BantuanBuku::BuatBaris('L/5', $gula, $g1, '-0.75', null),
        ], JenisReferensiMutasi::StokAwal);

        $saldoMinyakG2 = BantuanBuku::AmbilSaldo($minyak, $g2);

        expect(array_keys($hasil->baris))->toBe(['L/1', 'L/2', 'L/3', 'L/4', 'L/5'])
            ->and($hasil->baris['L/4']->totalHpp->KeString())->toBe('-154000.00')
            ->and($hasil->baris['L/4']->saldoSetelah->KeString())->toBe('20.0000')
            ->and($hasil->baris['L/5']->totalHpp->KeString())->toBe('-10687.50')
            ->and($saldoMinyakG2?->JumlahTersedia)->toBe('20.0000')
            ->and($saldoMinyakG2?->IdMutasiStokTerakhir)->toBe($hasil->baris['L/4']->idMutasiStok)
            ->and(BantuanBuku::AmbilSaldo($gula, $g1)?->JumlahTersedia)->toBe('11.7500')
            ->and(BantuanBuku::AmbilSaldo($minyak, $g1)?->IdMutasiStokTerakhir)->toBe($hasil->baris['L/3']->idMutasiStok)
            ->and($hasil->baris['L/1']->idMutasiStok)->toBeLessThan($hasil->baris['L/5']->idMutasiStok)
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });

    it('dijalankan di transaksi pemanggil (savepoint): pemanggil gagal → mutasi dan saldo ikut batal', function (): void {
        $b = SiapkanBukuStokUji();
        $id = $b['Produk']['Stok']->Id;

        expect(fn () => DB::transaction(function () use ($id, $b): void {
            BantuanBuku::CatatMasuk($id, $b['Gudang']->Id, '10', '385000.00');

            throw new RuntimeException('Penjualan gagal disimpan');
        }))->toThrow(RuntimeException::class);

        expect(MutasiStok::query()->count())->toBe(0)
            ->and(SaldoStok::query()->where('IdProduk', $id)->where('JumlahTersedia', '<>', 0)->count())->toBe(0);
    });

    it('satu baris gagal → seluruh dokumen batal (tidak ada baris parsial)', function (): void {
        $b = SiapkanBukuStokUji();
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('1', $id, $g, '5', '192500.00', JenisMutasi::PenyesuaianMasuk),
            BantuanBuku::BuatBaris('2', $id, $g, '-8', null, JenisMutasi::PenyesuaianKeluar),
        ]));

        expect($galat->kode)->toBe('StokTidakCukup')
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(BantuanBuku::AmbilSaldo($id, $g)->JumlahTersedia ?? '0.0000')->toBe('0.0000');
    });

    it('HPP belum diketahui: keluar sebelum ada stok (boleh minus) dinilai 0 dan ditandai hppTidakDiketahui', function (): void {
        $b = SiapkanBukuStokUji(bolehMinus: true);
        $hasil = BantuanBuku::CatatKeluar($b['Produk']['Stok']->Id, $b['Gudang']->Id, '2');

        expect($hasil->baris['K/1']->hppTidakDiketahui)->toBeTrue()
            ->and($hasil->TotalHpp()->KeString())->toBe('0.00')
            ->and(BantuanBuku::AmbilSaldo($b['Produk']['Stok']->Id, $b['Gudang']->Id)?->JumlahTersedia)->toBe('-2.0000');
    });

    it('dokumen 2.000 baris tercatat lengkap di bawah 5 detik (kunci & sisipan berbasis himpunan, DesainF05a C.2)', function (): void {
        $b = SiapkanBukuStokUji();
        $produk = [$b['Produk']['Stok']->Id, $b['Produk']['BahanBaku']->Id, $b['Produk']['Produksi']->Id];
        $gudang = [$b['Gudang']->Id, $b['Gudang2']->Id];
        $baris = [];

        for ($i = 1; $i <= 2000; $i++) {
            $baris[] = BantuanBuku::BuatBaris('P/'.$i, $produk[$i % 3], $gudang[$i % 2], '3', '105000.00');
        }

        $mulai = hrtime(true);
        $hasil = BantuanBuku::Catat($baris, JenisReferensiMutasi::StokAwal);
        $durasiMs = intdiv(hrtime(true) - $mulai, 1_000_000);

        expect($hasil->baris)->toHaveCount(2000)
            ->and(MutasiStok::query()->count())->toBe(2000)
            ->and($hasil->TotalHpp()->KeString())->toBe('210000000.00')
            ->and(SaldoStok::query()->where('JumlahTersedia', '>', 0)->count())->toBe(6)
            ->and($durasiMs)->toBeLessThan(5000)
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });
});

describe('F-05a buku stok: validasi masukan (DesainF05a C.2 langkah 1 & 5)', function (): void {
    it('arah mutasi harus sesuai jenis', function (JenisMutasi $jenis, string $jumlah, ?string $nilai): void {
        $b = SiapkanBukuStokUji(bolehMinus: true);
        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('1', $b['Produk']['Stok']->Id, $b['Gudang']->Id, $jumlah, $nilai, $jenis),
        ]));

        expect($galat->kode)->toBe('ArahMutasiTidakSesuai')
            ->and(MutasiStok::query()->count())->toBe(0);
    })->with([
        'penjualan menambah stok' => [JenisMutasi::Penjualan, '2', null],
        'penerimaan pembelian mengurangi stok' => [JenisMutasi::PenerimaanPembelian, '-2', '1000.00'],
        'susut menambah stok' => [JenisMutasi::Susut, '1', null],
        'opname lebih mengurangi stok' => [JenisMutasi::OpnameLebih, '-1', null],
    ]);

    it('StokAwal boleh dua arah (pembatalan stok awal = baris negatif)', function (): void {
        $b = SiapkanBukuStokUji();
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;

        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $id, $g, '4', '154000.00')], JenisReferensiMutasi::StokAwal, 51);
        BantuanBuku::Catat([BantuanBuku::BuatBaris('B/P/1', $id, $g, '-4', '154000.00')], JenisReferensiMutasi::StokAwal, 51);

        expect(BantuanBuku::AmbilSaldo($id, $g)?->JumlahTersedia)->toBe('0.0000')
            ->and(BantuanBuku::AmbilSaldo($id, $g)?->NilaiPersediaan)->toBe('0.00')
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });

    it('baris pembalik: jenis sama & tanda kebalikan boleh walau jenisnya satu arah. Selain itu ArahMutasiTidakSesuai', function (): void {
        $b = SiapkanBukuStokUji();
        $id = $b['Produk']['Stok']->Id;
        $g = $b['Gudang']->Id;
        $asal = BantuanBuku::CatatMasuk($id, $g, '10', '385000.00', JenisMutasi::PenerimaanPembelian)->baris['M/1']->idMutasiStok;

        $tandaSama = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('B/1', $id, $g, '2', '77000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $asal)]));
        $jenisLain = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('B/1', $id, $g, '-2', '77000.00', JenisMutasi::ReturPembelian, idMutasiAsal: $asal)]));
        $gudangLain = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('B/1', $id, $b['Gudang2']->Id, '-2', '77000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $asal)]));
        $tidakAda = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('B/1', $id, $g, '-2', '77000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $asal + 999)]));
        $balik = BantuanBuku::Catat([BantuanBuku::BuatBaris('B/1', $id, $g, '-10', '385000.00', JenisMutasi::PenerimaanPembelian, idMutasiAsal: $asal)]);

        expect([$tandaSama->kode, $jenisLain->kode, $gudangLain->kode, $tidakAda->kode])->toBe(array_fill(0, 4, 'ArahMutasiTidakSesuai'))
            ->and($balik->TotalHpp()->KeString())->toBe('-385000.00')
            ->and(BantuanBuku::AmbilSaldo($id, $g)?->JumlahTersedia)->toBe('0.0000')
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([]);
    });

    it('masukan tidak valid ditolak sebelum menulis apa pun', function (string $kode, Closure $buatBaris): void {
        $b = SiapkanBukuStokUji();
        $baris = $buatBaris($b['Produk'], $b['Gudang']->Id);
        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat($baris));

        expect($galat->kode)->toBe($kode)
            ->and(MutasiStok::query()->count())->toBe(0);
    })->with([
        'dokumen tanpa baris' => ['JumlahTidakValid', fn (array $p, int $g): array => []],
        'jumlah 0' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '0', '0.00')]],
        'kunci baris ganda' => ['JumlahTidakValid', fn (array $p, int $g): array => [
            BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1', '1000.00'), BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '2', '2000.00'),
        ]],
        'kunci baris > 80 karakter' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris(str_repeat('K', 81), $p['Stok']->Id, $g, '1', '1000.00')]],
        'jumlah pecahan untuk satuan pcs' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1.5', '1000.00')]],
        'ditentukan tanpa nilai' => ['HppTidakValid', fn (array $p, int $g): array => [new DataBarisMutasi('1', $p['Stok']->Id, $g, JenisMutasi::StokAwal, Kuantitas::Dari('1'), ModeNilaiMutasi::Ditentukan)]],
        'nilai negatif' => ['HppTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1', '-5.00')]],
        'HPP 7 desimal' => ['HppTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1', '1000.00', hppSatuan: '1000.1234567')]],
        'HPP negatif' => ['HppTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1', '1000.00', hppSatuan: '-1')]],
        'berjalan dengan nilai' => ['HppTidakValid', fn (array $p, int $g): array => [new DataBarisMutasi('1', $p['Stok']->Id, $g, JenisMutasi::StokAwal, Kuantitas::Dari('1'), ModeNilaiMutasi::Berjalan, Uang::Dari('1000'))]],
        'produk jasa tidak punya stok' => ['ProdukTanpaStok', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Jasa']->Id, $g, '1', '1000.00')]],
        'produk tanpa pelacakan diberi batch' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '1', '1000.00', batchMasuk: new DataBatchMasuk('B-01', null))]],
        'produk batch tanpa batch' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Batch']->Id, $g, '1', '19500.00')]],
        'produk seri tanpa nomor seri' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Seri']->Id, $g, '1', '675000.00')]],
        'nomor seri dengan jumlah 2' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Seri']->Id, $g, '2', '1350000.00', nomorSeriMasuk: 'RC-0001')]],
        'batch keluar pada baris masuk' => ['JumlahTidakValid', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Batch']->Id, $g, '1', '19500.00', idBatchStok: 1)]],
        'produk tidak dikenal' => ['ProdukTidakDikenal', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', 987654321, $g, '1', '1000.00')]],
        'lokasi stok tidak dikenal' => ['GudangTidakDikenal', fn (array $p, int $g): array => [BantuanBuku::BuatBaris('1', $p['Stok']->Id, 987654321, '1', '1000.00')]],
    ]);

    it('kuantitas desimal diterima untuk satuan kg (bahan baku)', function (): void {
        $b = SiapkanBukuStokUji();
        $hasil = BantuanBuku::CatatMasuk($b['Produk']['BahanBaku']->Id, $b['Gudang']->Id, '2.5', '35626.25');

        expect($hasil->baris['M/1']->saldoSetelah->KeString())->toBe('2.5000')
            ->and((string) $hasil->baris['M/1']->hppSatuan)->toBe('14250.500000');
    });

    it('produk terhapus tidak dikenal untuk mutasi baru', function (): void {
        $b = SiapkanBukuStokUji();
        $b['Produk']['Stok']->delete();

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::CatatMasuk($b['Produk']['Stok']->Id, $b['Gudang']->Id, '1', '1000.00'));

        expect($galat->kode)->toBe('ProdukTidakDikenal');
    });

    it('periode terkunci menolak mutasi bertanggal di periode itu (PeriodeTerkunci, DesainF05a H-9)', function (): void {
        $b = SiapkanBukuStokUji();
        BantuanPersediaan::KunciPeriode('2026-08', $b['Pemilik']->Id);

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat(
            [BantuanBuku::BuatBaris('1', $b['Produk']['Stok']->Id, $b['Gudang']->Id, '5', '192500.00', JenisMutasi::PenyesuaianMasuk)],
            tanggal: '2026-08-31',
        ));
        $bukaSeptember = BantuanBuku::Catat(
            [BantuanBuku::BuatBaris('1', $b['Produk']['Stok']->Id, $b['Gudang']->Id, '5', '192500.00', JenisMutasi::PenyesuaianMasuk)],
            tanggal: '2026-09-01',
        );

        expect($galat->kode)->toBe('PeriodeTerkunci')
            ->and($bukaSeptember->baris)->toHaveCount(1)
            ->and(MutasiStok::query()->count())->toBe(1);
    });
});

describe('F-05a buku stok: isolasi tenant (§13.4)', function (): void {
    it('produk atau lokasi stok milik tenant lain = ProdukTidakDikenal / GudangTidakDikenal, tanpa menulis apa pun', function (): void {
        $lain = SiapkanBukuStokUji(nama: 'Warung Kopi Tetangga');
        $b = SiapkanBukuStokUji();

        $produkLain = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::CatatMasuk($lain['Produk']['Stok']->Id, $b['Gudang']->Id, '1', '1000.00'));
        $gudangLain = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::CatatMasuk($b['Produk']['Stok']->Id, $lain['Gudang']->Id, '1', '1000.00'));

        expect($produkLain->kode)->toBe('ProdukTidakDikenal')
            ->and($gudangLain->kode)->toBe('GudangTidakDikenal')
            ->and(DB::table('MutasiStok')->count())->toBe(0)
            ->and(DB::table('SaldoStok')->count())->toBe(0);
    });

    it('mutasi & saldo tercatat atas nama tenant aktif dan tidak terlihat dari tenant lain', function (): void {
        $lain = SiapkanBukuStokUji(nama: 'Warung Kopi Tetangga');
        BantuanBuku::CatatMasuk($lain['Produk']['Stok']->Id, $lain['Gudang']->Id, '7', '269500.00');
        $b = SiapkanBukuStokUji();
        BantuanBuku::CatatMasuk($b['Produk']['Stok']->Id, $b['Gudang']->Id, '3', '115500.00');

        expect(MutasiStok::query()->count())->toBe(1)
            ->and(SaldoStok::query()->count())->toBe(1)
            ->and(DB::table('MutasiStok')->where('IdTenant', $lain['Tenant']->Id)->count())->toBe(1)
            ->and(BantuanBuku::PeriksaInvarianBuku($b['Tenant']->Id))->toBe([])
            ->and(BantuanBuku::PeriksaInvarianBuku($lain['Tenant']->Id))->toBe([]);

        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(SaldoStok::query()->value('JumlahTersedia'))->toBe('7.0000');
    });
});

describe('F-05a buku stok: kegagalan domain dilempar sebagai PelanggaranAturanBisnis', function (): void {
    it('PelanggaranAturanBisnis membawa KunciBaris di detail', function (): void {
        $b = SiapkanBukuStokUji();
        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('P/9', $b['Produk']['Jasa']->Id, $b['Gudang']->Id, '1', '1000.00')]));

        expect($galat)->toBeInstanceOf(PelanggaranAturanBisnis::class)
            ->and($galat->detail)->toBe(['KunciBaris' => 'P/9']);
    });
});
