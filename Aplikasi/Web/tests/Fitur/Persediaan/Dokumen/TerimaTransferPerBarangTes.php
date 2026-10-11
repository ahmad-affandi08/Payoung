<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Aksi\TutupTransferStok;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b, D-105: terima transfer per barang. Satu barang bisa dibagi tiga: diterima (masuk stok tujuan), rusak (susut
 * dari lokasi dalam perjalanan, J-05.4), dan ditolak (kembali ke lokasi asal pada HPP kirim). Setiap skenario diakhiri
 * invarian: Σ debit = Σ kredit, SaldoStok = Σ MutasiStok, nilai persediaan = saldo akun persediaan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanTransferPerBarang(): array
{
    $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::RataRata);
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p, 'Cabang' => $cabang, 'GudangCabang' => $gudangCabang];
}

/** Tanggal bisnis (jam tutup buku bawaan 04:00 WIB), bukan tanggal kalender WIB. */
function HariTerimaPerBarang(): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Jakarta')->subHours(4)->startOfDay();
}

describe('D-105 terima per barang: diterima, rusak, ditolak', function (): void {
    it('satu penerimaan dibagi tiga: stok tujuan, susut rusak, dan stok kembali ke asal; semua jurnal seimbang', function (): void {
        $t = SiapkanTransferPerBarang();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);

        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [
            new DataTerimaTransfer(1, Kuantitas::Dari('20'), Kuantitas::Dari('3'), Kuantitas::Dari('7')),
        ], HariTerimaPerBarang(), $t['Pemilik']->Id);
        $detail = $transfer->Detail()->sole();

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($detail->JumlahDiterima)->toBe('20.0000')->and($detail->NilaiDiterima)->toBe('770000.00')
            ->and($detail->JumlahSusut)->toBe('3.0000')->and($detail->NilaiSusut)->toBe('115500.00')
            ->and($detail->JumlahDitolak)->toBe('7.0000')->and($detail->NilaiDitolak)->toBe('269500.00')
            ->and($transfer->TotalNilaiDiterima)->toBe('770000.00')
            ->and($transfer->TotalNilaiSusut)->toBe('115500.00')
            // Tujuan hanya menerima yang layak jual; transit habis; asal mendapat kembali 7.
            ->and(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['20.0000', '770000.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['0.0000', '0.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['77.0000', '2964500.00'])
            ->and(Jurnal::query()->where('JenisSumber', 'TransferStok')->pluck('KunciSumber')->all())->toEqualCanonicalizing(['Kirim', 'Terima1', 'Rusak1', 'Tolak1'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'TransferStok')->where('KunciBaris', 'like', 'X1/%')->sole()->JenisMutasi)->toBe(JenisMutasi::Susut);

        $rusak = Jurnal::query()->where('JenisSumber', 'TransferStok')->where('KunciSumber', 'Rusak1')->sole();
        $tolak = Jurnal::query()->where('JenisSumber', 'TransferStok')->where('KunciSumber', 'Tolak1')->sole();

        expect(B::BarisJurnal($rusak->Id))->toEqualCanonicalizing([
            ['SusutPersediaan', '115500.00', '0.00', $t['Outlet']->Id],
            ['PersediaanDalamPerjalanan', '0.00', '115500.00', $t['Outlet']->Id],
        ])->and(B::BarisJurnal($tolak->Id))->toEqualCanonicalizing([
            ['PersediaanBarangDagang', '269500.00', '0.00', $t['Outlet']->Id],
            ['PersediaanDalamPerjalanan', '0.00', '269500.00', $t['Outlet']->Id],
        ])->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('penerimaan lama yang hanya mengisi jumlah diterima berperilaku sama: tanpa jurnal rusak/tolak', function (): void {
        $t = SiapkanTransferPerBarang();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);
        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('30'))], HariTerimaPerBarang(), $t['Pemilik']->Id);

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($transfer->TotalNilaiSusut)->toBe('0.00')
            ->and(Jurnal::query()->where('JenisSumber', 'TransferStok')->pluck('KunciSumber')->all())->toBe(['Kirim', 'Terima1'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('penerimaan sebagian: rusak ikut mengurangi sisa; ditolak di penerimaan kedua menyelesaikan transfer', function (): void {
        $t = SiapkanTransferPerBarang();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);

        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('10'), Kuantitas::Dari('2'))], HariTerimaPerBarang(), $t['Pemilik']->Id);
        expect($transfer->Status)->toBe(StatusTransferStok::DiterimaSebagian)
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['18.0000', '693000.00']);

        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Nol(), null, Kuantitas::Dari('18'))], HariTerimaPerBarang(), $t['Pemilik']->Id);

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($transfer->Detail()->sole()->JumlahDitolak)->toBe('18.0000')
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['0.0000', '0.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['88.0000', '3388000.00'])
            ->and(B::Saldo($t['Produk']['Stok'], $t['GudangCabang']))->toBe(['10.0000', '385000.00'])
            ->and(Jurnal::query()->where('JenisSumber', 'TransferStok')->pluck('KunciSumber')->all())->toEqualCanonicalizing(['Kirim', 'Terima1', 'Rusak1', 'Tolak2'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('diterima + rusak + ditolak melebihi sisa = JumlahMelebihiSisa tanpa mengubah apa pun', function (): void {
        $t = SiapkanTransferPerBarang();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => app(TerimaTransferStok::class)->Jalankan($transfer, [
            new DataTerimaTransfer(1, Kuantitas::Dari('20'), Kuantitas::Dari('8'), Kuantitas::Dari('4')),
        ], HariTerimaPerBarang(), $t['Pemilik']->Id)))->toBe('JumlahMelebihiSisa')
            ->and(B::KodeGalat(fn () => app(TerimaTransferStok::class)->Jalankan($transfer, [
                new DataTerimaTransfer(1, Kuantitas::Nol(), Kuantitas::Dari('-1')),
            ], HariTerimaPerBarang(), $t['Pemilik']->Id)))->toBe('JumlahTidakValid')
            ->and(B::KodeGalat(fn () => app(TerimaTransferStok::class)->Jalankan($transfer, [
                new DataTerimaTransfer(1, Kuantitas::Nol()),
            ], HariTerimaPerBarang(), $t['Pemilik']->Id)))->toBe('BarisKosong');

        expect($transfer->refresh()->Status)->toBe(StatusTransferStok::Dikirim)
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['30.0000', '1155000.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('tutup dengan selisih setelah ada rusak: susut menjumlah (rusak + sisa), tidak menimpa', function (): void {
        $t = SiapkanTransferPerBarang();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);
        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('10'), Kuantitas::Dari('5'))], HariTerimaPerBarang(), $t['Pemilik']->Id);
        $transfer = app(TutupTransferStok::class)->Jalankan($transfer, 'Sisa 15 tidak pernah sampai', $t['Pemilik']->Id);
        $detail = $transfer->Detail()->sole();

        expect($transfer->Status)->toBe(StatusTransferStok::Diterima)
            ->and($detail->JumlahSusut)->toBe('20.0000')->and($detail->NilaiSusut)->toBe('770000.00')
            ->and($transfer->TotalNilaiSusut)->toBe('770000.00')
            ->and(B::Saldo($t['Produk']['Stok'], $transfer->IdGudangTransit))->toBe(['0.0000', '0.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('batch dan nomor seri yang ditolak kembali ke lokasi asal', function (): void {
        $t = SiapkanTransferPerBarang();
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($t['Produk']['Batch'], '40', '19500', 'UHT-2609A', '2027-03-31'),
            BantuanStokAwal::Baris($t['Produk']['Seri'], '2', '675000', nomorSeri: ['RC-0001', 'RC-0002']),
        ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->sole();
        $seri = NomorSeri::query()->where('Nomor', 'RC-0002')->sole();
        $transfer = app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [
            B::Baris($t['Produk']['Batch'], '15', idBatch: $batch->Id),
            B::Baris($t['Produk']['Seri'], '1', idSeri: $seri->Id),
        ]), $t['Pemilik']->Id);

        app(TerimaTransferStok::class)->Jalankan($transfer, [
            new DataTerimaTransfer(1, Kuantitas::Dari('10'), null, Kuantitas::Dari('5')),
            new DataTerimaTransfer(2, Kuantitas::Nol(), null, Kuantitas::Dari('1')),
        ], HariTerimaPerBarang(), $t['Pemilik']->Id);
        $seri->refresh();

        expect($seri->Status)->toBe(StatusNomorSeri::Tersedia)->and($seri->IdGudang)->toBe($t['Gudang']->Id)
            ->and($batch->refresh()->JumlahSisa)->toBe('30.0000')
            ->and(BatchStok::query()->where('NomorBatch', 'UHT-2609A')->where('IdGudang', $t['GudangCabang']->Id)->sole()->JumlahSisa)->toBe('10.0000')
            ->and(BatchStok::query()->where('NomorBatch', 'UHT-2609A')->where('IdGudang', $transfer->IdGudangTransit)->sole()->JumlahSisa)->toBe('0.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});
