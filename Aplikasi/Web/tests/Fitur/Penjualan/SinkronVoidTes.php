<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\VoidPenjualan;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SaldoStokVoid(int $idProduk, int $idGudang): ?string
{
    return SaldoStok::query()->where('IdProduk', $idProduk)->where('IdGudang', $idGudang)->value('JumlahTersedia');
}

describe('F-09 void penjualan lewat sinkron (Penjualan.Void)', function (): void {
    it('void penjualan PPN split QRIS + tunai: VoidPenjualan (refund tunai bersih & non-tunai), status Void, mutasi pembalik & jurnal pembalik J-09.1, riwayat & audit. Invariant terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Belanja Dalam Kota', 'Jenis' => JenisProduk::Jasa], '10000.00');
        $p = BantuanPenjualan::Jual($this, $k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12]],
            'Baris' => [
                ['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00'],
                ['Produk' => $jasa, 'Jumlah' => '1', 'Harga' => '10000.00'],
            ],
            'Pembayaran' => [
                ['Metode' => $k['Qris'], 'Jumlah' => '50000.00', 'Referensi' => 'QR-88123'],
                ['Metode' => $k['Tunai'], 'Jumlah' => '50000.00'],
            ],
        ]);
        expect(SaldoStokVoid($minyak->Id, $k['Gudang']->Id))->toBe('8.0000');

        $item = BantuanPenjualan::ItemVoid($k, $p);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p->refresh();
        $void = VoidPenjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($p->Status)->toBe(StatusPenjualan::Void)
            ->and($void->IdPenjualan)->toBe($p->Id)
            ->and($void->IdShift)->toBe($p->IdShift)
            ->and($void->DivoidOleh)->toBe($k['Kasir']->Id)
            ->and($void->DisetujuiOleh)->toBe($k['Supervisor']->Id)
            ->and($void->Nominal)->toBe('96570.00')
            ->and($void->RefundTunai)->toBe('46570.00')
            ->and($void->RefundNonTunai)->toBe('50000.00')
            ->and($void->IdJurnal)->not->toBeNull()
            ->and(SaldoStokVoid($minyak->Id, $k['Gudang']->Id))->toBe('10.0000');

        $pembalik = MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::VoidPenjualan->value)->where('IdReferensi', $p->Id)->sole();
        $asal = MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdReferensi', $p->Id)->sole();
        expect($pembalik->Jumlah)->toBe('2.0000')
            ->and($pembalik->IdMutasiAsal)->toBe($asal->Id)
            ->and($pembalik->TotalHpp)->toBe('60000.00');

        $jurnal = Jurnal::query()->findOrFail($void->IdJurnal);
        $asalJurnal = Jurnal::query()->findOrFail($p->IdJurnal);
        expect($jurnal->JenisSumber)->toBe(JenisSumberJurnal::Penjualan)
            ->and($jurnal->IdSumber)->toBe($p->Id)
            ->and($jurnal->IdJurnalDibalik)->toBe($asalJurnal->Id)
            ->and($jurnal->TotalDebit)->toBe($asalJurnal->TotalDebit);

        // Setiap akun di jurnal pembalik = kebalikan jurnal asal (saldo gabungan nol).
        $saldo = JurnalDetail::query()->whereIn('IdJurnal', [$jurnal->Id, $asalJurnal->Id])
            ->selectRaw('IdAkun, SUM(Debit) - SUM(Kredit) AS Saldo')->groupBy('IdAkun')->pluck('Saldo')->all();
        expect(array_values(array_unique(array_map(fn ($s): string => (string) $s, $saldo))))->toBe(['0.00']);

        expect(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Penjualan')->where('IdDokumen', $p->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Lunas', 'Void'])
            ->and(LogAudit::query()->where('Peristiwa', 'penjualan.void')->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('idempoten: Uuid sama = Duplikat tanpa pembalik ganda. Void kedua Uuid lain = SudahDivoid; Uuid void dipakai untuk penjualan lain = UuidSudahDipakai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $satu = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $dua = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $void = BantuanPenjualan::ItemVoid($k, $satu);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$void, BantuanPenjualan::ItemVoid($k, $satu)]))->toBe([['Duplikat', null], ['Ditolak', 'SudahDivoid']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $dua, uuid: $void['Uuid'])]))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(VoidPenjualan::query()->count())->toBe(1)
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::VoidPenjualan->value)->count())->toBe(1)
            ->and(Jurnal::query()->whereNotNull('IdJurnalDibalik')->count())->toBe(1)
            ->and($dua->refresh()->Status)->toBe(StatusPenjualan::Lunas)
            ->and(SaldoStokVoid($minyak->Id, $k['Gudang']->Id))->toBe('9.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('penolakan: PenjualanTidakDitemukan, VoidTidakDiizinkan (perangkat lain, shift sudah ditutup, sudah diretur), PenyetujuTidakBerwenang, KasirTidakDitemukan, TanpaIzin, alasan < 5 karakter, WaktuTidakValid', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $p = BantuanPenjualan::Jual($this, $k, $baris);
        $diretur = BantuanPenjualan::Jual($this, $k, $baris);
        $detailDiretur = $diretur->Detail()->firstOrFail();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $diretur, [['Detail' => $detailDiretur]])]))->toBe([['Diterima', null]]);

        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $luarOutlet = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor, semuaOutlet: false);
        $kasirLain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

        // Perangkat kedua di outlet yang sama.
        $lain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $p, timpa: ['UuidPenjualan' => BantuanKasir::Uuid()]),
            BantuanPenjualan::ItemVoid($k, $diretur),
            BantuanPenjualan::ItemVoid($k, $p, ['Penyetuju' => $kasirLain]),
            BantuanPenjualan::ItemVoid($k, $p, ['Penyetuju' => $luarOutlet]),
            BantuanPenjualan::ItemVoid($k, $p, ['Kasir' => $gudang]),
            BantuanPenjualan::ItemVoid($k, $p, ['Alasan' => 'Oops']),
            BantuanPenjualan::ItemVoid($k, $p, ['DivoidPada' => now()->addHour()->toImmutable()]),
            BantuanPenjualan::ItemVoid($k, $p, ['DivoidPada' => now()->subDay()->toImmutable()]),
        ]))->toBe([
            ['Ditolak', 'PenjualanTidakDitemukan'],
            ['Ditolak', 'VoidTidakDiizinkan'],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
            ['Ditolak', 'TanpaIzin'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
        ]);

        expect(BantuanKasir::KirimRingkas($this, $lain['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Ditolak', 'VoidTidakDiizinkan']]);

        // Shift penjualan ditutup (F-11) sebelum waktu void.
        DB::table('Shift')->where('Uuid', $k['UuidShift'])->update(['Status' => 'Tertutup', 'DitutupPada' => now()->subMinutes(2)]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Ditolak', 'VoidTidakDiizinkan']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(VoidPenjualan::query()->count())->toBe(0)
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Lunas)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('kasir yang sendiri ber-izin penjualan.void menyetujui dirinya. Penjualan Rp 0 di-void tanpa jurnal', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $gratis = BantuanKatalog::BuatProduk(['Nama' => 'Kantong Belanja Kertas Gratis', 'Jenis' => JenisProduk::NonStok], '0.00');
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $gratis, 'Jumlah' => '1', 'Harga' => '0.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $p, ['Kasir' => $k['Supervisor'], 'Penyetuju' => $k['Supervisor']]),
        ]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(VoidPenjualan::query()->sole()->IdJurnal)->toBeNull()
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Void);
    });

    it('isolasi tenant: penjualan tenant lain tidak bisa di-void (PenjualanTidakDitemukan)', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        $minyak = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $a, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [BantuanPenjualan::ItemVoid($b, $p)]))->toBe([['Ditolak', 'PenjualanTidakDitemukan']]);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Penjualan::query()->whereKey($p->Id)->sole()->Status)->toBe(StatusPenjualan::Lunas);
    });
});
