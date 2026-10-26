<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\MutasiKas;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Shift terbuka (kas awal Rp 500.000) dengan: penjualan tunai Rp 77.000 (bayar Rp 100.000, kembali Rp 23.000),
 * penjualan QRIS Rp 38.500, kas masuk Rp 20.000, kas keluar Rp 45.000, setoran Rp 100.000.
 * Kas seharusnya = 500.000 + 77.000 + 20.000 − 45.000 − 100.000 = Rp 452.000.
 *
 * @return array<string, mixed>
 */
function SiapkanShiftTutupUji(object $tes, string $namaUsaha = 'Toko Kelontong Berkah Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $hasil = BantuanKasir::KirimRingkas($tes, $k['Token'], [
        BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '100000.00']]]),
        BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '38500.00']]]),
        BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Masuk', '20000.00', $k['KategoriMasuk']),
        BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '45000.00', $k['KategoriKeluar']),
        BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Setoran', '100000.00', null),
    ]);
    expect($hasil)->toBe(array_fill(0, 5, ['Diterima', null]));
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $k + ['Produk' => $produk];
}

/**
 * Item outbox `Shift.Tutup` sesuai kontrak PRD. `Ringkasan.Selisih` = kas aktual − kas seharusnya perangkat.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemTutupShiftUji(array $k, string $kasAktual, string $kasSeharusnya = '452000.00', array $timpa = [], ?Pengguna $penutup = null): array
{
    return [
        'Jenis' => 'Shift.Tutup',
        'Uuid' => BantuanKasir::Uuid(),
        'Data' => array_replace([
            'UuidShift' => $k['UuidShift'],
            'UuidPengguna' => ($penutup ?? $k['Kasir'])->Uuid,
            'DitutupPada' => now()->subMinute()->utc()->toIso8601ZuluString(),
            'KasAktual' => $kasAktual,
            'PecahanKasAkhir' => null,
            'NonTunai' => [],
            'Alasan' => null,
            'UuidPenyetuju' => null,
            'Ringkasan' => [
                'KasSeharusnya' => $kasSeharusnya,
                'Selisih' => Uang::Dari($kasAktual)->Kurangi(Uang::Dari($kasSeharusnya))->KeString(),
            ],
        ], $timpa),
    ];
}

/**
 * Baris jurnal selisih shift sebagai `[IdAkun, Debit, Kredit]`.
 *
 * @return list<array{0: int, 1: string, 2: string}>
 */
function AmbilBarisJurnalSelisihUji(Shift $shift): array
{
    $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TutupShift->value)->where('IdSumber', $shift->Id)->sole();

    return array_values(JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get()
        ->map(fn (JurnalDetail $d): array => [$d->IdAkun, (string) $d->Debit, (string) $d->Kredit])->all());
}

function AmbilShiftUji(string $uuid): Shift
{
    return Shift::query()->where('Uuid', $uuid)->sole();
}

