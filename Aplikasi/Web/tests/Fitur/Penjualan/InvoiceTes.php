<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Aksi\BatalkanInvoice;
use App\Domain\Penjualan\Aksi\CatatPembayaranInvoice;
use App\Domain\Penjualan\Aksi\SimpanInvoice;
use App\Domain\Penjualan\Aksi\TerbitkanInvoice;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Invoice & penagihan B2B (F-21, D-166, J-21.1/J-21.2): invoice penjualan jasa/barang tanpa stok yang mengakui pendapatannya
 * sendiri saat terbit (tanpa surat jalan), invoice uang muka (DP) beserta pemotongannya, PPh yang dipotong pelanggan saat
 * membayar, pembatalan dengan jurnal pembalik, dan jaminan bahwa tidak ada dokumen terbit yang bisa diubah.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Maju Bersama');
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Sumber Makmur Sentosa', 'NoHp' => '6281355550101', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa konsultasi manajemen per bulan', 'Jenis' => JenisProduk::Jasa], '5000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function PakaiPpnUji($tes, bool $pkp = true): void
{
    BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
    BantuanPenjualan::AturProfilPajak($tes->k, pkp: $pkp);
    $tes->kelompokPpn = BantuanPenjualan::PasangKelompokPajak('Jasa kena PPN', ['Ppn' => 'Subtotal']);
}

function BarisJasaUji(string $nama = 'Konsultasi manajemen', string $jumlah = '1', string $harga = '10000000.00', string $diskon = '0.00', ?string $uuidKelompok = null, JenisBarisInvoice $jenis = JenisBarisInvoice::Jasa, ?string $uuidProduk = null): DataBarisInvoice
{
    return new DataBarisInvoice($uuidProduk, $nama, null, $jenis, 'bln', Kuantitas::Dari($jumlah), Uang::Dari($harga), Uang::Dari($diskon), $uuidKelompok);
}

/**
 * @param  list<DataBarisInvoice>|null  $baris
 * @param  list<string>  $uangMuka
 */
function DrafInvoiceUji($tes, ?array $baris = null, string $tanggal = '2026-09-27', JenisInvoice $jenis = JenisInvoice::Penjualan, ?Pelanggan $pelanggan = null, array $uangMuka = [], ?string $kodePph = null, ?string $tarifPph = null, bool $inklusif = false): Invoice
{
    return app(SimpanInvoice::class)->Jalankan(new DataInvoice(
        jenis: $jenis,
        uuidPelanggan: ($pelanggan ?? $tes->klien)->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse($tanggal),
        terminHari: null,
        baris: $baris ?? [BarisJasaUji()],
        hargaTermasukPajak: $inklusif,
        perihal: 'Jasa konsultasi September 2026',
        nomorReferensi: 'PO-2026/09/118',
        kodePph: $kodePph,
        tarifPph: $tarifPph,
        uuidUangMuka: $uangMuka,
    ), $tes->k['Pemilik']->Id);
}

function TerbitInvoiceUji($tes, Invoice $draf): Invoice
{
    return app(TerbitkanInvoice::class)->Jalankan($draf->Uuid, $tes->k['Pemilik']->Id);
}

/** @return array{Debit: string, Kredit: string} */
function JurnalPeranInvoiceUji(int $idJurnal, PeranAkun $peran): array
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', BantuanJurnal::IdAkunPeran($peran))->get() as $baris) {
        $debit = $debit->Tambah(Uang::Dari($baris->Debit));
        $kredit = $kredit->Tambah(Uang::Dari($baris->Kredit));
    }

    return ['Debit' => $debit->KeString(), 'Kredit' => $kredit->KeString()];
}

function JurnalSeimbangInvoiceUji(int $idJurnal): bool
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->get() as $baris) {
        $debit = $debit->Tambah(Uang::Dari((string) $baris->Debit));
        $kredit = $kredit->Tambah(Uang::Dari((string) $baris->Kredit));
    }

    return $debit->SamaDengan($kredit) && ! $debit->BernilaiNol();
}

