<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\BatalkanTransferStok;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Aksi\TutupTransferStok;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b transfer stok (PRD Rincian F-05b, J-05.2/J-05.3/J-05.4): Draf → Dikirim → DiterimaSebagian → Diterima
 * (+ Dibatalkan). Kirim = TransferKeluar asal → TransferMasuk lokasi Dalam perjalanan (HPP asal); terima = keluar
 * dalam perjalanan → masuk tujuan (HPP kirim); tutup = selisih jadi Susut. Setiap skenario diakhiri invarian:
 * Σ debit = Σ kredit, SaldoStok = Σ MutasiStok, nilai persediaan = saldo akun persediaan (termasuk dalam perjalanan).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanTransfer(MetodeHpp $metode = MetodeHpp::RataRata): array
{
    $t = BantuanPersediaan::SiapkanTenant(metodeHpp: $metode);
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
        BantuanStokAwal::Baris($p['BahanBaku'], '50.5', '14750'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p, 'Cabang' => $cabang, 'GudangCabang' => $gudangCabang];
}

describe('F-05b transfer stok: status & alur', function (): void {
    it('kirim: TransferKeluar asal + TransferMasuk lokasi Dalam perjalanan outlet asal, nomor TF, jurnal Dr PDP / Cr Persediaan (J-05.2)', function (): void {
        $t = SiapkanTransfer();
        $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30'), B::Baris($t['Produk']['BahanBaku'], '10.25')]);

        expect($draf->Status)->toBe(StatusTransferStok::Draf)->and($draf->Nomor)->toBeNull()
            ->and(MutasiStok::query()->where('JenisReferensi', 'TransferStok')->count())->toBe(0);

        $dikirim = app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id);
        $transit = Gudang::query()->findOrFail($dikirim->IdGudangTransit);
        $kemarin = CarbonImmutable::parse(B::Kemarin());

        expect($dikirim->Status)->toBe(StatusTransferStok::Dikirim)
            ->and($dikirim->Nomor)->toBe('TF/'.mb_substr($t['Gudang']->Kode, 0, 13).'-'.$t['GudangCabang']->Kode.'/'.$kemarin->format('ym').'/0001')
            ->and($transit->Jenis)->toBe(JenisGudang::DalamPerjalanan)
            ->and($transit->IdOutlet)->toBe($t['Outlet']->Id)
            ->and($dikirim->TotalNilaiKirim)->toBe('1306187.50')
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['70.0000', '2695000.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $transit))->toBe(['30.0000', '1155000.00'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'TransferStok')->orderBy('Id')->pluck('JenisMutasi')->map(fn ($j) => $j->value)->all())
            ->toBe(['TransferKeluar', 'TransferKeluar', 'TransferMasuk', 'TransferMasuk']);

        $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TransferStok->value)->where('IdSumber', $dikirim->Id)->sole();
        expect($jurnal->KunciSumber)->toBe('Kirim')
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanDalamPerjalanan', '1306187.50', '0.00', $t['Outlet']->Id],
                ['PersediaanBarangDagang', '0.00', '1155000.00', $t['Outlet']->Id],
                ['PersediaanBahanBaku', '0.00', '151187.50', $t['Outlet']->Id],
            ])
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'TransferStok')->where('IdDokumen', $dikirim->Id)->pluck('StatusKe')->all())->toBe(['Draf', 'Dikirim'])
            ->and(LogAudit::query()->where('Peristiwa', 'transfer-stok.kirim')->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        // Kirim ulang idempoten: tidak ada mutasi/jurnal kedua.
        app(KirimTransferStok::class)->Jalankan($dikirim, $t['Pemilik']->Id);
        expect(MutasiStok::query()->where('JenisReferensi', 'TransferStok')->count())->toBe(4)
            ->and(Jurnal::query()->where('JenisSumber', 'TransferStok')->count())->toBe(1);
    });

    it('lokasi Dalam perjalanan dipakai ulang per outlet asal dan tidak bisa dipilih sebagai asal/tujuan', function (): void {
        $t = SiapkanTransfer();
        $pertama = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '1')]), $t['Pemilik']->Id);
        $kedua = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '2')]), $t['Pemilik']->Id);
        $transit = Gudang::query()->findOrFail($pertama->IdGudangTransit);

        expect($kedua->IdGudangTransit)->toBe($pertama->IdGudangTransit)
            ->and(Gudang::query()->where('Jenis', JenisGudang::DalamPerjalanan->value)->count())->toBe(1)
            ->and($kedua->Nomor)->toEndWith('/0002')
            ->and(B::KodeGalat(fn () => B::DrafTransfer($transit, $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '1')])))->toBe('GudangDalamPerjalanan')
            ->and(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $t['Gudang'], [B::Baris($t['Produk']['Stok'], '1')])))->toBe('LokasiSama');
    });

    it('terima parsial lalu tutup dengan selisih: DiterimaSebagian → Diterima, susut dari dalam perjalanan (J-05.3, J-05.4)', function (): void {
        $t = SiapkanTransfer();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);
        // Tanggal bisnis (jam tutup buku bawaan 04:00 WIB), bukan tanggal kalender WIB.
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->subHours(4)->startOfDay();

        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('12'))], $hariIni, $t['Pemilik']->Id);
        expect($transfer->Status)->toBe(StatusTransferStok::DiterimaSebagian)
            ->and(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['12.0000', '462000.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['18.0000', '693000.00']);

        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('15'))], $hariIni, $t['Pemilik']->Id);
        expect($transfer->Status)->toBe(StatusTransferStok::DiterimaSebagian)
            ->and(B::KodeGalat(fn () => app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('4'))], $hariIni, $t['Pemilik']->Id)))->toBe('JumlahMelebihiSisa')
            ->and(B::KodeGalat(fn () => app(TutupTransferStok::class)->Jalankan($transfer, '', $t['Pemilik']->Id)))->toBe('AlasanWajib');

        $transfer = app(TutupTransferStok::class)->Jalankan($transfer, '3 botol pecah di perjalanan', $t['Pemilik']->Id);
        $detail = $transfer->Detail()->sole();
        $susut = MutasiStok::query()->where('KunciBaris', 'S/'.$detail->Id)->sole();
        $jurnalTutup = Jurnal::query()->where('JenisSumber', 'TransferStok')->where('KunciSumber', 'Tutup')->sole();

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($transfer->AlasanSelisih)->toBe('3 botol pecah di perjalanan')
            ->and($transfer->TotalNilaiSusut)->toBe('115500.00')
            ->and($detail->JumlahDiterima)->toBe('27.0000')
            ->and($detail->JumlahSusut)->toBe('3.0000')
            ->and($susut->JenisMutasi)->toBe(JenisMutasi::Susut)
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['0.0000', '0.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['27.0000', '1039500.00'])
            ->and(B::BarisJurnal($jurnalTutup->Id))->toEqualCanonicalizing([
                ['SusutPersediaan', '115500.00', '0.00', $t['Outlet']->Id],
                ['PersediaanDalamPerjalanan', '0.00', '115500.00', $t['Outlet']->Id],
            ])
            ->and(Jurnal::query()->where('JenisSumber', 'TransferStok')->pluck('KunciSumber')->all())->toBe(['Kirim', 'Terima1', 'Terima2', 'Tutup'])
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'TransferStok')->where('IdDokumen', $transfer->Id)->pluck('StatusKe')->all())->toBe(['Draf', 'Dikirim', 'DiterimaSebagian', 'Diterima'])
            ->and(B::KodeGalat(fn () => app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('1'))], $hariIni, $t['Pemilik']->Id)))->toBe('StatusTidakSesuai')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('terima penuh sekaligus langsung Diterima. Jurnal terima Dr persediaan tujuan (outlet tujuan) / Cr PDP (outlet asal)', function (): void {
        $t = SiapkanTransfer();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '10'), B::Baris($t['Produk']['BahanBaku'], '0.5')]), $t['Pemilik']->Id);
        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('10')), new DataTerimaTransfer(2, Kuantitas::Dari('0.5'))], CarbonImmutable::parse(B::Kemarin()), $t['Pemilik']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'TransferStok')->where('KunciSumber', 'Terima1')->sole();

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($transfer->TotalNilaiDiterima)->toBe($transfer->TotalNilaiKirim)
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanBarangDagang', '385000.00', '0.00', $t['Cabang']->Id],
                ['PersediaanBahanBaku', '7375.00', '0.00', $t['Cabang']->Id],
                ['PersediaanDalamPerjalanan', '0.00', '392375.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('batal hanya sebelum dikirim. Stok asal tidak cukup = StokTidakCukup tanpa perubahan', function (): void {
        $t = SiapkanTransfer();
        $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '150')]);

        expect(B::KodeGalat(fn () => app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('StokTidakCukup')
            ->and(TransferStok::query()->findOrFail($draf->Id)->Status)->toBe(StatusTransferStok::Draf)
            ->and(MutasiStok::query()->where('JenisReferensi', 'TransferStok')->count())->toBe(0);

        $batal = app(BatalkanTransferStok::class)->Jalankan($draf, 'Salah pilih lokasi tujuan', $t['Pemilik']->Id);
        expect($batal->Status)->toBe(StatusTransferStok::Dibatalkan)
            ->and(B::KodeGalat(fn () => app(KirimTransferStok::class)->Jalankan($batal, $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');

        $dikirim = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '1')]), $t['Pemilik']->Id);
        expect(B::KodeGalat(fn () => app(BatalkanTransferStok::class)->Jalankan($dikirim, 'Terlanjur dikirim', $t['Pemilik']->Id)))->toBe('StatusTidakSesuai')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05b transfer stok: penilaian HPP kirim/terima', function (): void {
    it('terima dinilai HPP kirim walau HPP asal berubah setelah dikirim (rata-rata bergerak)', function (): void {
        $t = SiapkanTransfer();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '20')]), $t['Pemilik']->Id);
        // HPP asal naik setelah pengiriman (penyesuaian masuk harga mahal).
        $ps = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Lainnya, [B::Baris($t['Produk']['Stok'], '10', '60000')], 'Barang ditemukan di gudang belakang');
        app(AjukanPenyesuaianStok::class)->Jalankan($ps, $t['Pemilik']->Id);

        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('7'))], CarbonImmutable::parse(B::Kemarin()), $t['Pemilik']->Id);
        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('13'))], CarbonImmutable::parse(B::Kemarin()), $t['Pemilik']->Id);

        expect(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['20.0000', '770000.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['0.0000', '0.00'])
            ->and(MutasiStok::query()->where('IdGudang', $t['GudangCabang']->Id)->pluck('HppSatuan')->unique()->values()->all())->toBe(['38500.000000'])
            ->and(TransferStok::query()->findOrFail($transfer->Id)->Status)->toBe(StatusTransferStok::Diterima)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('FIFO: kirim mengambil lapisan tertua. Nilai kirim = nilai terima', function (): void {
        $t = SiapkanTransfer(MetodeHpp::Fifo);
        $ps = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Lainnya, [B::Baris($t['Produk']['Stok'], '10', '41000')], 'Stok titipan lama ditemukan');
        app(AjukanPenyesuaianStok::class)->Jalankan($ps, $t['Pemilik']->Id);
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '105')]), $t['Pemilik']->Id);

        expect($transfer->TotalNilaiKirim)->toBe('4055000.00'); // 100 × 38.500 + 5 × 41.000

        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('105'))], CarbonImmutable::parse(B::Kemarin()), $t['Pemilik']->Id);
        expect(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['105.0000', '4055000.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id, fifo: true))->toBe([]);
    });
});