describe('F-11 tutup shift lewat sinkron', function (): void {
    it('diterima: kas seharusnya dihitung server, kolom tutup & non-tunai per metode tersimpan, riwayat & audit. Kirim ulang = Duplikat. Data lain = ShiftSudahDitutup', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $pecahan = [['Nominal' => '100000.00', 'Jumlah' => 4], ['Nominal' => '50000.00', 'Jumlah' => 1], ['Nominal' => '2000.00', 'Jumlah' => 1]];
        $item = ItemTutupShiftUji($k, '452000.00', timpa: [
            'PecahanKasAkhir' => $pecahan,
            'NonTunai' => [['UuidMetodePembayaran' => $k['Qris']->Uuid, 'Jumlah' => '38500.00']],
        ]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = AmbilShiftUji($k['UuidShift']);

        expect($shift->Status)->toBe(StatusShift::Tertutup)
            ->and($shift->DitutupOleh)->toBe($k['Kasir']->Id)
            ->and($shift->KasSeharusnya)->toBe('452000.00')
            ->and($shift->KasAktual)->toBe('452000.00')
            ->and($shift->Selisih)->toBe('0.00')
            ->and($shift->PecahanKasAkhir)->toEqual($pecahan)
            ->and($shift->RingkasanNonTunai)->toEqual([[
                'UuidMetodePembayaran' => $k['Qris']->Uuid,
                'Jenis' => 'QrisStatis',
                'Nama' => 'QRIS Toko Berkah',
                'JumlahSistem' => '38500.00',
                'JumlahDilaporkan' => '38500.00',
            ]])
            ->and($shift->PerluTinjauan)->toBeFalse()
            ->and($shift->AlasanSelisih)->toBeNull()
            ->and($shift->IdPenyetujuSelisih)->toBeNull()
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TutupShift->value)->count())->toBe(0)
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Shift')->where('IdDokumen', $shift->Id)->where('StatusKe', 'Tertutup')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'shift.tutup')->count())->toBe(1);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '450000.00')]))->toBe([['Ditolak', 'ShiftSudahDitutup']]);
    });

    it('kiriman ulang Shift.Buka & Shift.Tutup setelah akses kasir dicabut tetap Duplikat (outbox tidak macet). Isi berbeda tetap ditolak', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $item = ItemTutupShiftUji($k, '452000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = AmbilShiftUji($k['UuidShift']);
        $buka = BantuanKasir::ItemBukaShift($k['Kasir'], (string) $shift->KasAwal, ['DibukaPada' => $shift->DibukaPada->utc()->toIso8601ZuluString()], $k['UuidShift']);

        TenantPengguna::query()->where('IdTenant', $k['Tenant']->Id)->where('IdPengguna', $k['Kasir']->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$buka, $item]))->toBe([['Duplikat', null], ['Duplikat', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '450000.00')]))->toBe([['Ditolak', 'ShiftSudahDitutup']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'], '1.00', uuid: $k['UuidShift'])]))->toBe([['Ditolak', 'UuidSudahDipakai']]);
    });

    it('izin & shift bersama (BR-06.2): tanpa penjualan.buat = TanpaIzin. Kasir lain di shift bukan bersama = BukanShiftSendiri. Supervisor boleh menutup', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $kasirLain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemTutupShiftUji($k, '452000.00', penutup: $gudang),
            ItemTutupShiftUji($k, '452000.00', penutup: $kasirLain),
            ItemTutupShiftUji($k, '452000.00', penutup: $k['Supervisor']),
        ]))->toBe([['Ditolak', 'TanpaIzin'], ['Ditolak', 'BukanShiftSendiri'], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(AmbilShiftUji($k['UuidShift'])->DitutupOleh)->toBe($k['Supervisor']->Id);
    });

    it('validasi: pecahan ≠ kas aktual, waktu sebelum buka / di masa depan, metode non-tunai tidak dikenal atau tunai, ringkasan hilang', function (): void {
        $k = SiapkanShiftTutupUji($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemTutupShiftUji($k, '452000.00', timpa: ['PecahanKasAkhir' => [['Nominal' => '100000.00', 'Jumlah' => 4]]]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['DitutupPada' => now()->subDay()->utc()->toIso8601ZuluString()]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['DitutupPada' => now()->addHour()->utc()->toIso8601ZuluString()]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['NonTunai' => [['UuidMetodePembayaran' => BantuanKasir::Uuid(), 'Jumlah' => '1000.00']]]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['NonTunai' => [['UuidMetodePembayaran' => $k['Tunai']->Uuid, 'Jumlah' => '1000.00']]]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['Ringkasan' => null]),
            ItemTutupShiftUji($k, '452000.00', timpa: ['UuidShift' => BantuanKasir::Uuid()]),
        ]))->toBe([
            ['Ditolak', 'PecahanTidakSesuai'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'MetodeBayarTidakDikenal'],
            ['Ditolak', 'MetodeBayarTidakDikenal'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'ShiftTidakDikenal'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(AmbilShiftUji($k['UuidShift'])->Status)->toBe(StatusShift::Terbuka);
    });

    it('J-11.1 selisih kurang Rp 7.000 (di bawah toleransi Rp 10.000) tanpa penyetuju: Dr Beban Selisih Kas / Cr Kas Outlet; Σ debit = Σ kredit', function (): void {
        $k = SiapkanShiftTutupUji($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '445000.00')]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = AmbilShiftUji($k['UuidShift']);

        expect($shift->Selisih)->toBe('-7000.00')
            ->and(AmbilBarisJurnalSelisihUji($shift))->toBe([
                [BantuanJurnal::IdAkunPeran(PeranAkun::BebanSelisihKas), '7000.00', '0.00'],
                [BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet), '0.00', '7000.00'],
            ])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('selisih di atas toleransi: tanpa alasan = AlasanDiperlukan, tanpa penyetuju = PersetujuanDiperlukan, penyetuju tanpa izin = PenyetujuTidakBerwenang. Supervisor sah diterima', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $alasan = 'Uang kembalian salah hitung saat antrean ramai';

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemTutupShiftUji($k, '430000.00', timpa: ['UuidPenyetuju' => $k['Supervisor']->Uuid]),
            ItemTutupShiftUji($k, '430000.00', timpa: ['Alasan' => $alasan]),
            ItemTutupShiftUji($k, '430000.00', timpa: ['Alasan' => $alasan, 'UuidPenyetuju' => $k['Kasir']->Uuid]),
            ItemTutupShiftUji($k, '430000.00', timpa: ['Alasan' => $alasan, 'UuidPenyetuju' => $k['Supervisor']->Uuid]),
        ]))->toBe([
            ['Ditolak', 'AlasanDiperlukan'],
            ['Ditolak', 'PersetujuanDiperlukan'],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
            ['Diterima', null],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = AmbilShiftUji($k['UuidShift']);

        expect($shift->Selisih)->toBe('-22000.00')
            ->and($shift->AlasanSelisih)->toBe($alasan)
            ->and($shift->IdPenyetujuSelisih)->toBe($k['Supervisor']->Id)
            ->and(AmbilBarisJurnalSelisihUji($shift)[0])->toBe([BantuanJurnal::IdAkunPeran(PeranAkun::BebanSelisihKas), '22000.00', '0.00'])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('J-11.2 selisih lebih Rp 28.000: penutup yang sendiri ber-izin shift.selisih.setujui menyetujui; Dr Kas Outlet / Cr Pendapatan Lain', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $item = ItemTutupShiftUji($k, '480000.00', timpa: ['Alasan' => 'Pelanggan tidak mengambil kembalian', 'UuidPenyetuju' => $k['Supervisor']->Uuid], penutup: $k['Supervisor']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(AmbilBarisJurnalSelisihUji(AmbilShiftUji($k['UuidShift'])))->toBe([
            [BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet), '28000.00', '0.00'],
            [BantuanJurnal::IdAkunPeran(PeranAkun::PendapatanLain), '0.00', '28000.00'],
        ])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('toleransi dari pengaturan kasir: toleransi Rp 50.000 menerima selisih Rp 22.000 tanpa penyetuju', function (): void {
        $k = SiapkanShiftTutupUji($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '200000', 'ShiftBersama' => false, 'ToleransiSelisihKas' => '50000', 'TutupShiftButa' => false])
            ->assertRedirect('/kelola/kasir/pengaturan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pengaturan = app(PengaturanKasirTenant::class)->Ambil();
        expect($pengaturan->toleransiSelisihKas->KeString())->toBe('50000.00')
            ->and($pengaturan->tutupShiftButa)->toBeFalse();

        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '200000', 'ShiftBersama' => false, 'ToleransiSelisihKas' => '-5'])
            ->assertSessionHasErrors('ToleransiSelisihKas');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '430000.00')]))->toBe([['Diterima', null]]);
    });

    it('kas seharusnya perangkat berbeda: diterima dengan angka server + PerluTinjauan KasSeharusnyaBerbeda. Persetujuan dinilai dari selisih server', function (): void {
        $k = SiapkanShiftTutupUji($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '452000.00', '440000.00')]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = AmbilShiftUji($k['UuidShift']);

        expect($shift->KasSeharusnya)->toBe('452000.00')
            ->and($shift->Selisih)->toBe('0.00')
            ->and($shift->PerluTinjauan)->toBeTrue()
            ->and($shift->AlasanTinjauan)->toStartWith('KasSeharusnyaBerbeda')
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TutupShift->value)->count())->toBe(0);
    });

    it('penjualan & kas yang tiba setelah shift ditutup tetap diterima dengan PerluTinjauan ShiftSudahDitutup', function (): void {
        $k = SiapkanShiftTutupUji($this);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '452000.00')]))->toBe([['Diterima', null]]);

        $jual = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $kas = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$jual, $kas]))->toBe([['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->where('Uuid', $jual['Uuid'])->sole();
        $mutasi = MutasiKas::query()->where('Uuid', $kas['Uuid'])->sole();

        expect($penjualan->PerluTinjauan)->toBeTrue()
            ->and($penjualan->AlasanTinjauan)->toContain('ShiftSudahDitutup')
            ->and($mutasi->PerluTinjauan)->toBeTrue()
            ->and($mutasi->AlasanTinjauan)->toStartWith('ShiftSudahDitutup')
            ->and(AmbilShiftUji($k['UuidShift'])->KasSeharusnya)->toBe('452000.00')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('isolasi tenant: perangkat tenant lain tidak bisa menutup shift tenant ini. Kolom tutup tidak bisa diubah lewat model setelah ditutup', function (): void {
        $a = SiapkanShiftTutupUji($this);
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [ItemTutupShiftUji($a, '452000.00', penutup: $b['Kasir'])]))->toBe([['Ditolak', 'ShiftTidakDikenal']]);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(AmbilShiftUji($a['UuidShift'])->Status)->toBe(StatusShift::Terbuka);

        BantuanKasir::KirimRingkas($this, $a['Token'], [ItemTutupShiftUji($a, '452000.00')]);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $shift = AmbilShiftUji($a['UuidShift']);

        expect(fn () => $shift->update(['KasAktual' => '1.00']))->toThrow(LogicException::class)
            ->and(fn () => AmbilShiftUji($a['UuidShift'])->update(['Status' => StatusShift::Terbuka]))->toThrow(LogicException::class);
    });
});

