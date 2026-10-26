<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use App\Domain\Penjualan\Enum\MetodeRefund;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanShift;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Model\ReturPenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualanPembayaran;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Saldo (debit − kredit) jurnal pada akun peran tertentu (dimensi outlet). */
function SaldoPeranJurnalRetur(int $idJurnal, PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $saldo = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
    }

    return $saldo->KeString();
}

function SaldoStokRetur(int $idProduk, int $idGudang): ?string
{
    return SaldoStok::query()->where('IdProduk', $idProduk)->where('IdGudang', $idGudang)->value('JumlahTersedia');
}

describe('F-09 retur penjualan lewat sinkron (ReturPenjualan.Buat)', function (): void {
    it('retur parsial berulang sampai habis (PPN 12% DPP 11/12, diskon pesanan): nilai proporsional ke sen, retur terakhir mengambil sisa (Σ = TotalBaris), stok layak jual ke Toko & rusak ke lokasi Rusak, jurnal J-09.2 tunai vs transfer, status DireturSebagian → Diretur. Invariant terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rusak = BantuanPersediaan::BuatGudang($k['Outlet'], 'Barang Rusak & Retur', JenisGudang::Rusak);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '10', hpp: '30000.123');
        $p = BantuanPenjualan::Jual($this, $k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12]],
            'DiskonManualPesanan' => ['Jumlah' => '1000.00'],
            'Penyetuju' => $k['Supervisor'],
            'Baris' => [['Produk' => $minyak, 'Jumlah' => '3', 'Harga' => '33333.00']],
        ]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();
        expect(SaldoStokRetur($minyak->Id, $k['Gudang']->Id))->toBe('7.0000');

        // Retur 1: 1 pcs layak jual, refund tunai.
        $satu = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1', 'Kondisi' => 'LayakJual']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $r1 = ReturPenjualan::query()->where('Uuid', $satu['Uuid'])->sole();
        $b1 = ReturPenjualanDetail::query()->where('IdReturPenjualan', $r1->Id)->sole();
        expect($r1->TotalRefund)->toBe($satu['Data']['Ringkasan']['TotalRefund'])
            ->and($r1->MetodeRefund)->toBe(MetodeRefund::Tunai)
            ->and($r1->RefundTunai)->toBe($r1->TotalRefund)
            ->and($r1->IdShift)->not->toBeNull()
            ->and($b1->Kondisi)->toBe(KondisiBarangRetur::LayakJual)
            ->and($b1->IdGudang)->toBe($k['Gudang']->Id)
            ->and($b1->HppSatuan)->toBe($d->HppSatuan)
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::DireturSebagian)
            ->and(SaldoStokRetur($minyak->Id, $k['Gudang']->Id))->toBe('8.0000');

        $j1 = (int) $r1->IdJurnal;
        $o = $k['Outlet']->Id;
        $pajak1 = Uang::Dari($b1->Pajak);
        expect(Jurnal::query()->findOrFail($j1)->JenisSumber)->toBe(JenisSumberJurnal::ReturPenjualan)
            ->and(SaldoPeranJurnalRetur($j1, PeranAkun::KasOutlet, $o))->toBe('-'.$r1->TotalRefund)
            ->and(SaldoPeranJurnalRetur($j1, PeranAkun::PpnKeluaran, $o))->toBe($pajak1->KeString())
            ->and(SaldoPeranJurnalRetur($j1, PeranAkun::ReturPenjualan, $o))->toBe(Uang::Dari($b1->NilaiBaris)->Kurangi($pajak1)->KeString())
            ->and(SaldoPeranJurnalRetur($j1, PeranAkun::PersediaanBarangDagang, $o))->toBe($b1->TotalHpp)
            ->and(SaldoPeranJurnalRetur($j1, PeranAkun::Hpp, $o))->toBe('-'.$b1->TotalHpp);

        // Retur 2: 1 pcs rusak ke lokasi Rusak, refund transfer manual (BR-09.2).
        $dua = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1', 'Kondisi' => 'Rusak']], ['Refund' => [['Metode' => $k['Transfer'], 'Jumlah' => null]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$dua]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $r2 = ReturPenjualan::query()->where('Uuid', $dua['Uuid'])->sole();
        $b2 = ReturPenjualanDetail::query()->where('IdReturPenjualan', $r2->Id)->sole();
        expect($r2->MetodeRefund)->toBe(MetodeRefund::Transfer)
            ->and($r2->RefundTunai)->toBe('0.00')
            ->and($r2->PerluTinjauan)->toBeFalse()
            ->and($b2->IdGudang)->toBe($rusak->Id)
            ->and(SaldoStokRetur($minyak->Id, $rusak->Id))->toBe('1.0000')
            ->and(SaldoPeranJurnalRetur((int) $r2->IdJurnal, PeranAkun::Bank, $o))->toBe('-'.$r2->TotalRefund)
            ->and(SaldoPeranJurnalRetur((int) $r2->IdJurnal, PeranAkun::KasOutlet, $o))->toBe('0.00')
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::DireturSebagian);

        // Retur 3: sisa 1 pcs, refund campuran tunai + transfer; mengambil sisa nilai.
        $tiga = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']], ['Refund' => [['Metode' => $k['Tunai'], 'Jumlah' => '10000.00'], ['Metode' => $k['Transfer'], 'Jumlah' => null]]]);
        $totalTiga = Uang::Dari($tiga['Data']['Ringkasan']['TotalRefund']);
        $tiga['Data']['Refund'][1]['Jumlah'] = $totalTiga->Kurangi(Uang::Dari('10000.00'))->KeString();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tiga]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $r3 = ReturPenjualan::query()->where('Uuid', $tiga['Uuid'])->sole();
        expect($r3->MetodeRefund)->toBe(MetodeRefund::Campuran)
            ->and($r3->RefundTunai)->toBe('10000.00')
            ->and(ReturPenjualanPembayaran::query()->where('IdReturPenjualan', $r3->Id)->count())->toBe(2);

        $semua = ReturPenjualanDetail::query()->where('IdPenjualanDetail', $d->Id)->get();
        $jumlah = fn (string $kolom): string => $semua->reduce(fn (Uang $t, ReturPenjualanDetail $b): Uang => $t->Tambah(Uang::Dari($b->{$kolom})), Uang::Nol())->KeString();
        expect($jumlah('NilaiBaris'))->toBe($d->TotalBaris)
            ->and($jumlah('Pajak'))->toBe($d->JumlahPajak)
            ->and($jumlah('TotalHpp'))->toBe($d->TotalHpp)
            ->and($semua->reduce(fn (Kuantitas $t, ReturPenjualanDetail $b): Kuantitas => $t->Tambah(Kuantitas::Dari($b->Jumlah)), Kuantitas::Nol())->KeString())->toBe($d->Jumlah)
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Diretur)
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Penjualan')->where('IdDokumen', $p->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Lunas', 'DireturSebagian', 'Diretur'])
            ->and(SaldoStokRetur($minyak->Id, $k['Gudang']->Id))->toBe('9.0000')
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::ReturPenjualan->value)->count())->toBe(3)
            ->and(LogAudit::query()->where('Peristiwa', 'penjualan.retur')->count())->toBe(3)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        // Sudah diretur penuh: retur berikutnya ditolak.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']])]))->toBe([['Ditolak', 'ReturTidakDiizinkan']]);
    });

    it('barang rusak tanpa lokasi Rusak di outlet: kembali ke Toko dan retur ditandai perlu ditinjau', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $item = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $p->Detail()->firstOrFail(), 'Jumlah' => '2', 'Kondisi' => 'Rusak']]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $r = ReturPenjualan::query()->sole();
        expect($r->PerluTinjauan)->toBeTrue()
            ->and($r->AlasanTinjauan)->toContain('LokasiRusakTidakAda')
            ->and($r->TotalRefund)->toBe('77000.00')
            ->and(SaldoStokRetur($minyak->Id, $k['Gudang']->Id))->toBe('10.0000')
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Diretur)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('idempoten: Uuid sama = Duplikat tanpa dokumen/stok/jurnal ganda; Uuid sama dengan nomor lain = UuidSudahDipakai. Nomor dipakai = NomorSudahDipakai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '5', 'Harga' => '38500.00']]]);
        $d = $p->Detail()->firstOrFail();
        $item = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

        $lain = $item;
        $lain['Data']['Nomor'] = BantuanPenjualan::NomorRetur($k, 9001);
        $nomorSama = BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']], timpa: ['Nomor' => $item['Data']['Nomor']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lain, $nomorSama]))->toBe([['Ditolak', 'UuidSudahDipakai'], ['Ditolak', 'NomorSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ReturPenjualan::query()->count())->toBe(1)
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::ReturPenjualan->value)->count())->toBe(1)
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::ReturPenjualan->value)->count())->toBe(1)
            ->and(SaldoStokRetur($minyak->Id, $k['Gudang']->Id))->toBe('6.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('penolakan: HitunganTidakCocok, JumlahReturMelebihi, RefundTidakSesuai, MetodeBayarBelumDidukung, MetodeBayarTidakDikenal, NomorTidakValid, BarisTidakDikenal, PenyetujuTidakBerwenang, TanpaIzin, ShiftTidakDitemukan, PenjualanTidakDitemukan, alasan < 5, ReturTidakDiizinkan (void)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]];
        $p = BantuanPenjualan::Jual($this, $k, $baris);
        $lain = BantuanPenjualan::Jual($this, $k, $baris);
        $divoid = BantuanPenjualan::Jual($this, $k, $baris);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $divoid)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $d = $p->Detail()->firstOrFail();
        $dLain = $lain->Detail()->firstOrFail();
        $kasirLain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $satu = [['Detail' => $d, 'Jumlah' => '1']];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $p, $satu, timpa: ['Ringkasan' => ['TotalRefund' => '38000.00'], 'Refund' => [['Uuid' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => $k['Tunai']->Uuid, 'Jumlah' => '38000.00']]]),
            BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '3']]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['Refund' => [['Metode' => $k['Tunai'], 'Jumlah' => '38000.00']]]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['Refund' => [['Metode' => $k['Qris'], 'Jumlah' => null]]]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, timpa: ['Refund' => [['Uuid' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => BantuanKasir::Uuid(), 'Jumlah' => '38500.00']]]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, timpa: ['Nomor' => 'RJ/UTAMA/260924/K01-0001']),
            BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $dLain, 'Jumlah' => '1']]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['Penyetuju' => $kasirLain]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['Kasir' => $gudang]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['UuidShift' => BantuanKasir::Uuid()]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, timpa: ['UuidPenjualanAsal' => BantuanKasir::Uuid()]),
            BantuanPenjualan::ItemRetur($k, $p, $satu, ['Alasan' => 'Rsk']),
            BantuanPenjualan::ItemRetur($k, $divoid, [['Detail' => $divoid->Detail()->firstOrFail(), 'Jumlah' => '1']]),
        ]))->toBe([
            ['Ditolak', 'HitunganTidakCocok'],
            ['Ditolak', 'JumlahReturMelebihi'],
            ['Ditolak', 'RefundTidakSesuai'],
            ['Ditolak', 'MetodeBayarBelumDidukung'],
            ['Ditolak', 'MetodeBayarTidakDikenal'],
            ['Ditolak', 'NomorTidakValid'],
            ['Ditolak', 'BarisTidakDikenal'],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
            ['Ditolak', 'TanpaIzin'],
            ['Ditolak', 'ShiftTidakDitemukan'],
            ['Ditolak', 'PenjualanTidakDitemukan'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'ReturTidakDiizinkan'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ReturPenjualan::query()->count())->toBe(0)
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Lunas)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('BatasHariRetur (bawaan 7 hari sejak tanggal bisnis penjualan): hari ke-7 diterima, hari ke-8 ReturTidakDiizinkan. Pengaturan 0 = hanya hari yang sama', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $tujuh = BantuanPenjualan::Jual($this, $k, $baris);
        $delapan = BantuanPenjualan::Jual($this, $k, $baris);
        $hariIni = BantuanPenjualan::Jual($this, $k, $baris);
        // Simulasi penjualan lama (tanggal bisnis mundur); dokumen tidak bisa diubah lewat model.
        DB::table('Penjualan')->where('Id', $tujuh->Id)->update(['TanggalBisnis' => $tujuh->TanggalBisnis->copy()->subDays(7)->toDateString(), 'DibuatOfflinePada' => now()->subDays(7)]);
        DB::table('Penjualan')->where('Id', $delapan->Id)->update(['TanggalBisnis' => $delapan->TanggalBisnis->copy()->subDays(8)->toDateString(), 'DibuatOfflinePada' => now()->subDays(8)]);
        $tujuh->refresh();
        $delapan->refresh();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $tujuh, [['Detail' => $tujuh->Detail()->firstOrFail()]]),
            BantuanPenjualan::ItemRetur($k, $delapan, [['Detail' => $delapan->Detail()->firstOrFail()]]),
        ]))->toBe([['Diterima', null], ['Ditolak', 'ReturTidakDiizinkan']]);

        $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
        $tenant->Pengaturan = array_replace($tenant->Pengaturan ?? [], ['BatasHariRetur' => 0]);
        $tenant->save();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $hariIni, [['Detail' => $hariIni->Detail()->firstOrFail()]]),
        ]))->toBe([['Diterima', null]]);
    });

    it('isolasi tenant: penjualan tenant lain tidak bisa diretur (PenjualanTidakDitemukan)', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        $minyak = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $a, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $d = $p->Detail()->firstOrFail();
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [BantuanPenjualan::ItemRetur($b, $p, [['Detail' => $d]])]))->toBe([['Ditolak', 'PenjualanTidakDitemukan']]);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(ReturPenjualan::query()->count())->toBe(0)
            ->and($p->refresh()->Status)->toBe(StatusPenjualan::Lunas);
    });
    it('F-11 ringkasan shift: refund tunai = tunai void + retur tunai dari laci shift ini. Jumlah & nominal void/retur terisi', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $divoid = BantuanPenjualan::Jual($this, $k, [
            'Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '20000.00'], ['Metode' => $k['Tunai'], 'Jumlah' => '60000.00']],
        ]);
        $asal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '3', 'Harga' => '38500.00']]]);
        $d = $asal->Detail()->firstOrFail();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $divoid),
            BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $d, 'Jumlah' => '1']]),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $asal, [['Detail' => $d, 'Jumlah' => '1']], ['Refund' => [['Metode' => $k['Transfer'], 'Jumlah' => null]]]),
        ]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $ringkasan = app(RingkasanPenjualanShift::class)->Ambil($asal->IdShift);
        // Void: tunai bersih 60.000 − kembalian 3.000 = 57.000; retur tunai 38.500; retur transfer tidak dari laci.
        expect($ringkasan->refundTunai->KeString())->toBe('95500.00')
            ->and($ringkasan->jumlahVoid)->toBe(1)
            ->and($ringkasan->nominalVoid->KeString())->toBe('77000.00')
            ->and($ringkasan->jumlahRetur)->toBe(2)
            ->and($ringkasan->nominalRetur->KeString())->toBe('77000.00')
            ->and($ringkasan->jumlahTransaksi)->toBe(1)
            ->and($ringkasan->totalAkhir->KeString())->toBe('115500.00');
    });
});
