<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BatalkanSuratJalan;
use App\Domain\Penjualan\Aksi\BuatFakturPenjualan;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataFakturPenjualan;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalan;
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
 * Grosir bagian 1 tahap 3 (F-12, §9.7, **BR-12.2**, J-12.1/J-12.3): surat jalan sebagai **titik pengakuan**.
 *
 * Yang diuji di sini bukan sekadar "dokumen tersimpan", tetapi bahwa penyerahan barang benar-benar memindahkan nilai:
 * stok keluar pada HPP berjalan, HPP & pendapatan diakui, PPN keluaran terutang pada tanggal penyerahan, dan lawannya
 * masuk `PiutangBelumDifakturkan` — bukan `PiutangUsaha`, karena tagihannya belum pernah dikirim ke pembeli.
 *
 * Invarian wajib (aturan #17) diperiksa lewat `PemeriksaInvarian::PeriksaSemua()`: Σ debit = Σ kredit,
 * `SaldoStok` = Σ `MutasiStok`, dan saldo akun persediaan = Σ nilai stok.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Sumber Pangan');
    $this->gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kemasan 1 kg'], '15000.00', $this->k['Pcs']);
    $this->satuan = BantuanHarga::SatuanDasar($this->gula);
    BantuanStokAwal::BuatDanPosting(
        $this->k['Gudang'],
        [BantuanStokAwal::Baris($this->gula, '500', '11000')],
        $this->k['Pemilik']->Id,
        '2026-09-20',
    );
    $this->toko = Pelanggan::query()->create([
        'Nama' => 'Toko Makmur Jaya',
        'NoHp' => '6281355550002',
        'LimitKredit' => '5000000',
        'TerminHari' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * SO grosir yang sudah dikonfirmasi dan siap dikirim.
 *
 * @param  list<array{Jumlah: string, Diskon?: string}>  $baris
 */
function SoSiapKirimUji($tes, array $baris): PesananGrosir
{
    $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $tes->toko->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-27'),
        baris: array_map(fn (array $b): DataBarisPesananGrosir => new DataBarisPesananGrosir(
            $tes->gula->Uuid,
            $tes->satuan->Uuid,
            Kuantitas::Dari($b['Jumlah']),
            Uang::Dari($b['Diskon'] ?? '0'),
        ), $baris),
    ), $tes->k['Pemilik']->Id);

    return app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $tes->k['Pemilik']->Id, true, 'Pelanggan lama, rekam jejak baik');
}

/** @param  list<array{0: int, 1: string}>  $baris  [Urutan baris SO, jumlah kirim] */
function KirimUji($tes, PesananGrosir $pesanan, array $baris, string $tanggal = '2026-09-27'): SuratJalan
{
    return app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
        uuidPesanan: $pesanan->Uuid,
        idGudang: $tes->k['Gudang']->Id,
        tanggal: CarbonImmutable::parse($tanggal),
        baris: array_map(fn (array $b): DataBarisSuratJalan => new DataBarisSuratJalan($b[0], Kuantitas::Dari($b[1])), $baris),
        namaPengirim: 'Sopir Pak Slamet',
        nomorKendaraan: 'AD 8123 XY',
    ), $tes->k['Pemilik']->Id);
}

/** @return array{Debit: string, Kredit: string} */
function BarisJurnalPeranUji(int $idJurnal, PeranAkun $peran): array
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', BantuanJurnal::IdAkunPeran($peran))->get() as $baris) {
        $debit = $debit->Tambah(Uang::Dari($baris->Debit));
        $kredit = $kredit->Tambah(Uang::Dari($baris->Kredit));
    }

    return ['Debit' => $debit->KeString(), 'Kredit' => $kredit->KeString()];
}

function SisaStokUji($tes): string
{
    return (string) SaldoStok::query()
        ->where('IdProduk', $tes->gula->Id)
        ->where('IdGudang', $tes->k['Gudang']->Id)
        ->value('JumlahTersedia');
}