describe('F-11 laporan shift back-office', function (): void {
    it('detail shift: laporan X berjalan, lalu Z dengan data tutup (kas, alasan, penyetuju, non-tunai vs sistem, jurnal). Daftar menampilkan selisih. Tenant lain 404', function (): void {
        $k = SiapkanShiftTutupUji($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->get("/kelola/kasir/shift/{$k['UuidShift']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Shift/Detail')
            ->where('Tutup', null)
            ->where('Laporan.Penjualan.JumlahTransaksi', 2)
            ->where('Laporan.Penjualan.PenjualanKotor', '115500.00')
            ->where('Laporan.Penjualan.PenjualanBersih', '115500.00')
            ->where('Laporan.Penjualan.TotalAkhir', '115500.00')
            ->where('Laporan.Penjualan.PerMetode.0.Nama', 'Tunai')
            ->where('Laporan.Penjualan.PerMetode.0.Jumlah', '77000.00')
            ->where('Laporan.Penjualan.PerMetode.1.Jumlah', '38500.00')
            ->where('Laporan.Kas.TunaiMasukBersih', '77000.00')
            ->where('Laporan.Kas.KasSeharusnya', '452000.00'));

        BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '430000.00', timpa: [
            'Alasan' => 'Uang kembalian salah hitung',
            'UuidPenyetuju' => $k['Supervisor']->Uuid,
            'NonTunai' => [['UuidMetodePembayaran' => $k['Qris']->Uuid, 'Jumlah' => '37000.00']],
        ])]);

        $this->get("/kelola/kasir/shift/{$k['UuidShift']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Shift.Status', 'Tertutup')
            ->where('Tutup.KasSeharusnya', '452000.00')
            ->where('Tutup.KasAktual', '430000.00')
            ->where('Tutup.Selisih', '-22000.00')
            ->where('Tutup.AlasanSelisih', 'Uang kembalian salah hitung')
            ->where('Tutup.Penyetuju', $k['Supervisor']->Nama)
            ->where('Tutup.DitutupOleh', $k['Kasir']->Nama)
            ->where('Tutup.NonTunai.0.JumlahSistem', '38500.00')
            ->where('Tutup.NonTunai.0.JumlahDilaporkan', '37000.00')
            ->where('Tutup.NomorJurnal', fn (?string $nomor): bool => is_string($nomor) && str_starts_with($nomor, 'JU/')));

        $this->get('/kelola/kasir/shift')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Shift.Data.0.Status', 'Tertutup')
            ->where('Shift.Data.0.Selisih', '-22000.00')
            ->where('Shift.Data.0.KasAktual', '430000.00'));

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/kasir/shift/{$k['UuidShift']}")->assertNotFound();
    });

    it('izin shift.selisih.setujui: bawaan Supervisor, Manajer Outlet, Admin (Pemilik otomatis); Kasir tidak', function (): void {
        $izin = fn (PeranTenantBawaan $peran): array => array_map(fn ($i) => $i->value, $peran->AmbilIzin());

        expect($izin(PeranTenantBawaan::Supervisor))->toContain('shift.selisih.setujui')
            ->and($izin(PeranTenantBawaan::ManajerOutlet))->toContain('shift.selisih.setujui')
            ->and($izin(PeranTenantBawaan::Admin))->toContain('shift.selisih.setujui')
            ->and($izin(PeranTenantBawaan::Pemilik))->toContain('shift.selisih.setujui')
            ->and($izin(PeranTenantBawaan::Kasir))->not->toContain('shift.selisih.setujui');
    });
});