function GalatInvoiceUji(callable $aksi): ?string
{
    try {
        $aksi();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

describe('SimpanInvoice (draf)', function (): void {
    it('draf belum bernomor, belum berjurnal, belum berpiutang; angkanya dihitung server', function (): void {
        $jurnalSebelum = Jurnal::query()->count();
        $draf = DrafInvoiceUji($this, [BarisJasaUji('Konsultasi manajemen', '2', '5000000.00', '500000.00')]);

        expect($draf->Status)->toBe(StatusInvoice::Draf)
            ->and($draf->Nomor)->toBeNull()
            ->and($draf->IdJurnal)->toBeNull()
            ->and($draf->Subtotal)->toBe('10000000.00')
            ->and($draf->Diskon)->toBe('500000.00')
            ->and($draf->Total)->toBe('9500000.00')
            ->and($draf->Tagihan)->toBe('9500000.00')
            // Termin ikut pelanggan (14 hari) bila tidak diisi.
            ->and($draf->TerminHari)->toBe(14)
            ->and($draf->JatuhTempo->toDateString())->toBe('2026-10-11')
            ->and(strlen($draf->KodePublik))->toBe(32)
            ->and(Piutang::query()->where('IdInvoice', $draf->Id)->count())->toBe(0)
            ->and(Jurnal::query()->count())->toBe($jurnalSebelum);
    });

    it('draf bisa diganti isinya; setelah terbit ditolak', function (): void {
        $draf = DrafInvoiceUji($this);
        $ubah = app(SimpanInvoice::class)->Jalankan(new DataInvoice(
            jenis: JenisInvoice::Penjualan,
            uuidPelanggan: $this->klien->Uuid,
            idOutlet: $this->k['Outlet']->Id,
            tanggal: CarbonImmutable::parse('2026-09-27'),
            terminHari: 30,
            baris: [BarisJasaUji('Audit internal', '1', '7500000.00')],
        ), $this->k['Pemilik']->Id, $draf->Uuid);

        expect($ubah->Total)->toBe('7500000.00')
            ->and($ubah->TerminHari)->toBe(30)
            ->and($ubah->Detail)->toHaveCount(1)
            ->and($ubah->Detail->first()->Nama)->toBe('Audit internal');

        TerbitInvoiceUji($this, $ubah);

        expect(GalatInvoiceUji(fn () => app(SimpanInvoice::class)->Jalankan(new DataInvoice(
            jenis: JenisInvoice::Penjualan,
            uuidPelanggan: $this->klien->Uuid,
            idOutlet: $this->k['Outlet']->Id,
            tanggal: CarbonImmutable::parse('2026-09-27'),
            terminHari: null,
            baris: [BarisJasaUji()],
        ), $this->k['Pemilik']->Id, $draf->Uuid)))->toBe('InvoiceTidakBisaDiubah');
    });

    it('barang berstok ditolak dengan alasan; jasa dan tanpa stok dari katalog diterima', function (): void {
        $stok = BantuanKatalog::BuatProduk(['Nama' => 'Gula pasir 1 kg'], '15000.00');
        $tanpaStok = BantuanKatalog::BuatProduk(['Nama' => 'Voucher layanan', 'Jenis' => JenisProduk::NonStok], '100000.00');

        expect(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [BarisJasaUji('Gula', uuidProduk: $stok->Uuid, jenis: JenisBarisInvoice::Barang)])))->toBe('ProdukBerstok');

        $draf = DrafInvoiceUji($this, [
            BarisJasaUji('Konsultasi', uuidProduk: $this->jasa->Uuid),
            BarisJasaUji('Voucher', '3', '100000.00', uuidProduk: $tanpaStok->Uuid, jenis: JenisBarisInvoice::Barang),
        ]);

        expect($draf->Detail)->toHaveCount(2)->and($draf->Total)->toBe('10300000.00');
    });

    it('validasi isian: tanpa baris, jumlah nol, diskon melebihi baris, pelanggan diarsipkan, PPh tidak lengkap', function (): void {
        expect(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [])))->toBe('InvoiceTanpaBaris')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [BarisJasaUji(jumlah: '0')])))->toBe('BarisTidakValid')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [BarisJasaUji(harga: '1000.00', diskon: '2000.00')])))->toBe('BarisTidakValid')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, kodePph: 'PPh23')))->toBe('PphTidakValid')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, kodePph: 'PPh23', tarifPph: '150')))->toBe('PphTidakValid');

        $this->klien->forceFill(['Status' => 'Diarsipkan'])->save();

        expect(GalatInvoiceUji(fn () => DrafInvoiceUji($this)))->toBe('PelangganNonaktif');
    });
});

