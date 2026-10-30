<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Aksi\BatalkanFakturPenjualan;
use App\Domain\Penjualan\Aksi\BatalkanReturGrosir;
use App\Domain\Penjualan\Aksi\BuatFakturPenjualan;
use App\Domain\Penjualan\Aksi\BuatReturGrosir;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisReturGrosir;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataFakturPenjualan;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Data\DataReturGrosir;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Grosir bagian 2 (F-12, §9.7, **BR-12.7**, J-12.4): retur grosir & nota kredit.
 *
 * Yang dikejar di sini bukan "dokumennya tersimpan", tetapi bahwa retur membalik **peristiwa yang sudah terjadi** pada
 * nilai saat itu: stok kembali pada HPP ketika barang keluar, pendapatan masuk akun kontra `ReturPenjualan` (bukan
 * mengurangi `Penjualan` supaya returnya tetap terlihat di laporan), dan lawan kreditnya bergantung apakah tagihannya
 * sudah terbit — `PiutangUsaha` beserta baris `Piutang` (nota kredit) atau `PiutangBelumDifakturkan`.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Sumber Pangan');
    $this->gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kemasan 1 kg'], '15000.00', $this->k['Pcs']);
    $this->satuan = BantuanHarga::SatuanDasar($this->gula);
    BantuanStokAwal::BuatDanPosting(
        $this->k['Gudang'],
        [BantuanStokAwal::Baris($this->gula, '1000', '11000')],
        $this->k['Pemilik']->Id,
        '2026-09-01',
    );
    $this->toko = Pelanggan::query()->create([
        'Nama' => 'Toko Makmur Jaya',
        'NoHp' => '6281355550006',
        'LimitKredit' => '50000000',
        'TerminHari' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Surat jalan terposting 200 pcs, siap diretur. */
function SuratJalanUntukReturUji($tes, string $jumlah = '200', string $diskon = '0'): SuratJalan
{
    $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $tes->toko->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisPesananGrosir($tes->gula->Uuid, $tes->satuan->Uuid, Kuantitas::Dari($jumlah), Uang::Dari($diskon))],
    ), $tes->k['Pemilik']->Id);
    $pesanan = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $tes->k['Pemilik']->Id, true, 'Pelanggan lama, rekam jejak baik');

    return app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
        uuidPesanan: $pesanan->Uuid,
        idGudang: $tes->k['Gudang']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisSuratJalan(1, Kuantitas::Dari($jumlah))],
    ), $tes->k['Pemilik']->Id);
}

/** @param  list<array{0: int, 1: string, 2?: KondisiBarangRetur}>  $baris */
function ReturUji($tes, SuratJalan $suratJalan, array $baris, string $tanggal = '2026-09-27', string $alasan = 'Kemasan sobek saat bongkar', ?string $uuidAkunRefund = null): ReturGrosir
{
    return app(BuatReturGrosir::class)->Jalankan(new DataReturGrosir(
        uuidSuratJalan: $suratJalan->Uuid,
        tanggal: CarbonImmutable::parse($tanggal),
        alasan: $alasan,
        baris: array_map(
            fn (array $b): DataBarisReturGrosir => new DataBarisReturGrosir(
                $b[0],
                Kuantitas::Dari($b[1]),
                $b[2] ?? KondisiBarangRetur::LayakJual,
            ),
            $baris,
        ),
        uuidAkunRefund: $uuidAkunRefund,
    ), $tes->k['Pemilik']->Id);
}

/** @return array{Debit: string, Kredit: string} */
function BarisJurnalReturUji(int $idJurnal, PeranAkun $peran): array
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', BantuanJurnal::IdAkunPeran($peran))->get() as $baris) {
        $debit = $debit->Tambah(Uang::Dari($baris->Debit));
        $kredit = $kredit->Tambah(Uang::Dari($baris->Kredit));
    }

    return ['Debit' => $debit->KeString(), 'Kredit' => $kredit->KeString()];
}

