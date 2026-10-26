<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BuatFakturPenjualan;
use App\Domain\Penjualan\Aksi\BuatReturGrosir;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Aksi\UbahNomorFakturPajak;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisReturGrosir;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataFakturPenjualan;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Data\DataReturGrosir;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Data\HasilNotaReturPajak;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use App\Domain\Penjualan\Layanan\PenulisXmlCoretax;
use App\Domain\Penjualan\Layanan\PenyusunFakturPajakCoretax;
use App\Domain\Penjualan\Layanan\PenyusunNotaReturPajak;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Ekspor Faktur Pajak Keluaran Coretax dari faktur grosir (PRD v3.12, F-12 §9.7, UU PPN Pasal 13).
 *
 * Yang dibuktikan: angka baris XML (DPP, DPP nilai lain 11/12, PPN 12%) cocok dengan faktur internal; faktur yang belum
 * bisa diekspor dilewati **dengan alasan** (bukan menggagalkan seluruh berkas); penjual non-PKP menahan seluruh ekspor;
 * dan isi XML aman dari karakter khusus.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Sumber Pangan');
    BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
    BantuanPenjualan::AturProfilPajak($this->k, pkp: true);
    Tenant::query()->whereKey($this->k['Tenant']->Id)->update(['Npwp' => '0123456789012345', 'Pkp' => true]);
    $this->gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula "Premium" & Halus'], '15000.00', $this->k['Pcs']);
    $this->satuan = BantuanHarga::SatuanDasar($this->gula);
    BantuanPenjualan::PasangKelompokPajak('Grosir: barang kena PPN', ['Ppn' => 'Subtotal'], $this->gula);
    BantuanStokAwal::BuatDanPosting(
        $this->k['Gudang'],
        [BantuanStokAwal::Baris($this->gula, '1000', '11000')],
        $this->k['Pemilik']->Id,
        '2026-08-01',
    );
    $this->toko = Pelanggan::query()->create([
        'Nama' => 'Toko Makmur Jaya',
        'NoHp' => '6281355550013',
        'LimitKredit' => '50000000',
        'TerminHari' => 30,
        'Npwp' => '012345678901234',
        'NamaNpwp' => 'PT Makmur Jaya Abadi',
        'AlamatNpwp' => 'Jl. Merdeka No. 1, Solo',
        'Email' => 'pajak@makmur.test',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function FakturCoretaxUji($tes, Pelanggan $pelanggan, string $jumlah = '200', string $tanggal = '2026-09-27'): FakturPenjualan
{
    $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $pelanggan->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse($tanggal),
        baris: [new DataBarisPesananGrosir($tes->gula->Uuid, $tes->satuan->Uuid, Kuantitas::Dari($jumlah), Uang::Nol())],
    ), $tes->k['Pemilik']->Id);
    $pesanan = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $tes->k['Pemilik']->Id, true, 'Pelanggan lama, rekam jejak baik');
    $suratJalan = app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
        uuidPesanan: $pesanan->Uuid,
        idGudang: $tes->k['Gudang']->Id,
        tanggal: CarbonImmutable::parse($tanggal),
        baris: [new DataBarisSuratJalan(1, Kuantitas::Dari($jumlah))],
    ), $tes->k['Pemilik']->Id);

    return app(BuatFakturPenjualan::class)->Jalankan(new DataFakturPenjualan(
        uuidSuratJalan: [$suratJalan->Uuid],
        tanggal: CarbonImmutable::parse($tanggal),
    ), $tes->k['Pemilik']->Id);
}

function SusunCoretaxUji($tes)
{
    BantuanPersediaan::MasukSebagai($tes, $tes->k['Tenant']->Id);

    return app(PenyusunFakturPajakCoretax::class)->Susun(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), null);
}

