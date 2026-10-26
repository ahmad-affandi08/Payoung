<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\MetodeRefund;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Model\VoidPenjualan;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-11 tukar barang (F-09, §9.3–§9.4): barang diretur, nilainya membayar barang pengganti. Retur me-refund lewat metode
 * sistem `Tukar` (Cr Kliring Tukar Barang), penjualan pengganti membayar dengan metode yang sama + `UuidReturTukar`
 * (Dr Kliring). Selisih dibayar pelanggan dengan metode biasa. Nilai tukar melebihi retur = diterima + tinjauan.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/** Saldo (debit − kredit) akun Kliring Tukar Barang seluruh jurnal tenant. */
function SaldoKliringTukar(): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::KliringTukarBarang, null);
    $saldo = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
    }

    return $saldo->KeString();
}

describe('K-11 tukar barang', function (): void {
    it('data awal menyertakan metode sistem Tukar barang (sekali per tenant)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $ambil = fn () => collect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('MetodePembayaran'))
            ->where('Jenis', 'Tukar')->values();
        expect($ambil())->toHaveCount(1)->and($ambil()[0]['Nama'])->toBe('Tukar barang');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(MetodePembayaran::query()->where('Jenis', 'Tukar')->count())->toBe(1);
    });

    it('retur → refund Tukar (Cr kliring), penjualan pengganti lebih mahal dibayar Tukar + tunai (Dr kliring): kliring nol, retur tertaut, invarian jurnal', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tukar = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tukar->value)->sole();
        $kaos = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Kaos Polos Katun Combed 30s Ukuran M', '5', '45000', '85000.00');
        $kaosL = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Kaos Polos Katun Combed 30s Ukuran XL', '5', '50000', '95000.00');

        $asal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $kaos, 'Jumlah' => '1', 'Harga' => '85000.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $asal->Id)->sole();
        $retur = BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $d, 'Jumlah' => '1']], ['Refund' => [['Metode' => $tukar, 'Jumlah' => null]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $r = ReturPenjualan::query()->where('Uuid', $retur['Uuid'])->sole();
        expect($r->MetodeRefund)->toBe(MetodeRefund::Tukar)
            ->and(Uang::Dari($r->RefundTunai)->BernilaiNol())->toBeTrue()
            ->and(SaldoKliringTukar())->toBe('-85000.00');

        $pengganti = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $kaosL, 'Jumlah' => '1', 'Harga' => '95000.00']],
            'Pembayaran' => [['Metode' => $tukar, 'Jumlah' => '85000.00'], ['Metode' => $k['Tunai'], 'Jumlah' => null]],
        ], ['UuidReturTukar' => $retur['Uuid']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$pengganti]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $pengganti['Uuid'])->sole();
        expect($p->IdReturTukar)->toBe($r->Id)
            ->and($p->AlasanTinjauan ?? null)->toBeNull()
            ->and(SaldoKliringTukar())->toBe('0.00');
        expect(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('void penjualan pengganti: nilai tukar bukan refund non-tunai (bisa dipakai lagi), hanya tunai yang keluar dari laci', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tukar = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tukar->value)->sole();
        $kaos = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Kaos Polos Katun Combed 30s Ukuran M', '5', '45000', '85000.00');
        $kaosL = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Kaos Polos Katun Combed 30s Ukuran XL', '5', '50000', '95000.00');

        $asal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $kaos, 'Jumlah' => '1', 'Harga' => '85000.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $asal->Id)->sole();
        $retur = BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $d, 'Jumlah' => '1']], ['Refund' => [['Metode' => $tukar, 'Jumlah' => null]]]);
        $pengganti = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $kaosL, 'Jumlah' => '1', 'Harga' => '95000.00']],
            'Pembayaran' => [['Metode' => $tukar, 'Jumlah' => '85000.00'], ['Metode' => $k['Tunai'], 'Jumlah' => null]],
        ], ['UuidReturTukar' => $retur['Uuid']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur, $pengganti]))->toBe([['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $pengganti['Uuid'])->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $void = VoidPenjualan::query()->where('IdPenjualan', $p->Id)->sole();
        expect($void->RefundNonTunai)->toBe('0.00')
            ->and($void->RefundTunai)->toBe('10000.00')
            ->and(SaldoKliringTukar())->toBe('-85000.00');
        expect(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('Tukar tanpa UuidReturTukar ditolak. Nilai tukar melebihi retur atau retur belum ada = diterima + tinjauan TukarBermasalah', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tukar = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tukar->value)->sole();
        $sepatu = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Sandal Jepit Karet Ukuran 42', '10', '15000', '30000.00');

        $asal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $sepatu, 'Jumlah' => '1', 'Harga' => '30000.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $asal->Id)->sole();
        $retur = BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $d, 'Jumlah' => '1']], ['Refund' => [['Metode' => $tukar, 'Jumlah' => null]]]);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]);

        $tanpaRetur = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $sepatu, 'Jumlah' => '1', 'Harga' => '30000.00']],
            'Pembayaran' => [['Metode' => $tukar, 'Jumlah' => '30000.00']],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaRetur]))->toBe([['Ditolak', 'TukarTanpaRetur']]);

        $lebih = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $sepatu, 'Jumlah' => '2', 'Harga' => '30000.00']],
            'Pembayaran' => [['Metode' => $tukar, 'Jumlah' => '60000.00']],
        ], ['UuidReturTukar' => $retur['Uuid']]);
        $belumAda = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $sepatu, 'Jumlah' => '1', 'Harga' => '30000.00']],
            'Pembayaran' => [['Metode' => $tukar, 'Jumlah' => '30000.00']],
        ], ['UuidReturTukar' => BantuanKasir::Uuid()]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lebih, $belumAda]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->AlasanTinjauan)->toContain('TukarBermasalah: nilai tukar Rp 60.000 melebihi nilai retur Rp 30.000')
            ->and(Penjualan::query()->where('Uuid', $belumAda['Uuid'])->sole()->AlasanTinjauan)->toContain('TukarBermasalah: retur tukar belum diterima server');
        expect(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});