describe('F-11 × F-09 kas seharusnya setelah void & retur tunai', function (): void {
    it('void penjualan tunai (Rp 77.000) dan retur tunai dari laci (Rp 38.500) mengurangi kas seharusnya: 452.000 − 77.000 − 38.500 = Rp 336.500. Retur transfer tidak mengurangi laci', function (): void {
        $k = SiapkanShiftTutupUji($this);
        $tunai = Penjualan::query()->where('TotalAkhir', '77000.00')->sole();
        $qris = Penjualan::query()->where('TotalAkhir', '38500.00')->sole();
        $produkLain = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '38500.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $tunai),
            BantuanPenjualan::ItemRetur($k, $qris, [['Detail' => $qris->Detail()->firstOrFail()]]),
            BantuanPenjualan::ItemRetur($k, $produkLain, [['Detail' => $produkLain->Detail()->firstOrFail()]], ['Refund' => [['Metode' => $k['Transfer'], 'Jumlah' => null]]]),
        ]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemTutupShiftUji($k, '336500.00', '336500.00')]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->where('Uuid', $k['UuidShift'])->sole();
        expect($shift->Status)->toBe(StatusShift::Tertutup)
            ->and($shift->KasSeharusnya)->toBe('336500.00')
            ->and($shift->Selisih)->toBe('0.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});