describe('PenyusunFakturPajakCoretax & PenulisXmlCoretax (v3.12)', function (): void {
    it('menyusun baris dengan DPP, DPP nilai lain 11/12, dan PPN 12% yang sama dengan faktur internal', function (): void {
        $faktur = FakturCoretaxUji($this, $this->toko);

        $hasil = SusunCoretaxUji($this);

        expect($hasil->BisaDiekspor())->toBeTrue()
            ->and($hasil->masalahUmum)->toBe([])
            ->and($hasil->masalahFaktur)->toBe([])
            ->and($hasil->tinPenjual)->toBe('0123456789012345')
            ->and($hasil->faktur)->toHaveCount(1);

        $f = $hasil->faktur[0];
        expect($f->nomorFaktur)->toBe($faktur->Nomor)
            // NPWP pembeli 15 digit diberi awalan 0 menjadi 16 digit.
            ->and($f->tinPembeli)->toBe('0012345678901234')
            ->and($f->idTkuPembeli)->toBe('0012345678901234000000')
            ->and($f->namaPembeli)->toBe('PT Makmur Jaya Abadi')
            ->and($f->alamatPembeli)->toBe('Jl. Merdeka No. 1, Solo')
            ->and($f->totalDpp)->toBe($faktur->DasarPengenaanPajak)
            ->and($f->totalPpn)->toBe($faktur->Pajak)
            ->and($f->selisihDpp)->toBe('0.00')
            ->and($f->selisihPpn)->toBe('0.00');

        $b = $f->baris[0];
        expect($b->opsi)->toBe('A')
            ->and($b->kode)->toBe('000000')
            ->and($b->satuan)->toBe('UM.0021')
            ->and($b->harga)->toBe('15000.00')
            ->and($b->jumlah)->toBe('200')
            ->and($b->totalDiskon)->toBe('0.00')
            ->and($b->dpp)->toBe('3000000.00')
            ->and($b->dppNilaiLain)->toBe('2750000.00')
            ->and($b->tarifPpn)->toBe('12')
            ->and($b->ppn)->toBe('330000.00');

        // Kode Coretax belum diisi produk: peringatan, bukan penghalang.
        expect($hasil->peringatan)->not->toBe([]);
    });

    it('menulis XML TaxInvoiceBulk yang sah dan meloloskan karakter khusus pada nama barang', function (): void {
        FakturCoretaxUji($this, $this->toko);

        $xml = app(PenulisXmlCoretax::class)->Tulis(SusunCoretaxUji($this));
        $dokumen = simplexml_load_string($xml);

        expect($dokumen)->not->toBeFalse()
            ->and($dokumen->getName())->toBe('TaxInvoiceBulk')
            ->and((string) $dokumen->TIN)->toBe('0123456789012345');

        $faktur = $dokumen->ListOfTaxInvoice->TaxInvoice;
        expect((string) $faktur->TaxInvoiceDate)->toBe('2026-09-27')
            ->and((string) $faktur->TrxCode)->toBe('04')
            ->and((string) $faktur->SellerIDTKU)->toBe('0123456789012345000000')
            ->and((string) $faktur->BuyerTin)->toBe('0012345678901234')
            ->and((string) $faktur->BuyerDocument)->toBe('TIN')
            ->and((string) $faktur->BuyerCountry)->toBe('IDN')
            ->and((string) $faktur->BuyerEmail)->toBe('pajak@makmur.test');

        $barang = $faktur->ListOfGoodService->GoodService;
        // Tanda kutip dan & kembali utuh setelah XML di-parse: ditulis sebagai entitas, bukan merusak dokumen.
        expect((string) $barang->Name)->toBe('Gula "Premium" & Halus')
            ->and((string) $barang->Price)->toBe('15000')
            ->and((string) $barang->Qty)->toBe('200')
            ->and((string) $barang->TaxBase)->toBe('3000000')
            ->and((string) $barang->OtherTaxBase)->toBe('2750000')
            ->and((string) $barang->VATRate)->toBe('12')
            ->and((string) $barang->VAT)->toBe('330000');
    });

    it('pembeli ber-NIK memakai dokumen National ID dan TIN nol', function (): void {
        $this->toko->forceFill(['Npwp' => null, 'Nik' => '3374010101010001'])->save();
        FakturCoretaxUji($this, $this->toko);

        $f = SusunCoretaxUji($this)->faktur[0];

        expect($f->jenisDokumenPembeli)->toBe('National ID')
            ->and($f->tinPembeli)->toBe('0000000000000000')
            ->and($f->nomorDokumenPembeli)->toBe('3374010101010001')
            ->and($f->idTkuPembeli)->toBe('000000');
    });

    it('melewati faktur pembeli tanpa NPWP/NIK dengan alasan, dan tetap mengekspor faktur lain', function (): void {
        $tanpaIdentitas = Pelanggan::query()->create(['Nama' => 'Warung Tanpa NPWP', 'NoHp' => '6281355550014', 'LimitKredit' => '50000000', 'TerminHari' => 14]);
        FakturCoretaxUji($this, $this->toko, '100');
        $fakturBermasalah = FakturCoretaxUji($this, $tanpaIdentitas, '100');

        $hasil = SusunCoretaxUji($this);

        expect($hasil->jumlahDiperiksa)->toBe(2)
            ->and($hasil->faktur)->toHaveCount(1)
            ->and($hasil->BisaDiekspor())->toBeTrue()
            ->and($hasil->masalahFaktur)->toHaveKey($fakturBermasalah->Nomor)
            ->and($hasil->masalahFaktur[$fakturBermasalah->Nomor][0])->toContain('NPWP atau NIK');
    });

    it('penjual bukan PKP atau tanpa NPWP menahan seluruh ekspor', function (): void {
        FakturCoretaxUji($this, $this->toko);
        Tenant::query()->whereKey($this->k['Tenant']->Id)->update(['Npwp' => null, 'Pkp' => false]);

        $hasil = SusunCoretaxUji($this);

        expect($hasil->BisaDiekspor())->toBeFalse()
            ->and($hasil->masalahUmum)->toHaveCount(2);

        expect(fn () => app(PenulisXmlCoretax::class)->Tulis($hasil))->toThrow(InvalidArgumentException::class);
    });

    it('hanya memuat faktur pada periode yang diminta', function (): void {
        FakturCoretaxUji($this, $this->toko);
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $hasil = app(PenyusunFakturPajakCoretax::class)->Susun(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), null);

        expect($hasil->jumlahDiperiksa)->toBe(0)
            ->and($hasil->BisaDiekspor())->toBeFalse();
    });

    it('NormalisasiNpwp memberi awalan 0 pada 15 digit dan menolak panjang lain', function (): void {
        expect(PenyusunFakturPajakCoretax::NormalisasiNpwp('01.234.567.8-901.234'))->toBe('0012345678901234')
            ->and(PenyusunFakturPajakCoretax::NormalisasiNpwp('0123456789012345'))->toBe('0123456789012345')
            ->and(PenyusunFakturPajakCoretax::NormalisasiNpwp('12345'))->toBeNull()
            ->and(PenyusunFakturPajakCoretax::NormalisasiNpwp(null))->toBeNull();
    });
});

