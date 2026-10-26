<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Aksi\AjukanPesananPembelian;
use App\Domain\Pembelian\Aksi\BatalkanFakturPembelian;
use App\Domain\Pembelian\Aksi\BatalkanPembayaranHutang;
use App\Domain\Pembelian\Aksi\BatalkanPenerimaanBarang;
use App\Domain\Pembelian\Aksi\BatalkanPesananPembelian;
use App\Domain\Pembelian\Aksi\BatalkanReturPembelian;
use App\Domain\Pembelian\Aksi\SetujuiPesananPembelian;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStok;
use App\Domain\Pembelian\Aksi\SimpanPembayaranHutang;
use App\Domain\Pembelian\Aksi\SimpanReturPembelian;
use App\Domain\Pembelian\Aksi\UbahPengaturanPembelian;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBarisReturPembelian;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Data\DataPembayaranHutang;
use App\Domain\Pembelian\Data\DataReturPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Kode galat aturan bisnis dari `fn`, atau null bila tidak ada galat. */
function KodeGalatPembelian(Closure $fn): ?string
{
    try {
        $fn();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

/**
 * Baris jurnal satu sumber: [Kunci peran/Kode akun => [Debit, Kredit]] dari jurnal pertama sumber & kunci itu.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function BarisJurnalPembelian(string $jenis, int $idSumber, string $kunci = 'Utama'): array
{
    $jurnal = Jurnal::query()->where('JenisSumber', $jenis)->where('IdSumber', $idSumber)->where('KunciSumber', $kunci)->sole();
    $hasil = [];

    foreach (DB::table('JurnalDetail')->join('Akun', 'Akun.Id', '=', 'JurnalDetail.IdAkun')->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get(['Akun.Kode', 'Debit', 'Kredit']) as $d) {
        $hasil[(string) $d->Kode] = [(string) $d->Debit, (string) $d->Kredit];
    }

    return $hasil;
}

function SaldoProduk(int $idProduk, int $idGudang): SaldoStok
{
    return SaldoStok::query()->where('IdProduk', $idProduk)->where('IdGudang', $idGudang)->firstOrFail();
}

describe('F-04 pesanan pembelian: status & persetujuan (§19.2)', function (): void {
    it('PO di bawah batas langsung Disetujui. Di atas batas menunggu persetujuan orang lain, bukan pembuatnya', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        // D-38: Pemilik yang mengajukan langsung disetujui, jadi four-eyes diuji lewat pengaju bukan-Pemilik.
        $id = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id;
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pemasok = BantuanPembelian::BuatPemasok();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);

        $kecil = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$minyak, '24', '31500']], $id);
        expect($kecil->Status)->toBe(StatusPesananPembelian::Disetujui)
            ->and($kecil->Nomor)->toMatch('#^PO/[A-Z0-9-]+/\d{4}/0001$#')
            ->and($kecil->Total)->toBe('756000.00')
            ->and($kecil->DisetujuiOleh)->toBe($id);

        $besar = app(AjukanPesananPembelian::class)->Jalankan(BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$minyak, '200', '31500']], $id), $id);
        expect($besar->Status)->toBe(StatusPesananPembelian::MenungguPersetujuan)->and($besar->Total)->toBe('6300000.00');
        expect(KodeGalatPembelian(fn () => app(SetujuiPesananPembelian::class)->Jalankan($besar, $id)))->toBe('PenyetujuPembuat');

        $admin = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(KodeGalatPembelian(fn () => app(SetujuiPesananPembelian::class)->Jalankan($besar, $admin->Id, false, 'x')))->toBe('AlasanTidakValid');
        $ditolak = app(SetujuiPesananPembelian::class)->Jalankan($besar, $admin->Id, false, 'Harga terlalu tinggi, minta penawaran ulang');
        expect($ditolak->Status)->toBe(StatusPesananPembelian::Draf)->and($ditolak->AlasanDitolak)->toBe('Harga terlalu tinggi, minta penawaran ulang');

        app(AjukanPesananPembelian::class)->Jalankan($ditolak, $id);
        $disetujui = app(SetujuiPesananPembelian::class)->Jalankan($ditolak, $admin->Id);
        expect($disetujui->Status)->toBe(StatusPesananPembelian::Disetujui)->and($disetujui->DisetujuiOleh)->toBe($admin->Id);

        // Batas persetujuan bisa diubah; batas 0 = setiap PO butuh persetujuan.
        app(UbahPengaturanPembelian::class)->Jalankan(new DataPengaturanPembelian(Uang::Nol(), '0'), $id);
        $nol = app(AjukanPesananPembelian::class)->Jalankan(BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$minyak, '1', '31500']], $id), $id);
        expect($nol->Status)->toBe(StatusPesananPembelian::MenungguPersetujuan);

        // Riwayat status tercatat.
        expect(DB::table('RiwayatStatusDokumen')->where('JenisDokumen', PesananPembelian::JENIS_DOKUMEN)->where('IdDokumen', $besar->Id)->pluck('StatusKe')->all())
            ->toBe(['Draf', 'MenungguPersetujuan', 'Draf', 'MenungguPersetujuan', 'Disetujui']);
    });

    it('PPN masukan dari TarifPajak berlaku untuk pemasok PKP (DPP 11/12 × 12%), tanpa angka tarif di kode', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $pemasok = BantuanPembelian::BuatPemasok('PT Indofood Distribusi Solo', true);
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih 1 kg']);

        $po = BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$gula, '100', '16500', '50000']], $t['Pemilik']->Id, '75000');

        // DPP = (1.650.000 − 50.000) × 11/12 = 1.466.666,67; PPN 12% = 176.000,00.
        expect($po->Subtotal)->toBe('1650000.00')->and($po->Diskon)->toBe('50000.00')
            ->and($po->Pajak)->toBe('176000.00')->and($po->TarifPpn)->toBe('12.000000')
            ->and($po->Total)->toBe('1851000.00');
    });

    it('PO dibatalkan hanya sebelum ada penerimaan. Setelahnya hanya bisa ditutup', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $sabun = BantuanKatalog::BuatProduk();
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$sabun, '10', '12000']], $id);
        BantuanPembelian::TerimaDariPo($po, ['4'], $id);

        expect(KodeGalatPembelian(fn () => app(BatalkanPesananPembelian::class)->Jalankan($po, $id, false, 'Pemasok tidak sanggup kirim')))->toBe('SudahAdaPenerimaan');
        $ditutup = app(BatalkanPesananPembelian::class)->Jalankan($po, $id, true);
        expect($ditutup->Status)->toBe(StatusPesananPembelian::Ditutup);

        $po2 = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$sabun, '5', '12000']], $id);
        expect(app(BatalkanPesananPembelian::class)->Jalankan($po2, $id, false, 'Salah pilih pemasok')->Status)->toBe(StatusPesananPembelian::Dibatalkan);
    });
});

describe('F-04 penerimaan barang (GRN): BR-04.1, BR-04.2, BR-04.3, landed cost, batch & seri', function (): void {
    it('GRN parsial dari PO menaikkan stok & HPP rata-rata bergerak (BR-04.2), status PO DiterimaSebagian → Diterima, jurnal J-04.1', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele Delanggu 5 kg']);
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($beras, '10', '70000')], $id);

        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$beras, '20', '76000']], $id);
        $grn1 = BantuanPembelian::TerimaDariPo($po, ['8'], $id);

        // HPP baru = (10 × 70.000 + 8 × 76.000) / 18 = 72.666,666667.
        $saldo = SaldoProduk($beras->Id, $t['Gudang']->Id);
        expect($grn1->Nomor)->toMatch('#^GR/[A-Z0-9-]+/\d{4}/0001$#')
            ->and($saldo->JumlahTersedia)->toBe('18.0000')
            ->and($saldo->HppRataRata)->toBe('72666.666667')
            ->and($po->refresh()->Status)->toBe(StatusPesananPembelian::DiterimaSebagian)
            ->and(BarisJurnalPembelian('PenerimaanBarang', $grn1->Id))->toBe(['1-1500' => ['608000.00', '0.00'], '2-1150' => ['0.00', '608000.00']]);

        // BR-04.1: tanpa toleransi, sisa 12 → 13 ditolak; 12 diterima → Diterima.
        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaDariPo($po, ['13'], $id)))->toBe('MelebihiPesanan');
        BantuanPembelian::TerimaDariPo($po, ['12'], $id);
        expect($po->refresh()->Status)->toBe(StatusPesananPembelian::Diterima)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('BR-04.1 toleransi penerimaan (persen) mengizinkan lebih kirim sampai batasnya', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        app(UbahPengaturanPembelian::class)->Jalankan(new DataPengaturanPembelian(Uang::Dari('5000000'), '10'), $id);
        $pemasok = BantuanPembelian::BuatPemasok();
        $telur = BantuanKatalog::BuatProduk(['Nama' => 'Telur Ayam Negeri Tray 30 butir']);
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$telur, '50', '52000']], $id);

        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaDariPo($po, ['56'], $id)))->toBe('MelebihiPesanan');
        BantuanPembelian::TerimaDariPo($po, ['55'], $id);
        expect($po->refresh()->Status)->toBe(StatusPesananPembelian::Diterima)
            ->and($po->Detail()->first()?->JumlahDiterima)->toBe('55.0000');
    });

    it('BR-04.3: menerima saat stok minus → HPP baru = harga masuk, selisih ke Selisih HPP', function (): void {
        $t = BantuanPembelian::SiapkanTenant(stokBolehMinus: true);
        $id = $t['Pemilik']->Id;
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram']);
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($kopi, '5', '20000')], $id);
        BantuanPembelian::Jual($kopi, $t['Gudang'], '8');

        $grn = BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$kopi, '10', '25000']], $id);
        $saldo = SaldoProduk($kopi->Id, $t['Gudang']->Id);

        // Stok −3 bernilai −60.000 → 7 × 25.000 = 175.000; Δ persediaan 235.000, GRNI 250.000, Selisih HPP 15.000.
        expect($saldo->JumlahTersedia)->toBe('7.0000')->and($saldo->HppRataRata)->toBe('25000.000000')->and($saldo->NilaiPersediaan)->toBe('175000.00')
            ->and(BarisJurnalPembelian('PenerimaanBarang', $grn->Id))->toBe(['1-1500' => ['235000.00', '0.00'], '5-1100' => ['15000.00', '0.00'], '2-1150' => ['0.00', '250000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('landed cost: ongkir dialokasikan sebanding nilai, PPN tidak dapat dikreditkan (outlet non-PKP) masuk HPP', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok('CV Makmur Plastik', true);
        $a = BantuanKatalog::BuatProduk(['Nama' => 'Kantong Plastik HD 24x40 isi 100']);
        $b = BantuanKatalog::BuatProduk(['Nama' => 'Sedotan Steril Bungkus Kertas isi 500']);

        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$a, '10', '15000'], [$b, '5', '30000']], $id, '30000');
        $detail = $grn->Detail()->get();

        // Subtotal 150.000 + 150.000; PPN 300.000 × 11/12 × 12% = 33.000 tidak dikreditkan; biaya 63.000 dibagi rata.
        expect($grn->Pajak)->toBe('33000.00')->and($grn->PpnDikreditkan)->toBeFalse()->and($grn->TotalNilai)->toBe('363000.00')
            ->and($detail->pluck('AlokasiBiaya')->all())->toBe(['31500.00', '31500.00'])
            ->and($detail->pluck('HppSatuan')->all())->toBe(['18150.000000', '36300.000000'])
            ->and(SaldoProduk($a->Id, $t['Gudang']->Id)->HppRataRata)->toBe('18150.000000')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('batch & nomor seri masuk dari GRN. Seri wajib sebanyak jumlah dasar. Satuan dus dikonversi ke satuan dasar', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $susu = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter', 'Pelacakan' => PelacakanProduk::Batch]);
        $dus = BantuanPembelian::TambahSatuan($susu, $t['Dus'], '12');
        $hp = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 128 GB Hitam', 'Pelacakan' => PelacakanProduk::Seri]);
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$susu, '2', '216000', '0', $dus], [$hp, '2', '1500000']], $id);

        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaDariPo($po, ['2', '2'], $id, pelacakan: [1 => ['Seri' => ['IMEI-0001']]])))->toBe('BatchWajib');
        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaDariPo($po, ['2', '2'], $id, pelacakan: [0 => ['Batch' => 'LOT-2610'], 1 => ['Seri' => ['IMEI-0001']]])))->toBe('NomorSeriWajib');

        $grn = BantuanPembelian::TerimaDariPo($po, ['2', '2'], $id, pelacakan: [0 => ['Batch' => 'LOT-2610', 'Kedaluwarsa' => '2027-03-31'], 1 => ['Seri' => ['IMEI-0001', 'IMEI-0002']]]);
        $detail = $grn->Detail()->get();

        expect($detail[0]->JumlahDasar)->toBe('24.0000')->and($detail[0]->HppSatuan)->toBe('18000.000000')->and($detail[0]->IdBatchStok)->not->toBeNull()
            ->and(SaldoProduk($susu->Id, $t['Gudang']->Id)->JumlahTersedia)->toBe('24.0000')
            ->and(DB::table('NomorSeri')->where('IdProduk', $hp->Id)->where('Status', 'Tersedia')->count())->toBe(2)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('tanggal masa depan dan periode terkunci ditolak', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $sabun = BantuanKatalog::BuatProduk();

        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$sabun, '1', '1000']], $id, tanggal: BantuanPembelian::Hari(-2))))->toBe('TanggalMasaDepan');
        BantuanPersediaan::KunciPeriode(BantuanPembelian::Hari()->format('Y-m'));
        expect(KodeGalatPembelian(fn () => BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$sabun, '1', '1000']], $id)))->toBe('PeriodeTerkunci')
            ->and(PenerimaanBarang::query()->count())->toBe(0);
    });
});

describe('F-04 faktur, hutang, pembayaran, retur, belanja stok, pembatalan', function (): void {
    it('faktur 3-way: selisih harga BR-04.4 ke Selisih HPP; PPN masukan dikreditkan. Hutang = total faktur', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $t['Outlet']->refresh()->forceFill(['ProfilPajak' => ['Pkp' => true]])->save();
        $pemasok = BantuanPembelian::BuatPemasok('PT Wings Surya Distribusi', true);
        $sabun = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Cuci Piring Jeruk Nipis 780 ml']);
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$sabun, '48', '12000']], $id);
        $grn = BantuanPembelian::TerimaDariPo($po, ['48'], $id);

        // GRN: 576.000; PPN 576.000 × 11/12 × 12% = 63.360 dikreditkan (tidak masuk HPP).
        expect($grn->TotalNilai)->toBe('576000.00')->and($grn->PpnDikreditkan)->toBeTrue();

        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id, [$grn->Detail()->firstOrFail()->Id => '12500']);

        // Faktur 48 × 12.500 = 600.000 + PPN 66.000 = 666.000; selisih harga 24.000 ke Selisih HPP.
        expect($faktur->Nomor)->toMatch('#^FB/\d{4}/0001$#')
            ->and($faktur->Total)->toBe('666000.00')->and($faktur->Pajak)->toBe('66000.00')->and($faktur->SelisihHarga)->toBe('24000.00')
            ->and($faktur->JatuhTempo->toDateString())->toBe(BantuanPembelian::Hari()->addDays(30)->toDateString())
            ->and(BarisJurnalPembelian('FakturPembelian', $faktur->Id))->toBe([
                '2-1150' => ['576000.00', '0.00'],
                '1-1600' => ['66000.00', '0.00'],
                '5-1100' => ['24000.00', '0.00'],
                '2-1100' => ['0.00', '666000.00'],
            ])
            ->and($grn->refresh()->IdFakturPembelian)->toBe($faktur->Id)
            ->and(KodeGalatPembelian(fn () => BantuanPembelian::Fakturkan($pemasok, [$grn], $id)))->toBe('PenerimaanTidakValid')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // Nomor faktur pemasok unik per pemasok.
        $grn2 = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$sabun, '1', '12000']], $id);
        expect(KodeGalatPembelian(fn () => BantuanPembelian::Fakturkan($pemasok, [$grn2], $id, nomor: 'INV/SPN/X1')))->toBeNull()
            ->and(KodeGalatPembelian(fn () => BantuanPembelian::Fakturkan($pemasok, [BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$sabun, '1', '12000']], $id)], $id, nomor: 'INV/SPN/X1')))->toBe('NomorFakturGanda');
    });

    it('pembayaran sebagian & banyak faktur satu pemasok. Lebih dari sisa ditolak. Pembatalan mengembalikan sisa', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $f1 = BantuanPembelian::Fakturkan($pemasok, [BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$mi, '10', '110000']], $id)], $id);
        $f2 = BantuanPembelian::Fakturkan($pemasok, [BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$mi, '5', '110000']], $id)], $id);
        $kas = BantuanPembelian::AkunKas('1-1200');
        $bayar = fn (array $alokasi) => app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, $kas->Uuid, BantuanPembelian::Hari(), $alokasi, null, null, $id));

        expect(KodeGalatPembelian(fn () => $bayar([$f2->Uuid => Uang::Dari('550000.01')])))->toBe('MelebihiSisa')
            ->and(KodeGalatPembelian(fn () => app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, BantuanPembelian::AkunKas('1-1500')->Uuid, BantuanPembelian::Hari(), [$f1->Uuid => Uang::Dari('1')], null, null, $id))))->toBe('AkunKasBankWajib');

        $p = $bayar([$f1->Uuid => Uang::Dari('600000'), $f2->Uuid => Uang::Dari('550000')]);
        expect($p->Nomor)->toMatch('#^BH/\d{4}/0001$#')->and($p->Jumlah)->toBe('1150000.00')
            ->and($f1->refresh()->Status)->toBe(StatusFakturPembelian::DibayarSebagian)->and($f1->AmbilSisa()->KeString())->toBe('500000.00')
            ->and($f2->refresh()->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and(BarisJurnalPembelian('PembayaranHutang', $p->Id))->toBe(['2-1100' => ['1150000.00', '0.00'], '1-1200' => ['0.00', '1150000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        expect(KodeGalatPembelian(fn () => app(BatalkanFakturPembelian::class)->Jalankan($f1, 'Faktur salah input', $id)))->toBe('SudahDibayar');
        app(BatalkanPembayaranHutang::class)->Jalankan($p, 'Transfer ditolak bank', $id);
        expect($f1->refresh()->Status)->toBe(StatusFakturPembelian::BelumDibayar)->and($f2->refresh()->Status)->toBe(StatusFakturPembelian::BelumDibayar)
            ->and($p->refresh()->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('retur sebelum faktur mengurangi hutang belum difakturkan. Sesudah faktur mengurangi sisa hutang faktur. Pembatalan retur membalik', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $kecap = BantuanKatalog::BuatProduk(['Nama' => 'Kecap Manis Botol Kaca 620 ml']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$kecap, '20', '25000']], $id, '20000');
        $d = $grn->Detail()->firstOrFail();
        $retur = fn (string $q) => app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian($grn->Uuid, BantuanPembelian::Hari(), 'Botol pecah saat pengiriman', [new DataBarisReturPembelian($d->Id, Kuantitas::Dari($q))], $id));

        // Nilai landed per botol (500.000 + 20.000) / 20 = 26.000.
        $r1 = $retur('4');
        expect($r1->Nomor)->toMatch('#^RB/[A-Z0-9-]+/\d{4}/0001$#')->and($r1->NilaiBarang)->toBe('104000.00')->and($r1->IdFakturPembelian)->toBeNull()
            ->and(SaldoProduk($kecap->Id, $t['Gudang']->Id)->JumlahTersedia)->toBe('16.0000')
            ->and(BarisJurnalPembelian('ReturPembelian', $r1->Id))->toBe(['2-1150' => ['104000.00', '0.00'], '1-1500' => ['0.00', '104000.00']])
            ->and(KodeGalatPembelian(fn () => $retur('17')))->toBe('MelebihiDiterima');

        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        expect($faktur->Detail()->firstOrFail()->JumlahDasar)->toBe('16.0000')->and($faktur->NilaiPenerimaan)->toBe('416000.00')
            ->and($faktur->Total)->toBe('420000.00')
            ->and(KodeGalatPembelian(fn () => app(BatalkanReturPembelian::class)->Jalankan($r1, 'Ternyata tidak pecah', $id)))->toBe('SudahDifakturkan');

        $r2 = $retur('6');
        // Hutang faktur per botol = (400.000 + 20.000) / 16 = 26.250 → 157.500.
        expect($r2->IdFakturPembelian)->toBe($faktur->Id)->and($r2->NilaiHutang)->toBe('157500.00')
            ->and($faktur->refresh()->AmbilSisa()->KeString())->toBe('262500.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([])
            ->and(KodeGalatPembelian(fn () => app(BatalkanFakturPembelian::class)->Jalankan($faktur, 'Faktur ganda', $id)))->toBe('SudahDiretur')
            ->and(KodeGalatPembelian(fn () => app(BatalkanPenerimaanBarang::class)->Jalankan($grn, 'Salah input', $id)))->toBe('SudahDifakturkan');

        app(BatalkanReturPembelian::class)->Jalankan($r2, 'Barang ternyata diganti pemasok', $id);
        expect($faktur->refresh()->AmbilSisa()->KeString())->toBe('420000.00')
            ->and(SaldoProduk($kecap->Id, $t['Gudang']->Id)->JumlahTersedia)->toBe('16.0000')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('pembatalan GRN membalik stok & jurnal, jumlah diterima PO kembali. Ditolak bila stok sudah terpakai', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $teh = BantuanKatalog::BuatProduk(['Nama' => 'Teh Celup Melati isi 25']);
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$teh, '30', '6500']], $id);
        $grn = BantuanPembelian::TerimaDariPo($po, ['30'], $id);
        BantuanPembelian::Jual($teh, $t['Gudang'], '1');

        expect(KodeGalatPembelian(fn () => app(BatalkanPenerimaanBarang::class)->Jalankan($grn, 'Salah jumlah terima', $id)))->toBe('StokSudahTerpakai');

        $grn2 = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$teh, '10', '6400']], $id);
        $batal = app(BatalkanPenerimaanBarang::class)->Jalankan($grn2, 'Salah pilih lokasi stok', $id);
        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(Jurnal::query()->where('JenisSumber', 'PenerimaanBarang')->where('IdSumber', $grn2->Id)->where('KunciSumber', 'Pembatalan')->value('IdJurnalDibalik'))->toBe($grn2->IdJurnal)
            ->and(SaldoProduk($teh->Id, $t['Gudang']->Id)->JumlahTersedia)->toBe('29.0000')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // GRN dari PO yang dibatalkan mengembalikan jumlah diterima & status PO.
        $po2 = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$teh, '5', '6500']], $id);
        $grn3 = BantuanPembelian::TerimaDariPo($po2, ['5'], $id);
        expect($po2->refresh()->Status)->toBe(StatusPesananPembelian::Diterima);
        app(BatalkanPenerimaanBarang::class)->Jalankan($grn3, 'Barang dikembalikan utuh', $id);
        expect($po2->refresh()->Status)->toBe(StatusPesananPembelian::Disetujui)->and($po2->Detail()->firstOrFail()->JumlahDiterima)->toBe('0.0000');
    });

    it('belanja stok satu langkah: GRN + faktur lunas + pembayaran, satu jurnal J-04.3. Dibatalkan utuh', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $cabai = BantuanKatalog::BuatProduk(['Nama' => 'Cabai Rawit Merah Segar'], null, $t['Kg']);
        $tahu = BantuanKatalog::BuatProduk(['Nama' => 'Tahu Putih Kotak isi 10']);
        $kas = BantuanPembelian::AkunKas();

        $grn = app(SimpanBelanjaStok::class)->Jalankan(new DataBelanjaStok(null, $t['Gudang']->Id, BantuanPembelian::Hari(), $kas->Uuid, 'NOTA-PASAR-17', Uang::Dari('10000'), 'Belanja pasar Gede pagi', [
            new DataBarisPenerimaanBarang(null, $cabai->Uuid, null, Kuantitas::Dari('2.5'), Uang::Dari('60000'), Uang::Nol()),
            new DataBarisPenerimaanBarang(null, $tahu->Uuid, null, Kuantitas::Dari('3'), Uang::Dari('15000'), Uang::Nol()),
        ], null, $id));

        $faktur = FakturPembelian::query()->whereKey($grn->IdFakturPembelian)->firstOrFail();
        expect($grn->BelanjaStok)->toBeTrue()->and($faktur->Status)->toBe(StatusFakturPembelian::Lunas)->and($faktur->Total)->toBe('205000.00')
            ->and(Jurnal::query()->where('JenisSumber', 'FakturPembelian')->count())->toBe(0)
            ->and(Jurnal::query()->where('JenisSumber', 'PembayaranHutang')->count())->toBe(0)
            ->and(BarisJurnalPembelian('PenerimaanBarang', $grn->Id))->toBe(['1-1500' => ['205000.00', '0.00'], '1-1100' => ['0.00', '205000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([])
            ->and(KodeGalatPembelian(fn () => app(BatalkanFakturPembelian::class)->Jalankan($faktur, 'Batal dari faktur', $id)))->toBe('BagianBelanjaStok');

        app(BatalkanPenerimaanBarang::class)->Jalankan($grn, 'Belanja dicatat dua kali', $id);
        expect($faktur->refresh()->Status)->toBe(StatusFakturPembelian::Dibatalkan)
            ->and(DB::table('PembayaranHutang')->where('BelanjaStok', true)->value('Status'))->toBe('Dibatalkan')
            ->and(BarisJurnalPembelian('PenerimaanBarang', $grn->Id, 'Pembatalan'))->toBe(['1-1100' => ['205000.00', '0.00'], '1-1500' => ['0.00', '205000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('FIFO: penerimaan membuat lapisan. Invarian lapisan terjaga', function (): void {
        $t = BantuanPembelian::SiapkanTenant(MetodeHpp::Fifo);
        $id = $t['Pemilik']->Id;
        $roti = BantuanKatalog::BuatProduk(['Nama' => 'Roti Tawar Kupas Gandum 400 gram']);
        BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$roti, '10', '14000']], $id);
        BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$roti, '10', '15000']], $id);
        BantuanPembelian::Jual($roti, $t['Gudang'], '12');

        expect(SaldoProduk($roti->Id, $t['Gudang']->Id)->NilaiPersediaan)->toBe('120000.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id, true))->toBe([]);
    });
});

describe('F-04 invarian gabungan', function (): void {
    it('Σ debit = Σ kredit, SaldoStok = Σ MutasiStok, saldo HutangUsaha = Σ sisa faktur setelah alur lengkap', function (): void {
        $t = BantuanPembelian::SiapkanTenant(stokBolehMinus: true);
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok('PT Sinar Sosro Jateng', true);
        $a = BantuanKatalog::BuatProduk(['Nama' => 'Teh Botol Kotak 250 ml isi 24']);
        $b = BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Gelas 220 ml isi 48']);
        $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$a, '10', '52000'], [$b, '20', '19500', '10000']], $id, '25000');
        $g1 = BantuanPembelian::TerimaDariPo($po, ['6', '20'], $id, '25000');
        BantuanPembelian::Jual($a, $t['Gudang'], '9');
        $g2 = BantuanPembelian::TerimaDariPo($po, ['4'], $id);
        $f = BantuanPembelian::Fakturkan($pemasok, [$g1, $g2], $id, [$g1->Detail()->firstOrFail()->Id => '53000']);
        app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian($g1->Uuid, BantuanPembelian::Hari(), 'Kemasan penyok', [new DataBarisReturPembelian($g1->Detail()->get()[1]->Id, Kuantitas::Dari('3'))], $id));
        app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, BantuanPembelian::Hari(), [$f->Uuid => Uang::Dari('300000')], null, null, $id));

        expect(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([])
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::HutangUsaha))->toBe(Uang::Nol()->Kurangi($f->refresh()->AmbilSisa())->KeString());
    });
});
