<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-12 bagian 1 (PRD "Rincian F-12"): penjualan tempo dari POS membuat piutang (jurnal Dr Piutang Usaha, jatuh tempo =
 * tanggal bisnis + termin), BR-12.1 (limit kredit & piutang lewat jatuh tempo butuh penyetuju `penjualan.tempo.setujui`;
 * data cache basi = diterima + tinjauan), void membatalkan piutang, retur memotong piutang lebih dulu, dan data kredit di
 * pencarian pelanggan POS.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk Rp 38.500 + pelanggan Toko Makmur (limit [limit], termin 14 hari).
 *
 * @return array<string, mixed>
 */
function SiapkanTempo(TestCase $tes, ?string $limit = '500000'): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Grosir Sembako Tempo');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550001', 'LimitKredit' => $limit, 'TerminHari' => 14]);

    return $k + ['Produk' => $produk, 'Toko' => $toko];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $opsi
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemTempo(array $k, array $opsi = [], array $timpa = []): array
{
    return BantuanPenjualan::Item(
        $k,
        $opsi + ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '77000.00']]],
        $timpa + ['UuidPelanggan' => $k['Toko']->Uuid],
    );
}

function SaldoPiutangUsahaJurnal(Penjualan $p): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::PiutangUsaha, $p->IdOutlet);
    $saldo = Kuantitas::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $p->IdJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Kuantitas::Dari($b->Debit))->Kurangi(Kuantitas::Dari($b->Kredit));
    }

    return (string) $saldo->KeDesimal()->toScale(2);
}

describe('F-12 penjualan tempo', function (): void {
    it('membuat piutang (jatuh tempo = tanggal bisnis + termin) dan jurnal Dr Piutang Usaha; idempoten', function (): void {
        $k = SiapkanTempo($this);
        $item = ItemTempo($k);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $piutang = Piutang::query()->sole();

        expect($jual->PerluTinjauan)->toBeFalse()
            ->and($piutang->IdPelanggan)->toBe($k['Toko']->Id)
            ->and((string) $piutang->Jumlah)->toBe('77000.00')
            ->and($piutang->Status)->toBe(StatusPiutang::BelumLunas)
            ->and($piutang->Nomor)->toBe($jual->Nomor)
            ->and($piutang->JatuhTempo->toDateString())->toBe($jual->TanggalBisnis->addDays(14)->toDateString())
            ->and(SaldoPiutangUsahaJurnal($jual))->toBe('77000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Piutang::query()->count())->toBe(1);
    });

    it('tempo tanpa pelanggan ditolak. Dua pembayaran tempo ditolak', function (): void {
        $k = SiapkanTempo($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemTempo($k, timpa: ['UuidPelanggan' => null]),
            ItemTempo($k, ['Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '40000.00'], ['Metode' => $k['Tempo'], 'Jumlah' => '37000.00']]]),
        ]))->toBe([['Ditolak', 'TempoTanpaPelanggan'], ['Ditolak', 'PembayaranTidakValid']]);
    });

    it('BR-12.1: melebihi limit tanpa penyetuju = diterima + tinjauan, dengan PIN supervisor tercatat penyetujunya', function (): void {
        $k = SiapkanTempo($this, '50000');
        $tanpa = ItemTempo($k);
        $dengan = ItemTempo($k, timpa: ['UuidPenyetujuTempo' => $k['Supervisor']->Uuid]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $tanpa['Uuid'])->sole();
        expect($jual->PerluTinjauan)->toBeTrue()
            ->and($jual->AlasanTinjauan)->toContain('TempoBermasalah: piutang Rp 77.000 melebihi limit Rp 50.000 (tanpa penyetuju)')
            ->and($jual->IdPenyetujuTempo)->toBeNull();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$dengan]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $disetujui = Penjualan::query()->where('Uuid', $dengan['Uuid'])->sole();
        expect($disetujui->PerluTinjauan)->toBeFalse()
            ->and($disetujui->IdPenyetujuTempo)->toBe($k['Supervisor']->Id)
            ->and(Piutang::query()->count())->toBe(2);
    });

    it('BR-12.1: pelanggan tanpa limit atau punya piutang lewat jatuh tempo butuh penyetuju', function (): void {
        $k = SiapkanTempo($this);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTempo($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '38500.00']]])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $lama = Piutang::query()->sole();
        // Jatuh tempo 3 hari sebelum tanggal bisnis penjualan berikutnya (tanggal bisnis outlet, bukan jam server).
        $lama->forceFill(['JatuhTempo' => $lama->TanggalBisnis->subDays(3)->toDateString()])->save();

        $item = ItemTempo($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->AlasanTinjauan)->toContain('ada piutang lewat jatuh tempo 3 hari');

        $tanpaLimit = Pelanggan::query()->create(['Nama' => 'Warung Tanpa Limit', 'NoHp' => '6281355550002']);
        $lagi = ItemTempo($k, timpa: ['UuidPelanggan' => $tanpaLimit->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lagi]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lagi['Uuid'])->sole()->AlasanTinjauan)->toContain('pelanggan belum punya limit kredit');
    });
});

describe('F-12 void & retur penjualan tempo', function (): void {
    it('void membatalkan piutang tanpa refund tempo', function (): void {
        $k = SiapkanTempo($this);
        $item = ItemTempo($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $piutang = Piutang::query()->sole();
        expect($piutang->Status)->toBe(StatusPiutang::Dibatalkan)
            ->and($piutang->AmbilSisa()->BernilaiNol())->toBeTrue();
    });

    it('retur penjualan tempo wajib potong piutang lebih dulu', function (): void {
        $k = SiapkanTempo($this);
        $item = ItemTempo($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();
        $satu = [['Detail' => $detail, 'Jumlah' => '1']];
        $cari = fn (): mixed => $this->withToken($k['Token'])->getJson('/api/pos/v1/penjualan/cari?nomor='.urlencode($jual->Nomor))->assertOk()->json('Penjualan.SisaPiutang');
        expect($cari())->toBe('77000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, $satu)]))->toBe([['Ditolak', 'RefundTidakSesuai']]);

        $retur = BantuanPenjualan::ItemRetur($k, $jual, $satu, ['Refund' => [['Metode' => $k['Tempo'], 'Jumlah' => null]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $piutang = Piutang::query()->sole();
        expect((string) $piutang->JumlahDikurangi)->toBe('38500.00')
            ->and($piutang->AmbilSisa()->KeString())->toBe('38500.00')
            ->and($piutang->Status)->toBe(StatusPiutang::BelumLunas)
            ->and(ReturPenjualan::query()->sole()->MetodeRefund->value)->toBe('Piutang');
        expect($cari())->toBe('38500.00');
    });
});

describe('F-12 data kredit untuk POS', function (): void {
    it('pencarian pelanggan POS membawa limit, sisa piutang, dan hari lewat jatuh tempo', function (): void {
        $k = SiapkanTempo($this);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTempo($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '38500.00']]])]))->toBe([['Diterima', null]]);

        $hasil = $this->withToken($k['Token'])->getJson('/api/pos/v1/pelanggan?kata=makmur')->assertOk()->json('Pelanggan.0');
        expect($hasil['LimitKredit'])->toBe('500000.00')
            ->and($hasil['SisaPiutang'])->toBe('38500.00')
            ->and($hasil['HariLewatJatuhTempo'])->toBe(0);
    });
});