describe('F-05b transfer stok: batch & nomor seri', function (): void {
    it('batch dan nomor seri ikut pindah lewat lokasi dalam perjalanan', function (): void {
        $t = SiapkanTransfer();
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($t['Produk']['Batch'], '40', '19500', 'UHT-2609A', '2027-03-31'),
            BantuanStokAwal::Baris($t['Produk']['Seri'], '2', '675000', nomorSeri: ['RC-0001', 'RC-0002']),
        ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->sole();
        $seri = NomorSeri::query()->where('Nomor', 'RC-0002')->sole();

        expect(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Batch'], '5')])))->toBe('BarisTidakValid')
            ->and(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Batch'], '41', idBatch: $batch->Id)])))->toBe('BarisTidakValid');

        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [
            B::Baris($t['Produk']['Batch'], '15', idBatch: $batch->Id),
            B::Baris($t['Produk']['Seri'], '1', idSeri: $seri->Id),
        ]), $t['Pemilik']->Id);
        $seri->refresh();

        expect($seri->Status)->toBe(StatusNomorSeri::Tersedia)->and($seri->IdGudang)->toBe($transfer->IdGudangTransit)
            ->and(BatchStok::query()->where('NomorBatch', 'UHT-2609A')->where('IdGudang', $transfer->IdGudangTransit)->value('JumlahSisa'))->toBe('15.0000');

        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('15')), new DataTerimaTransfer(2, Kuantitas::Dari('1'))], CarbonImmutable::parse(B::Kemarin()), $t['Pemilik']->Id);
        $seri->refresh();
        $batchCabang = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->where('IdGudang', $t['GudangCabang']->Id)->sole();

        expect($seri->IdGudang)->toBe($t['GudangCabang']->Id)
            ->and($batchCabang->JumlahSisa)->toBe('15.0000')
            ->and($batchCabang->TanggalKedaluwarsa?->format('Y-m-d'))->toBe('2027-03-31')
            ->and($batch->refresh()->JumlahSisa)->toBe('25.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05b transfer stok: periode terkunci', function (): void {
    it('kirim bertanggal di periode terkunci ditolak PeriodeTerkunci tanpa perubahan', function (): void {
        $t = SiapkanTransfer();
        $tanggal = CarbonImmutable::now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth()->addDays(2);
        BantuanStokAwal::BuatDanPosting($t['GudangCabang'], [BantuanStokAwal::Baris($t['Produk']['Stok'], '5', '38500')], $t['Pemilik']->Id, $tanggal->subDay()->format('Y-m-d'));
        $draf = B::DrafTransfer($t['GudangCabang'], $t['Gudang'], [B::Baris($t['Produk']['Stok'], '2')], $tanggal->format('Y-m-d'));
        BantuanPersediaan::KunciPeriode($tanggal->format('Y-m'));

        expect(B::KodeGalat(fn () => app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('PeriodeTerkunci')
            ->and(TransferStok::query()->findOrFail($draf->Id)->Status)->toBe(StatusTransferStok::Draf)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});