describe('TerbitkanInvoice (J-21.1)', function (): void {
    it('mengakui pendapatan jasa & piutang tanpa PPN bila outlet bukan PKP; nomor INV/{OUTLET}/{YYMM}/{SEQ4}', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this));

        $kode = mb_strtoupper($this->k['Outlet']->Kode);
        expect($invoice->Status)->toBe(StatusInvoice::Terbit)
            ->and($invoice->Nomor)->toBe("INV/{$kode}/2609/0001")
            ->and($invoice->Total)->toBe('10000000.00');

        $idJurnal = (int) $invoice->IdJurnal;
        expect(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '10000000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '0.00']);

        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->sole();
        expect($piutang->Nomor)->toBe($invoice->Nomor)
            ->and($piutang->Jumlah)->toBe('10000000.00')
            ->and($piutang->Status)->toBe(StatusPiutang::BelumLunas)
            ->and($piutang->JatuhTempo->toDateString())->toBe('2026-10-11')
            ->and($piutang->IdFakturPenjualan)->toBeNull()
            ->and($piutang->IdPenjualan)->toBeNull();
    });

    it('outlet PKP: PPN keluaran 12% dengan DPP nilai lain 11/12 = 11% efektif, total = DPP + PPN', function (): void {
        PakaiPpnUji($this);
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(uuidKelompok: $this->kelompokPpn->Uuid)]));

        expect($invoice->DasarPengenaanPajak)->toBe('10000000.00')
            ->and($invoice->Pajak)->toBe('1100000.00')
            ->and($invoice->Total)->toBe('11100000.00')
            ->and($invoice->TarifPpn)->toBe('12.000000')
            ->and($invoice->RincianPajak)->toBe(['Ppn' => '1100000.00']);

        $idJurnal = (int) $invoice->IdJurnal;
        expect(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '11100000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '1100000.00']);
    });

    it('harga sudah termasuk PPN: pendapatan = bruto − PPN inklusif, jurnal seimbang', function (): void {
        PakaiPpnUji($this);
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(harga: '11100000.00', uuidKelompok: $this->kelompokPpn->Uuid)], inklusif: true));

        expect($invoice->Total)->toBe('11100000.00')
            ->and($invoice->Pajak)->toBe('1100000.00');

        $idJurnal = (int) $invoice->IdJurnal;
        expect(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '1100000.00']);
    });

    it('diskon baris didebit ke Diskon Penjualan; jasa ke Pendapatan Jasa dan barang tanpa stok ke Penjualan', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [
            BarisJasaUji('Konsultasi', '1', '10000000.00', '1000000.00'),
            BarisJasaUji('Paket modul pelatihan', '10', '250000.00', jenis: JenisBarisInvoice::Barang),
        ]));

        $idJurnal = (int) $invoice->IdJurnal;
        expect($invoice->Total)->toBe('11500000.00')
            ->and(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::DiskonPenjualan))->toBe(['Debit' => '1000000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::Penjualan))->toBe(['Debit' => '0.00', 'Kredit' => '2500000.00']);
    });

    it('PPh diharapkan = DPP baris jasa × tarif yang diisi pengguna; baris barang tidak ikut dasar PPh', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [
            BarisJasaUji('Konsultasi', '1', '10000000.00'),
            BarisJasaUji('Buku panduan', '10', '100000.00', jenis: JenisBarisInvoice::Barang),
        ], kodePph: 'PPh23', tarifPph: '2'));

        expect($invoice->KodePph)->toBe('PPh23')
            ->and($invoice->TarifPph)->toBe('2.000000')
            ->and($invoice->DasarPph)->toBe('10000000.00')
            ->and($invoice->NilaiPph)->toBe('200000.00')
            // PPh bukan bagian tagihan: pelanggan memotongnya saat membayar.
            ->and($invoice->Tagihan)->toBe('11000000.00');
    });

    it('tanggal masa depan ditolak saat terbit; draf masa depan boleh disimpan', function (): void {
        $draf = DrafInvoiceUji($this, tanggal: '2026-10-05');

        expect(GalatInvoiceUji(fn () => TerbitInvoiceUji($this, $draf)))->toBe('TanggalMasaDepan');

        Carbon::setTestNow('2026-10-05 03:00:00');

        expect(TerbitInvoiceUji($this, $draf)->Status)->toBe(StatusInvoice::Terbit);
    });

    it('idempoten: menerbitkan dua kali menghasilkan satu jurnal dan satu piutang', function (): void {
        $draf = DrafInvoiceUji($this);
        $pertama = TerbitInvoiceUji($this, $draf);
        $kedua = TerbitInvoiceUji($this, $draf);

        expect($kedua->Id)->toBe($pertama->Id)
            ->and($kedua->IdJurnal)->toBe($pertama->IdJurnal)
            ->and(Piutang::query()->where('IdInvoice', $pertama->Id)->count())->toBe(1);
    });

    it('invoice terbit terkunci: kolom isi tidak bisa diubah, dan tidak bisa dihapus', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this));

        expect(fn () => $invoice->forceFill(['Total' => '1.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => $invoice->delete())->toThrow(LogicException::class);
    });
});