describe('HTTP ekspor Faktur Pajak Coretax', function (): void {
    it('ringkasan JSON dan unduhan XML untuk periode filter', function (): void {
        FakturCoretaxUji($this, $this->toko);
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->getJson('/kelola/laporan/pajak/faktur-keluaran?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertJsonPath('JumlahSiap', 1)
            ->assertJsonPath('BisaDiekspor', true)
            ->assertJsonPath('TotalPpn', '330000.00');

        $respons = $this->get('/kelola/laporan/pajak/faktur-keluaran/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();

        expect($respons->headers->get('Content-Type'))->toContain('application/xml')
            ->and($respons->headers->get('Content-Disposition'))->toContain('faktur-pajak-keluaran-2026-09-01-2026-09-30.xml')
            ->and(simplexml_load_string($respons->getContent()))->not->toBeFalse();
    });

    it('unduhan ditolak 422 bila tidak ada faktur yang siap', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/laporan/pajak/faktur-keluaran/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertStatus(422);
    });
});

/** Retur grosir atas surat jalan pertama faktur ini (baris 1). */
function ReturCoretaxUji($tes, SuratJalan $suratJalan, string $jumlah, string $tanggal = '2026-09-28'): ReturGrosir
{
    return app(BuatReturGrosir::class)->Jalankan(new DataReturGrosir(
        uuidSuratJalan: $suratJalan->Uuid,
        tanggal: CarbonImmutable::parse($tanggal),
        alasan: 'Kemasan sobek saat bongkar',
        baris: [new DataBarisReturGrosir(1, Kuantitas::Dari($jumlah), KondisiBarangRetur::LayakJual)],
    ), $tes->k['Pemilik']->Id);
}

function SusunNotaReturUji($tes): HasilNotaReturPajak
{
    BantuanPersediaan::MasukSebagai($tes, $tes->k['Tenant']->Id);

    return app(PenyusunNotaReturPajak::class)->Susun(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), null);
}