describe('KirimPesananGrosir: penyerahan sebagai titik pengakuan (BR-12.2, J-12.1)', function (): void {
    it('mengeluarkan stok, mengakui HPP & pendapatan, dan menaruh lawannya di Piutang Belum Difakturkan', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '200']]);

        $suratJalan = KirimUji($this, $pesanan, [[1, '200']]);

        $kode = mb_strtoupper($this->k['Outlet']->Kode);
        expect($suratJalan->Nomor)->toBe("SJ/{$kode}/2609/0001")
            ->and($suratJalan->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($suratJalan->Total)->toBe('3000000.00')
            // HPP berjalan dari stok awal 11.000/pcs; harga jualnya 15.000 dan itu dua angka yang berbeda.
            ->and($suratJalan->TotalHpp)->toBe('2200000.00')
            ->and($suratJalan->Detail[0]->HppSatuan)->toBe('11000.000000')
            ->and($suratJalan->IdJurnal)->not->toBeNull()
            // Belum difakturkan: itu yang membuat barisnya jatuh di akun perantara, bukan Piutang Usaha.
            ->and($suratJalan->IdFakturPenjualan)->toBeNull()
            ->and(SisaStokUji($this))->toBe('300.0000');

        $idJurnal = (int) $suratJalan->IdJurnal;
        expect(BarisJurnalPeranUji($idJurnal, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '3000000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::Penjualan))->toBe(['Debit' => '0.00', 'Kredit' => '3000000.00'])
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::Hpp))->toBe(['Debit' => '2200000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::PersediaanBarangDagang))->toBe(['Debit' => '0.00', 'Kredit' => '2200000.00'])
            // Piutang Usaha belum tersentuh: tidak ada tagihan yang bisa ditagih sebelum faktur terbit.
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '0.00']);

        expect(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);

        $pesanan->refresh();
        expect($pesanan->Status)->toBe(StatusPesananGrosir::Selesai)
            ->and($pesanan->SelesaiPada)->not->toBeNull()
            ->and(PesananGrosirDetail::query()->where('IdPesananGrosir', $pesanan->Id)->value('JumlahTerkirim'))->toBe('200.0000');
    });

    it('outlet PKP: PPN keluaran terutang saat penyerahan dengan tarif tanggal penyerahan', function (): void {
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanPenjualan::AturProfilPajak($this->k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Grosir: barang kena PPN', ['Ppn' => 'Subtotal'], $this->gula);
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '200']]);

        $suratJalan = KirimUji($this, $pesanan, [[1, '200']]);

        // 200 x 15.000 = 3.000.000; PPN 12% dengan pengali DPP 11/12 = efektif 11% = 330.000 (PP 44/2022).
        expect($suratJalan->Subtotal)->toBe('3000000.00')
            ->and($suratJalan->DasarPengenaanPajak)->toBe('3000000.00')
            ->and($suratJalan->Pajak)->toBe('330000.00')
            ->and($suratJalan->Total)->toBe('3330000.00')
            // Snapshot tarif & pengali DPP ada di dokumennya sendiri, bahan Faktur Pajak nanti.
            ->and($suratJalan->TarifPpn)->toBe('12.000000')
            ->and($suratJalan->PengaliDppPembilang)->toBe(11)
            ->and($suratJalan->PengaliDppPenyebut)->toBe(12)
            ->and($suratJalan->RincianPajak)->toBe(['Ppn' => '330000.00']);

        $idJurnal = (int) $suratJalan->IdJurnal;
        expect(BarisJurnalPeranUji($idJurnal, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '3330000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::Penjualan))->toBe(['Debit' => '0.00', 'Kredit' => '3000000.00'])
            // PPN terutang di penyerahan, bukan menunggu faktur (UU PPN Pasal 11 ayat 1).
            ->and(BarisJurnalPeranUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '330000.00']);

        expect(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('pengiriman bertahap: diskon baris dibagi sebanding dan pengiriman penutup menerima sisanya', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '100', 'Diskon' => '150000']]);
        expect($pesanan->Total)->toBe('1350000.00');

        $satu = KirimUji($this, $pesanan, [[1, '60']]);
        $pesanan->refresh();

        expect($satu->Diskon)->toBe('90000.00')
            ->and($satu->Total)->toBe('810000.00')
            ->and($pesanan->Status)->toBe(StatusPesananGrosir::SebagianDikirim)
            ->and($pesanan->SelesaiPada)->toBeNull()
            ->and(SisaStokUji($this))->toBe('440.0000');

        $dua = KirimUji($this, $pesanan, [[1, '40']]);
        $pesanan->refresh();

        expect($dua->Diskon)->toBe('60000.00')
            ->and($dua->Total)->toBe('540000.00')
            // Σ surat jalan = SO, tanpa selisih pembulatan yang menempel di akun perantara.
            ->and(Uang::Dari($satu->Total)->Tambah(Uang::Dari($dua->Total))->KeString())->toBe($pesanan->Total)
            ->and($pesanan->Status)->toBe(StatusPesananGrosir::Selesai)
            ->and(SisaStokUji($this))->toBe('400.0000');

        expect(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('menolak kirim melebihi sisa pesanan, dan SO yang belum dikonfirmasi', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '100']]);
        KirimUji($this, $pesanan, [[1, '80']]);

        try {
            // Sisa baris tinggal 20; 30 harus ditolak, bukan dipotong diam-diam ke 20.
            KirimUji($this, $pesanan->refresh(), [[1, '30']]);
            $this->fail('Kirim 30 dari sisa 20 seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('JumlahKirimMelebihiSisa');
        }

        $draf = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
            uuidPelanggan: $this->toko->Uuid,
            idOutlet: $this->k['Outlet']->Id,
            tanggal: CarbonImmutable::parse('2026-09-27'),
            baris: [new DataBarisPesananGrosir($this->gula->Uuid, $this->satuan->Uuid, Kuantitas::Dari('5'), Uang::Nol())],
        ), $this->k['Pemilik']->Id);

        try {
            KirimUji($this, $draf, [[1, '5']]);
            $this->fail('Surat jalan atas SO draf seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('PesananBelumBisaDikirim');
        }
    });

    it('menolak baris yang tidak ada di pesanan dan jumlah nol', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '10']]);

        try {
            KirimUji($this, $pesanan, [[9, '1']]);
            $this->fail('Baris nomor 9 tidak ada di pesanan. Seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('BarisTidakDikenal');
        }

        try {
            KirimUji($this, $pesanan, [[1, '0']]);
            $this->fail('Jumlah kirim nol seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('JumlahKirimTidakValid');
        }
    });
});