describe('Pembayaran invoice (BP, PPh dipotong pelanggan)', function (): void {
    it('pelunasan penuh melunasi piutang; jurnal Dr kas/bank, Cr Piutang Usaha', function (): void {
        PakaiPpnUji($this);
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(uuidKelompok: $this->kelompokPpn->Uuid)]));

        $pembayaran = app(CatatPembayaranInvoice::class)->Jalankan(
            $invoice->Uuid,
            BantuanPembelian::AkunKas('1-1200')->Uuid,
            CarbonImmutable::parse('2026-09-27'),
            null,
            Uang::Nol(),
            null,
            'Transfer BCA',
            $this->k['Pemilik']->Id,
        );

        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->sole();
        expect($pembayaran->Jumlah)->toBe('11100000.00')
            ->and($pembayaran->PotonganPph)->toBe('0.00')
            ->and($piutang->Status)->toBe(StatusPiutang::Lunas)
            ->and($piutang->AmbilSisa()->KeString())->toBe('0.00')
            ->and(JurnalPeranInvoiceUji((int) $pembayaran->IdJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '11100000.00'])
            ->and(JurnalSeimbangInvoiceUji((int) $pembayaran->IdJurnal))->toBeTrue();
    });

    it('PPh 23 dipotong pelanggan: piutang lunas penuh, kas hanya sebesar bersih, selisih ke PPh dibayar dimuka', function (): void {
        PakaiPpnUji($this);
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(uuidKelompok: $this->kelompokPpn->Uuid)], kodePph: 'PPh23', tarifPph: '2'));

        expect($invoice->NilaiPph)->toBe('200000.00');

        $pembayaran = app(CatatPembayaranInvoice::class)->Jalankan(
            $invoice->Uuid,
            BantuanPembelian::AkunKas('1-1200')->Uuid,
            CarbonImmutable::parse('2026-09-27'),
            null,
            Uang::Dari('200000.00'),
            '0123/BPPU/IX/2026',
            null,
            $this->k['Pemilik']->Id,
        );

        $idJurnal = (int) $pembayaran->IdJurnal;
        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->sole();
        expect($pembayaran->Jumlah)->toBe('11100000.00')
            ->and($pembayaran->PotonganPph)->toBe('200000.00')
            ->and($pembayaran->NomorBuktiPotong)->toBe('0123/BPPU/IX/2026')
            ->and($piutang->Status)->toBe(StatusPiutang::Lunas)
            ->and(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::Bank))->toBe(['Debit' => '10900000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PphDibayarDimuka))->toBe(['Debit' => '200000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '0.00', 'Kredit' => '11100000.00']);
    });

    it('pembayaran sebagian, lalu sisa; potongan PPh melebihi pelunasan ditolak', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this));
        $bayar = fn (?string $jumlah, string $pph = '0.00') => app(CatatPembayaranInvoice::class)->Jalankan(
            $invoice->Uuid,
            BantuanPembelian::AkunKas('1-1200')->Uuid,
            CarbonImmutable::parse('2026-09-27'),
            $jumlah === null ? null : Uang::Dari($jumlah),
            Uang::Dari($pph),
            null,
            null,
            $this->k['Pemilik']->Id,
        );

        expect(GalatInvoiceUji(fn () => $bayar('1000000.00', '2000000.00')))->toBe('PphTidakValid')
            ->and(GalatInvoiceUji(fn () => $bayar('99999999.00')))->toBe('MelebihiSisa');

        $bayar('4000000.00');
        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->sole();

        expect($piutang->Status)->toBe(StatusPiutang::DibayarSebagian)->and($piutang->AmbilSisa()->KeString())->toBe('6000000.00');

        $bayar(null);

        expect($piutang->refresh()->Status)->toBe(StatusPiutang::Lunas)
            ->and(PembayaranPiutang::query()->count())->toBe(2);
    });

    it('draf dan invoice dibatalkan tidak bisa dibayar', function (): void {
        $draf = DrafInvoiceUji($this);

        expect(GalatInvoiceUji(fn () => app(CatatPembayaranInvoice::class)->Jalankan($draf->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, CarbonImmutable::parse('2026-09-27'), null, Uang::Nol(), null, null, $this->k['Pemilik']->Id)))->toBe('InvoiceBelumTerbit');
    });
});