describe('Rekap nota retur pajak dari retur grosir (v4.08)', function (): void {
    it('retur atas faktur ber-NSFP direkap per baris dengan DPP, DPP nilai lain 11/12, dan PPN, tanpa NSFP dijelaskan', function (): void {
        Carbon::setTestNow('2026-09-28 03:00:00');
        $faktur = FakturCoretaxUji($this, $this->toko, '200', '2026-09-27');
        $retur = ReturCoretaxUji($this, $faktur->SuratJalan->firstOrFail(), '20');

        $hasil = SusunNotaReturUji($this);
        expect($hasil->retur)->toBe([])
            ->and($hasil->masalahRetur[$retur->Nomor][0])->toContain('belum punya nomor Faktur Pajak');

        app(UbahNomorFakturPajak::class)->Jalankan($faktur->Uuid, '04002600000012345', $this->k['Pemilik']->Id);
        $hasil = SusunNotaReturUji($this);
        $nota = $hasil->retur[0];
        $baris = $nota->baris[0];

        expect($hasil->jumlahDiperiksa)->toBe(1)
            ->and($nota->nomorRetur)->toBe($retur->Nomor)
            ->and($nota->nomorFaktur)->toBe($faktur->Nomor)
            ->and($nota->nomorFakturPajak)->toBe('04002600000012345')
            ->and($nota->namaPembeli)->toBe('PT Makmur Jaya Abadi')
            ->and($nota->tinPembeli)->toBe('0012345678901234')
            ->and($baris->jumlah)->toBe('20')
            ->and($baris->dpp)->toBe('300000.00')
            ->and($baris->dppNilaiLain)->toBe('275000.00')
            ->and($baris->ppn)->toBe('33000.00')
            // Angka per baris sama dengan dokumen retur (jurnal J-12.4 memakai angka dokumen).
            ->and($nota->totalPpn)->toBe(Uang::Dari($retur->Pajak)->KeString())
            ->and($nota->selisihDpp)->toBe('0.00')
            ->and($nota->selisihPpn)->toBe('0.00');
    });

    it('retur atas surat jalan yang belum difakturkan menunggu Faktur Pajaknya terbit', function (): void {
        $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
            uuidPelanggan: $this->toko->Uuid,
            idOutlet: $this->k['Outlet']->Id,
            tanggal: CarbonImmutable::parse('2026-09-20'),
            baris: [new DataBarisPesananGrosir($this->gula->Uuid, $this->satuan->Uuid, Kuantitas::Dari('50'), Uang::Nol())],
        ), $this->k['Pemilik']->Id);
        app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->k['Pemilik']->Id, true, 'Pelanggan lama, rekam jejak baik');
        $sj = app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
            uuidPesanan: $pesanan->Uuid,
            idGudang: $this->k['Gudang']->Id,
            tanggal: CarbonImmutable::parse('2026-09-20'),
            baris: [new DataBarisSuratJalan(1, Kuantitas::Dari('50'))],
        ), $this->k['Pemilik']->Id);
        $retur = ReturCoretaxUji($this, $sj, '5', '2026-09-25');

        expect(SusunNotaReturUji($this)->masalahRetur[$retur->Nomor][0])->toContain('belum difakturkan');
    });

    it('HTTP: ringkasan JSON & unduhan CSV per barang; 422 bila tidak ada yang siap', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->get('/kelola/laporan/pajak/nota-retur/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertStatus(422);

        Carbon::setTestNow('2026-09-28 03:00:00');
        $faktur = FakturCoretaxUji($this, $this->toko, '200', '2026-09-27');
        app(UbahNomorFakturPajak::class)->Jalankan($faktur->Uuid, '04002600000012345', $this->k['Pemilik']->Id);
        ReturCoretaxUji($this, $faktur->SuratJalan->firstOrFail(), '20');
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->getJson('/kelola/laporan/pajak/nota-retur?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertJsonPath('JumlahSiap', 1)
            ->assertJsonPath('BisaDiekspor', true)
            ->assertJsonPath('TotalPpn', '33000.00')
            ->assertJsonPath('Retur.0.NomorFakturPajak', '04002600000012345');

        $respons = $this->get('/kelola/laporan/pajak/nota-retur/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();
        $isi = $respons->streamedContent();

        expect($respons->headers->get('Content-Type'))->toContain('text/csv')
            ->and($isi)->toContain('Nomor Faktur Pajak')
            ->and($isi)->toContain('04002600000012345')
            ->and($isi)->toContain('275000.00');
    });
});