function SisaStokReturUji($tes, ?int $idGudang = null): string
{
    return (string) SaldoStok::query()
        ->where('IdProduk', $tes->gula->Id)
        ->where('IdGudang', $idGudang ?? $tes->k['Gudang']->Id)
        ->value('JumlahTersedia');
}

describe('BuatReturGrosir (BR-12.7, J-12.4)', function (): void {
    it('surat jalan belum difakturkan: stok kembali pada HPP asal dan Piutang Belum Difakturkan yang berkurang', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        expect(SisaStokReturUji($this))->toBe('800.0000');

        $retur = ReturUji($this, $suratJalan, [[1, '50']]);

        $kode = mb_strtoupper($this->k['Outlet']->Kode);
        expect($retur->Nomor)->toBe("RG/{$kode}/2609/0001")
            ->and($retur->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($retur->Total)->toBe('750000.00')
            // HPP saat barang keluar (11.000), bukan HPP berjalan hari ini.
            ->and($retur->TotalHpp)->toBe('550000.00')
            ->and($retur->MengurangiPiutang)->toBeFalse()
            ->and($retur->PerluTinjauan)->toBeFalse()
            ->and(SisaStokReturUji($this))->toBe('850.0000');

        $idJurnal = (int) $retur->IdJurnal;
        expect(BarisJurnalReturUji($idJurnal, PeranAkun::ReturPenjualan))->toBe(['Debit' => '750000.00', 'Kredit' => '0.00'])
            // Belum difakturkan: yang berkurang akun perantara, bukan Piutang Usaha.
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '0.00', 'Kredit' => '750000.00'])
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::PersediaanBarangDagang))->toBe(['Debit' => '550000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::Hpp))->toBe(['Debit' => '0.00', 'Kredit' => '550000.00'])
            // Retur tidak mengurangi `Penjualan`: omzetnya tetap terlihat, returnya punya akun kontra sendiri.
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::Penjualan))->toBe(['Debit' => '0.00', 'Kredit' => '0.00']);

        $detail = SuratJalanDetail::query()->where('IdSuratJalan', $suratJalan->Id)->sole();
        expect($detail->JumlahDiretur)->toBe('50.0000')
            ->and($detail->AmbilSisaRetur()->KeString())->toBe('150.0000')
            // SO tidak diubah: penyerahannya memang pernah terjadi (cermin retur pembelian yang tidak mengubah PO).
            ->and($suratJalan->PesananGrosir->refresh()->Detail()->value('JumlahTerkirim'))->toBe('200.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('sudah difakturkan: nota kredit mengurangi Piutang Usaha dan sisa tagihan fakturnya', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        $faktur = app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$suratJalan->Uuid], CarbonImmutable::parse('2026-09-21')),
            $this->k['Pemilik']->Id,
        );
        $piutang = Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole();
        expect($piutang->AmbilSisa()->KeString())->toBe('3000000.00');

        $retur = ReturUji($this, $suratJalan->refresh(), [[1, '50']]);

        expect($retur->MengurangiPiutang)->toBeTrue()
            ->and($retur->IdFakturPenjualan)->toBe($faktur->Id);

        $idJurnal = (int) $retur->IdJurnal;
        expect(BarisJurnalReturUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '750000.00'])
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '0.00', 'Kredit' => '0.00']);

        // Inilah nota kreditnya: tagihan pembeli berkurang tanpa uang berpindah.
        expect($piutang->refresh()->JumlahDikurangi)->toBe('750000.00')
            ->and($piutang->AmbilSisa()->KeString())->toBe('2250000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('outlet PKP: PPN keluaran dibalik dengan tarif tanggal penyerahan', function (): void {
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanPenjualan::AturProfilPajak($this->k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Grosir: barang kena PPN', ['Ppn' => 'Subtotal'], $this->gula);
        $suratJalan = SuratJalanUntukReturUji($this);

        $retur = ReturUji($this, $suratJalan, [[1, '50']]);

        // 50 x 15.000 = 750.000; PPN 12% pengali DPP 11/12 = efektif 11% = 82.500.
        expect($retur->Subtotal)->toBe('750000.00')
            ->and($retur->DasarPengenaanPajak)->toBe('750000.00')
            ->and($retur->Pajak)->toBe('82500.00')
            ->and($retur->Total)->toBe('832500.00')
            ->and($retur->TarifPpn)->toBe('12.000000');

        // PPN keluaran didebit (kontra): pajak yang tadi terutang ikut berkurang.
        expect(BarisJurnalReturUji((int) $retur->IdJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '82500.00', 'Kredit' => '0.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('barang rusak masuk lokasi stok Rusak, tanpa lokasi Rusak tetap diposting & ditandai perlu ditinjau', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);

        // Outlet belum punya lokasi Rusak: barangnya tetap kembali ke lokasi asal, dokumennya ditandai.
        $tanpaLokasi = ReturUji($this, $suratJalan, [[1, '10', KondisiBarangRetur::Rusak]]);
        expect($tanpaLokasi->PerluTinjauan)->toBeTrue()
            ->and($tanpaLokasi->AlasanTinjauan)->toContain('LokasiRusakTidakAda')
            ->and(SisaStokReturUji($this))->toBe('810.0000');

        $gudangRusak = BantuanPersediaan::BuatGudang($this->k['Outlet'], 'Barang Rusak', JenisGudang::Rusak);
        $keLokasiRusak = ReturUji($this, $suratJalan->refresh(), [[1, '10', KondisiBarangRetur::Rusak]]);

        expect($keLokasiRusak->PerluTinjauan)->toBeFalse()
            ->and($keLokasiRusak->Detail[0]->IdGudang)->toBe($gudangRusak->Id)
            // Stok layak jual tidak bertambah; yang bertambah lokasi Rusak.
            ->and(SisaStokReturUji($this))->toBe('810.0000')
            ->and(SisaStokReturUji($this, $gudangRusak->Id))->toBe('10.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('diskon baris dialokasikan sebanding, dan retur penutup menerima sisanya', function (): void {
        // 100 pcs, diskon baris 150.000 (per baris SO, ikut ke surat jalan).
        $suratJalan = SuratJalanUntukReturUji($this, '100', '150000');
        expect($suratJalan->Diskon)->toBe('150000.00');

        $sebagian = ReturUji($this, $suratJalan, [[1, '60']]);
        $penutup = ReturUji($this, $suratJalan->refresh(), [[1, '40']]);

        expect($sebagian->Diskon)->toBe('90000.00')
            ->and($penutup->Diskon)->toBe('60000.00')
            // Seluruh barang kembali: Σ diskon retur tepat sama dengan diskon surat jalannya.
            ->and(Uang::Dari($sebagian->Diskon)->Tambah(Uang::Dari($penutup->Diskon))->KeString())->toBe($suratJalan->Diskon)
            ->and(Uang::Dari($sebagian->Total)->Tambah(Uang::Dari($penutup->Total))->KeString())->toBe($suratJalan->Total)
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('menolak melebihi sisa, baris tak dikenal, jumlah nol, tanggal sebelum penyerahan, alasan pendek', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        ReturUji($this, $suratJalan, [[1, '150']]);

        $kasus = [
            ['JumlahReturMelebihiSisa', fn (): ReturGrosir => ReturUji($this, $suratJalan->refresh(), [[1, '60']])],
            ['BarisTidakDikenal', fn (): ReturGrosir => ReturUji($this, $suratJalan->refresh(), [[9, '1']])],
            ['JumlahReturTidakValid', fn (): ReturGrosir => ReturUji($this, $suratJalan->refresh(), [[1, '0']])],
            ['TanggalReturSebelumPenyerahan', fn (): ReturGrosir => ReturUji($this, $suratJalan->refresh(), [[1, '1']], '2026-09-19')],
            ['AlasanWajib', fn (): ReturGrosir => ReturUji($this, $suratJalan->refresh(), [[1, '1']], '2026-09-27', 'oke')],
        ];

        foreach ($kasus as [$kode, $jalankan]) {
            try {
                $jalankan();
                $this->fail("Seharusnya ditolak dengan {$kode}.");
            } catch (PelanggaranAturanBisnis $galat) {
                expect($galat->kode)->toBe($kode);
            }
        }
    });

    it('sisa tagihan tidak cukup: ditolak tanpa akun pengembalian uang, tidak ada dokumen setengah jadi', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        $faktur = app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$suratJalan->Uuid], CarbonImmutable::parse('2026-09-21')),
            $this->k['Pemilik']->Id,
        );

        // Pembeli sudah melunasi hampir semuanya; sisa tagihan 100.000.
        $piutang = Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole();
        $piutang->JumlahDibayar = '2900000.00';
        $piutang->SelaraskanStatus();
        $piutang->save();

        try {
            ReturUji($this, $suratJalan->refresh(), [[1, '50']]);
            $this->fail('Retur 750.000 atas sisa tagihan 100.000 tanpa akun pengembalian uang seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('AkunRefundWajib');
        }

        // Tidak ada dokumen setengah jadi yang tertinggal, dan piutangnya tidak tersentuh.
        expect(ReturGrosir::query()->count())->toBe(0)
            ->and($piutang->refresh()->JumlahDikurangi)->toBe('0.00')
            ->and(SisaStokReturUji($this))->toBe('800.0000');
    });

    it('faktur yang punya retur grosir berlaku tidak bisa dibatalkan sampai retur itu dibatalkan lebih dulu (PiutangUsaha tidak boleh minus)', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        $faktur = app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$suratJalan->Uuid], CarbonImmutable::parse('2026-09-21')),
            $this->k['Pemilik']->Id,
        );
        $retur = ReturUji($this, $suratJalan->refresh(), [[1, '50']]);

        try {
            app(BatalkanFakturPenjualan::class)->Jalankan($faktur->Uuid, 'Salah pelanggan di fakturnya', $this->k['Pemilik']->Id);
            $this->fail('Faktur dengan retur grosir yang masih berlaku seharusnya tidak bisa dibatalkan.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('FakturPunyaReturGrosir')
                ->and($galat->getMessage())->toContain($retur->Nomor);
        }

        // Tidak ada yang setengah jalan: faktur tetap berlaku, piutang dan surat jalan tidak tersentuh.
        $piutang = Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole();
        expect($faktur->refresh()->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($piutang->AmbilSisa()->KeString())->toBe('2250000.00')
            ->and($suratJalan->refresh()->IdFakturPenjualan)->toBe($faktur->Id);

        // Urutan yang benar: retur dibatalkan dulu, sesudah itu faktur boleh dibatalkan dan angkanya kembali utuh.
        app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Pembeli mengambil kembali barangnya', $this->k['Pemilik']->Id);
        $dibatalkan = app(BatalkanFakturPenjualan::class)->Jalankan($faktur->Uuid, 'Salah pelanggan di fakturnya', $this->k['Pemilik']->Id);

        expect($dibatalkan->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(BarisJurnalReturUji((int) $dibatalkan->IdJurnalPembatalan, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '3000000.00', 'Kredit' => '0.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('BR-12.7: nilai retur melebihi sisa tagihan → sisa tagihan terpotong, selebihnya dikembalikan sebagai uang dari kas/bank (J-12.4 seimbang), pembatalan membalik keduanya', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        $faktur = app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$suratJalan->Uuid], CarbonImmutable::parse('2026-09-21')),
            $this->k['Pemilik']->Id,
        );
        $piutang = Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole();
        $piutang->JumlahDibayar = '2900000.00';
        $piutang->SelaraskanStatus();
        $piutang->save();
        $kas = Akun::query()->where('KasBank', true)->where('Aktif', true)->orderBy('Kode')->firstOrFail();

        $retur = ReturUji($this, $suratJalan->refresh(), [[1, '50']], uuidAkunRefund: $kas->Uuid);

        expect($retur->MengurangiPiutang)->toBeTrue()
            ->and($retur->Total)->toBe('750000.00')
            ->and($retur->NilaiRefund)->toBe('650000.00')
            ->and($retur->IdAkunRefund)->toBe($kas->Id);
        $piutang->refresh();
        expect($piutang->JumlahDikurangi)->toBe('100000.00')
            ->and($piutang->AmbilSisa()->KeString())->toBe('0.00')
            ->and($piutang->Status)->toBe(StatusPiutang::Lunas);

        $idJurnal = (int) $retur->IdJurnal;
        $barisKas = JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $kas->Id)->get();
        expect($barisKas)->toHaveCount(1)
            ->and($barisKas->first()?->Kredit)->toBe('650000.00')
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '100000.00'])
            ->and(BarisJurnalReturUji($idJurnal, PeranAkun::ReturPenjualan))->toBe(['Debit' => '750000.00', 'Kredit' => '0.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);

        // Akun yang bukan kas/bank ditolak.
        $bukanKas = Akun::query()->where('KasBank', false)->orderBy('Kode')->firstOrFail();

        try {
            ReturUji($this, $suratJalan->refresh(), [[1, '10']], uuidAkunRefund: $bukanKas->Uuid);
            $this->fail('Akun yang bukan kas/bank seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('AkunRefundWajib');
        }

        $dibatalkan = app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Pembeli batal mengembalikan barang', $this->k['Pemilik']->Id);
        $idPembalik = (int) $dibatalkan->IdJurnalPembatalan;
        $pembalikKas = JurnalDetail::query()->where('IdJurnal', $idPembalik)->where('IdAkun', $kas->Id)->get();
        expect($pembalikKas)->toHaveCount(1)
            ->and($pembalikKas->first()?->Debit)->toBe('650000.00')
            ->and(BarisJurnalReturUji($idPembalik, PeranAkun::PiutangUsaha))->toBe(['Debit' => '100000.00', 'Kredit' => '0.00'])
            ->and($piutang->refresh()->JumlahDikurangi)->toBe('0.00')
            ->and($piutang->AmbilSisa()->KeString())->toBe('100000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });
});

describe('BatalkanReturGrosir', function (): void {
    it('mengeluarkan stok kembali, membalik J-12.4, memulihkan tagihan, dan idempoten', function (): void {
        $suratJalan = SuratJalanUntukReturUji($this);
        $faktur = app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$suratJalan->Uuid], CarbonImmutable::parse('2026-09-21')),
            $this->k['Pemilik']->Id,
        );
        $retur = ReturUji($this, $suratJalan->refresh(), [[1, '50']]);
        $piutang = Piutang::query()->where('IdFakturPenjualan', $faktur->Id)->sole();
        expect($piutang->AmbilSisa()->KeString())->toBe('2250000.00');

        $dibatalkan = app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Pembeli mengambil kembali barangnya', $this->k['Pemilik']->Id);

        expect($dibatalkan->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and($dibatalkan->IdJurnalPembatalan)->not->toBeNull()
            ->and(SisaStokReturUji($this))->toBe('800.0000');

        $idPembalik = (int) $dibatalkan->IdJurnalPembatalan;
        expect(BarisJurnalReturUji($idPembalik, PeranAkun::ReturPenjualan))->toBe(['Debit' => '0.00', 'Kredit' => '750000.00'])
            ->and(BarisJurnalReturUji($idPembalik, PeranAkun::PiutangUsaha))->toBe(['Debit' => '750000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalReturUji($idPembalik, PeranAkun::PersediaanBarangDagang))->toBe(['Debit' => '0.00', 'Kredit' => '550000.00']);

        // Nota kreditnya dicabut: tagihan kembali penuh, dan barisnya bisa diretur lagi.
        expect($piutang->refresh()->AmbilSisa()->KeString())->toBe('3000000.00')
            ->and(SuratJalanDetail::query()->where('IdSuratJalan', $suratJalan->Id)->value('JumlahDiretur'))->toBe('0.0000');

        $ulang = app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Pembeli mengambil kembali barangnya', $this->k['Pemilik']->Id);
        expect($ulang->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SisaStokReturUji($this))->toBe('800.0000')
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', ReturGrosir::JENIS_DOKUMEN)->where('IdDokumen', $retur->Id)->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });
});