describe('Invoice uang muka (J-21.2) dan pemotongannya (J-21.1)', function (): void {
    it('DP: Dr Piutang, Cr Uang Muka Pelanggan (DPP) dan PPN keluaran; belum ada pendapatan', function (): void {
        PakaiPpnUji($this);
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka 30% proyek', harga: '3000000.00', uuidKelompok: $this->kelompokPpn->Uuid)], jenis: JenisInvoice::UangMuka));

        $idJurnal = (int) $dp->IdJurnal;
        expect($dp->Jenis)->toBe(JenisInvoice::UangMuka)
            ->and($dp->Total)->toBe('3330000.00')
            ->and(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '3330000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::UangMukaPelanggan))->toBe(['Debit' => '0.00', 'Kredit' => '3000000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '330000.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '0.00']);
    });

    it('invoice akhir memotong DP: piutang = total − DP, UM didebit, PPN hanya sisa yang belum diakui; jurnal seimbang', function (): void {
        PakaiPpnUji($this);
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka 30%', harga: '3000000.00', uuidKelompok: $this->kelompokPpn->Uuid)], jenis: JenisInvoice::UangMuka));

        $akhir = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(uuidKelompok: $this->kelompokPpn->Uuid)], uangMuka: [$dp->Uuid]));

        expect($akhir->Total)->toBe('11100000.00')
            ->and($akhir->PotonganUangMukaDpp)->toBe('3000000.00')
            ->and($akhir->PotonganUangMukaPajak)->toBe('330000.00')
            ->and($akhir->Tagihan)->toBe('7770000.00');

        $idJurnal = (int) $akhir->IdJurnal;
        expect(JurnalSeimbangInvoiceUji($idJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PiutangUsaha))->toBe(['Debit' => '7770000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::UangMukaPelanggan))->toBe(['Debit' => '3000000.00', 'Kredit' => '0.00'])
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            // PPN penuh 1.100.000 dikurangi 330.000 yang sudah diakui saat DP: tidak terhitung dua kali.
            ->and(JurnalPeranInvoiceUji($idJurnal, PeranAkun::PpnKeluaran))->toBe(['Debit' => '0.00', 'Kredit' => '770000.00'])
            ->and(Piutang::query()->where('IdInvoice', $akhir->Id)->sole()->Jumlah)->toBe('7770000.00')
            ->and($dp->refresh()->IdInvoiceAkhir)->toBe($akhir->Id);

        // Saldo Uang Muka Pelanggan akhirnya nol: kredit di DP, debit di invoice akhir.
        expect(BantuanPembelian::SaldoPeran($this->k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('0.00');
    });

    it('DP yang menutup seluruh invoice: Tagihan nol, tidak ada piutang baru', function (): void {
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka 100%', harga: '10000000.00')], jenis: JenisInvoice::UangMuka));
        $akhir = TerbitInvoiceUji($this, DrafInvoiceUji($this, uangMuka: [$dp->Uuid]));

        expect($akhir->Tagihan)->toBe('0.00')
            ->and(Piutang::query()->where('IdInvoice', $akhir->Id)->count())->toBe(0)
            ->and(JurnalSeimbangInvoiceUji((int) $akhir->IdJurnal))->toBeTrue()
            ->and(JurnalPeranInvoiceUji((int) $akhir->IdJurnal, PeranAkun::PendapatanJasa))->toBe(['Debit' => '0.00', 'Kredit' => '10000000.00'])
            ->and(JurnalPeranInvoiceUji((int) $akhir->IdJurnal, PeranAkun::UangMukaPelanggan))->toBe(['Debit' => '10000000.00', 'Kredit' => '0.00']);
    });

    it('DP melebihi nilai invoice, milik pelanggan lain, atau sudah dipakai ditolak', function (): void {
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka', harga: '8000000.00')], jenis: JenisInvoice::UangMuka));
        $lain = Pelanggan::query()->create(['Nama' => 'CV Karya Utama', 'NoHp' => '6281355550102', 'TerminHari' => 0]);

        expect(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [BarisJasaUji(harga: '5000000.00')], uangMuka: [$dp->Uuid])))->toBe('UangMukaMelebihiInvoice')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, pelanggan: $lain, uangMuka: [$dp->Uuid])))->toBe('UangMukaTidakValid');

        $akhir = DrafInvoiceUji($this, uangMuka: [$dp->Uuid]);

        expect($akhir->PotonganUangMukaDpp)->toBe('8000000.00')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, uangMuka: [$dp->Uuid])))->toBe('UangMukaTidakValid');
    });

    it('invoice DP tidak berdiskon, tidak ber-PPh, tidak memotong DP lain', function (): void {
        expect(GalatInvoiceUji(fn () => DrafInvoiceUji($this, [BarisJasaUji(harga: '1000.00', diskon: '100.00')], jenis: JenisInvoice::UangMuka)))->toBe('BarisTidakValid')
            ->and(GalatInvoiceUji(fn () => DrafInvoiceUji($this, jenis: JenisInvoice::UangMuka, kodePph: 'PPh23', tarifPph: '2')))->toBe('PphTidakValid');
    });
});