describe('BatalkanSuratJalan (J-12.3)', function (): void {
    it('mengembalikan stok, membalik seluruh baris J-12.1, dan memundurkan status SO', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '200']]);
        $suratJalan = KirimUji($this, $pesanan, [[1, '200']]);

        $dibatalkan = app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Barang ditolak pembeli di lokasi', $this->k['Pemilik']->Id);

        expect($dibatalkan->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and($dibatalkan->AlasanBatal)->toBe('Barang ditolak pembeli di lokasi')
            ->and($dibatalkan->IdJurnalPembatalan)->not->toBeNull()
            ->and(SisaStokUji($this))->toBe('500.0000');

        $idPembalik = (int) $dibatalkan->IdJurnalPembatalan;
        expect(BarisJurnalPeranUji($idPembalik, PeranAkun::PiutangBelumDifakturkan))->toBe(['Debit' => '0.00', 'Kredit' => '3000000.00'])
            ->and(BarisJurnalPeranUji($idPembalik, PeranAkun::Penjualan))->toBe(['Debit' => '3000000.00', 'Kredit' => '0.00'])
            ->and(BarisJurnalPeranUji($idPembalik, PeranAkun::Hpp))->toBe(['Debit' => '0.00', 'Kredit' => '2200000.00'])
            ->and(BarisJurnalPeranUji($idPembalik, PeranAkun::PersediaanBarangDagang))->toBe(['Debit' => '2200000.00', 'Kredit' => '0.00']);

        $pesanan->refresh();
        expect($pesanan->Status)->toBe(StatusPesananGrosir::Dikonfirmasi)
            ->and($pesanan->SelesaiPada)->toBeNull()
            ->and(PesananGrosirDetail::query()->where('IdPesananGrosir', $pesanan->Id)->value('JumlahTerkirim'))->toBe('0.0000');

        expect(RiwayatStatusDokumen::query()->where('JenisDokumen', SuratJalan::JENIS_DOKUMEN)->where('IdDokumen', $suratJalan->Id)->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('idempoten, menuntut alasan, dan menolak surat jalan yang sudah difakturkan', function (): void {
        $pesanan = SoSiapKirimUji($this, [['Jumlah' => '100']]);
        $suratJalan = KirimUji($this, $pesanan, [[1, '100']]);

        try {
            app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'oke', $this->k['Pemilik']->Id);
            $this->fail('Alasan 4 karakter seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('AlasanBatalWajib');
        }

        app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Salah kirim ke alamat cabang lain', $this->k['Pemilik']->Id);
        $ulang = app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Salah kirim ke alamat cabang lain', $this->k['Pemilik']->Id);

        // Pemutaran ulang tidak membalik dua kali: stok dan riwayat tetap satu langkah.
        expect($ulang->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SisaStokUji($this))->toBe('500.0000')
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', SuratJalan::JENIS_DOKUMEN)->where('IdDokumen', $suratJalan->Id)->count())->toBe(1);

        $lain = KirimUji($this, $pesanan->refresh(), [[1, '100']]);
        app(BuatFakturPenjualan::class)->Jalankan(
            new DataFakturPenjualan([$lain->Uuid], CarbonImmutable::parse('2026-09-27')),
            $this->k['Pemilik']->Id,
        );

        try {
            app(BatalkanSuratJalan::class)->Jalankan($lain->Uuid, 'Pembeli membatalkan pesanan', $this->k['Pemilik']->Id);
            $this->fail('Surat jalan yang sudah difakturkan seharusnya ditolak.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('SudahDifakturkan');
        }
    });
});

describe('BR-12.6: paparan kredit termasuk barang yang sudah diserahkan tetapi belum difakturkan', function (): void {
    it('menolak SO kedua karena barang SO pertama sudah keluar gudang walau belum difakturkan', function (): void {
        // Limit Rp 5.000.000. SO pertama Rp 3.000.000 dikirim penuh, jadi SO itu Selesai dan tidak lagi terhitung
        // sebagai "belum terkirim" — satu-satunya yang menahan SO kedua adalah bucket surat jalan belum difakturkan.
        $pertama = SoSiapKirimUji($this, [['Jumlah' => '200']]);
        KirimUji($this, $pertama, [[1, '200']]);
        expect($pertama->refresh()->Status)->toBe(StatusPesananGrosir::Selesai);

        $kedua = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
            uuidPelanggan: $this->toko->Uuid,
            idOutlet: $this->k['Outlet']->Id,
            tanggal: CarbonImmutable::parse('2026-09-27'),
            baris: [new DataBarisPesananGrosir($this->gula->Uuid, $this->satuan->Uuid, Kuantitas::Dari('200'), Uang::Nol())],
        ), $this->k['Pemilik']->Id);

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($kedua->Uuid, $this->k['Pemilik']->Id, false);
            $this->fail('SO kedua seharusnya butuh persetujuan kredit: 3jt + 3jt melewati limit 5jt.');
        } catch (PelanggaranAturanBisnis $galat) {
            expect($galat->kode)->toBe('ButuhPersetujuanKredit');
        }

        // Dengan izin & alasan, tetap bisa dikonfirmasi — dan paparannya tercatat di audit sebagai 6.000.000.
        $disetujui = app(KonfirmasiPesananGrosir::class)->Jalankan($kedua->Uuid, $this->k['Pemilik']->Id, true, 'Disetujui pemilik, pembayaran tunai menyusul');
        expect($disetujui->Status)->toBe(StatusPesananGrosir::Dikonfirmasi)
            ->and($disetujui->IdPenyetujuKredit)->toBe($this->k['Pemilik']->Id);
    });
});
