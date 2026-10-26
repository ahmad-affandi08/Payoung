<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Aksi\BatalkanPembayaranHutang;
use App\Domain\Pembelian\Aksi\BatalkanReturPembelian;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStok;
use App\Domain\Pembelian\Aksi\SimpanPembayaranHutang;
use App\Domain\Pembelian\Aksi\SimpanReturPembelian;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBarisReturPembelian;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Data\DataPembayaranHutang;
use App\Domain\Pembelian\Data\DataReturPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * E8 retur pembelian dengan pengembalian uang dari pemasok: retur belanja stok tunai dan retur atas faktur yang sudah
 * dibayar. Nilai retur dibagi: bagian yang memotong sisa hutang faktur, sisanya diterima tunai ke akun kas/bank pilihan
 * operator (Dr kas/bank). Pembatalan membalik keduanya.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function KodeGalatRefund(Closure $fn): ?string
{
    try {
        $fn();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

/**
 * @return array<string, array{0: string, 1: string}>
 */
function JurnalRefund(string $jenis, int $idSumber, string $kunci = 'Utama'): array
{
    $jurnal = Jurnal::query()->where('JenisSumber', $jenis)->where('IdSumber', $idSumber)->where('KunciSumber', $kunci)->sole();
    $hasil = [];

    foreach (DB::table('JurnalDetail')->join('Akun', 'Akun.Id', '=', 'JurnalDetail.IdAkun')->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get(['Akun.Kode', 'Debit', 'Kredit']) as $d) {
        $hasil[(string) $d->Kode] = [(string) $d->Debit, (string) $d->Kredit];
    }

    return $hasil;
}

describe('E8 retur pembelian dengan pengembalian uang', function (): void {
    it('retur belanja stok tunai: uang kembali ke akun pilihan (Dr kas, Cr persediaan), tanpa akun ditolak. Pembatalan membalik', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $tahu = BantuanKatalog::BuatProduk(['Nama' => 'Tahu Putih Kotak isi 10']);
        $kas = BantuanPembelian::AkunKas();
        $grn = app(SimpanBelanjaStok::class)->Jalankan(new DataBelanjaStok(null, $t['Gudang']->Id, BantuanPembelian::Hari(), $kas->Uuid, 'NOTA-PASAR-21', Uang::Nol(), 'Belanja pasar pagi', [
            new DataBarisPenerimaanBarang(null, $tahu->Uuid, null, Kuantitas::Dari('10'), Uang::Dari('15000'), Uang::Nol()),
        ], null, $id));
        $detail = $grn->Detail()->firstOrFail();
        $retur = fn (?string $uuidAkun, string $jumlah = '4') => app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian(
            $grn->Uuid,
            BantuanPembelian::Hari(),
            'Tahu basi saat dicek',
            [new DataBarisReturPembelian($detail->Id, Kuantitas::Dari($jumlah))],
            $id,
            $uuidAkun,
        ));

        expect(KodeGalatRefund(fn () => $retur(null)))->toBe('AkunRefundWajib')
            ->and(KodeGalatRefund(fn () => $retur(BantuanPembelian::AkunKas('1-1500')->Uuid)))->toBe('AkunRefundWajib');

        $r = $retur($kas->Uuid);
        $faktur = FakturPembelian::query()->whereKey($grn->IdFakturPembelian)->firstOrFail();

        expect($r->NilaiBarang)->toBe('60000.00')->and($r->NilaiRefund)->toBe('60000.00')->and($r->IdAkunRefund)->toBe($kas->Id)
            ->and(JurnalRefund('ReturPembelian', $r->Id))->toBe(['1-1100' => ['60000.00', '0.00'], '1-1500' => ['0.00', '60000.00']])
            // Faktur belanja sudah lunas: tidak ada sisa tagihan yang dikurangi.
            ->and($faktur->refresh()->JumlahRetur)->toBe('0.00')->and($faktur->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        app(BatalkanReturPembelian::class)->Jalankan($r, 'Tahu ternyata masih layak', $id);
        expect($r->refresh()->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(JurnalRefund('ReturPembelian', $r->Id, 'Pembatalan'))->toBe(['1-1500' => ['60000.00', '0.00'], '1-1100' => ['0.00', '60000.00']])
            ->and($faktur->refresh()->JumlahRetur)->toBe('0.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('retur atas faktur yang sebagian dibayar: memotong sisa hutang, selebihnya uang kembali. Pembayaran terkunci selama retur berlaku', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$mi, '10', '110000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $kas = BantuanPembelian::AkunKas('1-1200');
        $bayar = app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, $kas->Uuid, BantuanPembelian::Hari(), [$faktur->Uuid => Uang::Dari('1000000')], null, null, $id));
        $detail = $grn->Detail()->firstOrFail();

        // Faktur 1.100.000, dibayar 1.000.000 → sisa 100.000. Retur 2 dus (220.000) melebihi sisa.
        $retur = fn (?string $uuidAkun) => app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian($grn->Uuid, BantuanPembelian::Hari(), 'Dus penyok saat diterima', [new DataBarisReturPembelian($detail->Id, Kuantitas::Dari('2'))], $id, $uuidAkun));

        expect(KodeGalatRefund(fn () => $retur(null)))->toBe('AkunRefundWajib');

        $r = $retur($kas->Uuid);
        expect($r->NilaiHutang)->toBe('220000.00')->and($r->NilaiRefund)->toBe('120000.00')
            ->and(JurnalRefund('ReturPembelian', $r->Id))->toBe(['2-1100' => ['100000.00', '0.00'], '1-1200' => ['120000.00', '0.00'], '1-1500' => ['0.00', '220000.00']])
            ->and($faktur->refresh()->JumlahRetur)->toBe('100000.00')->and($faktur->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and($faktur->AmbilSisa()->KeString())->toBe('0.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([])
            ->and(KodeGalatRefund(fn () => app(BatalkanPembayaranHutang::class)->Jalankan($bayar, 'Transfer ditolak bank', $id)))->toBe('AdaReturBerefund');

        app(BatalkanReturPembelian::class)->Jalankan($r, 'Dus ternyata tidak rusak', $id);
        expect($faktur->refresh()->JumlahRetur)->toBe('0.00')->and($faktur->AmbilSisa()->KeString())->toBe('100000.00')
            ->and($faktur->Status)->toBe(StatusFakturPembelian::DibayarSebagian)
            ->and(JurnalRefund('ReturPembelian', $r->Id, 'Pembatalan'))->toBe(['1-1500' => ['220000.00', '0.00'], '2-1100' => ['0.00', '100000.00'], '1-1200' => ['0.00', '120000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // Setelah retur dibatalkan, pembayaran bisa dibatalkan lagi.
        app(BatalkanPembayaranHutang::class)->Jalankan($bayar, 'Transfer ditolak bank', $id);
        expect($faktur->refresh()->Status)->toBe(StatusFakturPembelian::BelumDibayar);
    });

    it('retur kecil yang masih di bawah sisa hutang tidak butuh akun dan tidak menghasilkan refund', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $kecap = BantuanKatalog::BuatProduk(['Nama' => 'Kecap Manis Botol Kaca 620 ml']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$kecap, '20', '25000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $detail = $grn->Detail()->firstOrFail();

        $r = app(SimpanReturPembelian::class)->Jalankan(new DataReturPembelian($grn->Uuid, BantuanPembelian::Hari(), 'Botol pecah saat pengiriman', [new DataBarisReturPembelian($detail->Id, Kuantitas::Dari('4'))], $id));

        expect($r->NilaiRefund)->toBe('0.00')->and($r->IdAkunRefund)->toBeNull()
            ->and($faktur->refresh()->JumlahRetur)->toBe($r->NilaiHutang)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });
});