describe('BatalkanInvoice', function (): void {
    it('draf dibatalkan tanpa jurnal dan nomor; DP yang dipesan dilepas', function (): void {
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka', harga: '2000000.00')], jenis: JenisInvoice::UangMuka));
        $draf = DrafInvoiceUji($this, uangMuka: [$dp->Uuid]);
        $jurnalSebelum = Jurnal::query()->count();

        expect($dp->refresh()->IdInvoiceAkhir)->toBe($draf->Id);

        $hasil = app(BatalkanInvoice::class)->Jalankan($draf->Uuid, 'Pelanggan membatalkan proyek', $this->k['Pemilik']->Id);

        expect($hasil->Status)->toBe(StatusInvoice::Dibatalkan)
            ->and($hasil->Nomor)->toBeNull()
            ->and($hasil->IdJurnalPembatalan)->toBeNull()
            ->and(Jurnal::query()->count())->toBe($jurnalSebelum)
            ->and($dp->refresh()->IdInvoiceAkhir)->toBeNull();
    });

    it('terbit dibatalkan: jurnal dibalik hari ini, piutang Dibatalkan, saldo kembali nol', function (): void {
        PakaiPpnUji($this);
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji(uuidKelompok: $this->kelompokPpn->Uuid)]));
        $hasil = app(BatalkanInvoice::class)->Jalankan($invoice->Uuid, 'Salah nominal, buat ulang', $this->k['Pemilik']->Id);

        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->sole();
        expect($hasil->Status)->toBe(StatusInvoice::Dibatalkan)
            ->and($hasil->IdJurnalPembatalan)->not->toBeNull()
            ->and(JurnalSeimbangInvoiceUji((int) $hasil->IdJurnalPembatalan))->toBeTrue()
            ->and($piutang->Status)->toBe(StatusPiutang::Dibatalkan)
            ->and(BantuanPembelian::SaldoPeran($this->k['Tenant']->Id, PeranAkun::PiutangUsaha))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($this->k['Tenant']->Id, PeranAkun::PendapatanJasa))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($this->k['Tenant']->Id, PeranAkun::PpnKeluaran))->toBe('0.00');

        // Idempoten dan terkunci.
        expect(app(BatalkanInvoice::class)->Jalankan($invoice->Uuid, 'Salah nominal, buat ulang', $this->k['Pemilik']->Id)->Id)->toBe($invoice->Id);
    });

    it('sudah dibayar sebagian ditolak sampai pelunasannya dibatalkan', function (): void {
        $invoice = TerbitInvoiceUji($this, DrafInvoiceUji($this));
        app(CatatPembayaranInvoice::class)->Jalankan($invoice->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, CarbonImmutable::parse('2026-09-27'), Uang::Dari('1000000.00'), Uang::Nol(), null, null, $this->k['Pemilik']->Id);

        expect(GalatInvoiceUji(fn () => app(BatalkanInvoice::class)->Jalankan($invoice->Uuid, 'Batal saja', $this->k['Pemilik']->Id)))->toBe('PiutangSudahDibayar');
    });

    it('DP yang sudah dipotong tidak bisa dibatalkan; membatalkan invoice akhir melepasnya', function (): void {
        $dp = TerbitInvoiceUji($this, DrafInvoiceUji($this, [BarisJasaUji('Uang muka', harga: '2000000.00')], jenis: JenisInvoice::UangMuka));
        $akhir = TerbitInvoiceUji($this, DrafInvoiceUji($this, uangMuka: [$dp->Uuid]));

        expect(GalatInvoiceUji(fn () => app(BatalkanInvoice::class)->Jalankan($dp->Uuid, 'Batalkan DP', $this->k['Pemilik']->Id)))->toBe('UangMukaSudahDipakai');

        app(BatalkanInvoice::class)->Jalankan($akhir->Uuid, 'Proyek diubah lingkupnya', $this->k['Pemilik']->Id);

        expect($dp->refresh()->IdInvoiceAkhir)->toBeNull()
            ->and(app(BatalkanInvoice::class)->Jalankan($dp->Uuid, 'Batalkan DP', $this->k['Pemilik']->Id)->Status)->toBe(StatusInvoice::Dibatalkan)
            ->and(BantuanPembelian::SaldoPeran($this->k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('0.00');
    });

    it('alasan wajib 5 sampai 255 karakter', function (): void {
        $draf = DrafInvoiceUji($this);

        expect(GalatInvoiceUji(fn () => app(BatalkanInvoice::class)->Jalankan($draf->Uuid, 'x', $this->k['Pemilik']->Id)))->toBe('AlasanBatalWajib');
    });
});
