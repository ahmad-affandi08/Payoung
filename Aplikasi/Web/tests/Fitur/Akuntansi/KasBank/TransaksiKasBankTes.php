<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\SaldoNormal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\TransaksiKasBank;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake((string) config('akuntansi.DiskLampiran'));
});

/** Uuid akun tenant konteks dari kode. */
function UuidAkunKode(string $kode): string
{
    return (string) Akun::query()->where('Kode', $kode)->value('Uuid');
}

/**
 * Isian transaksi kas & bank bawaan: pengeluaran listrik Rp 1.250.000 dari Kas Outlet.
 *
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsianKasBank(array $timpa = []): array
{
    return [
        'Jenis' => 'Pengeluaran',
        'Tanggal' => '2026-09-20',
        'UuidAkunSumber' => UuidAkunKode('1-1100'),
        'UuidAkunTujuan' => UuidAkunKode('6-2000'),
        'Jumlah' => '1250000.00',
        'Keterangan' => 'Bayar listrik PLN September toko Solo Baru',
        ...$timpa,
    ];
}

/**
 * Baris jurnal satu transaksi: [Kode akun => [Debit, Kredit]].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function BarisJurnalTransaksi(TransaksiKasBank $t): array
{
    $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TransaksiKasBank->value)->where('IdSumber', $t->Id)->sole();
    $hasil = [];

    foreach (JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get() as $d) {
        $hasil[(string) Akun::query()->whereKey($d->IdAkun)->value('Kode')] = [$d->Debit, $d->Kredit];
    }

    return $hasil;
}

describe('F-13a transaksi kas & bank (FIN-03, PRD "Rincian F-13a")', function (): void {
    it('pengeluaran, penerimaan, transfer: nomor KB/{YYYY}/{MM}/{SEQ4}, jurnal seimbang diposting saat simpan, diaudit', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidOutlet' => $t['Outlet']->Uuid]))->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Penerimaan', 'UuidAkunSumber' => UuidAkunKode('3-1000'), 'UuidAkunTujuan' => UuidAkunKode('1-1200'), 'Jumlah' => '25000000', 'Keterangan' => 'Setoran modal awal pemilik']))->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Transfer', 'Tanggal' => '2026-10-01', 'UuidAkunSumber' => UuidAkunKode('1-1150'), 'UuidAkunTujuan' => UuidAkunKode('1-1200'), 'Jumlah' => '3500000.50', 'Keterangan' => 'Setor kas brankas ke BRI']))->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        [$keluar, $masuk, $transfer] = TransaksiKasBank::query()->orderBy('Id')->get()->all();

        expect([$keluar->Nomor, $masuk->Nomor, $transfer->Nomor])->toBe(['KB/2026/09/0001', 'KB/2026/09/0002', 'KB/2026/10/0001'])
            ->and($keluar->IdOutlet)->toBe($t['Outlet']->Id)
            ->and(BarisJurnalTransaksi($keluar))->toBe(['6-2000' => ['1250000.00', '0.00'], '1-1100' => ['0.00', '1250000.00']])
            ->and(BarisJurnalTransaksi($masuk))->toBe(['1-1200' => ['25000000.00', '0.00'], '3-1000' => ['0.00', '25000000.00']])
            ->and(BarisJurnalTransaksi($transfer))->toBe(['1-1200' => ['3500000.50', '0.00'], '1-1150' => ['0.00', '3500000.50']])
            ->and(JurnalDetail::query()->where('IdOutlet', $t['Outlet']->Id)->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'kas-bank.simpan')->count())->toBe(3);

        // Invarian: setiap jurnal seimbang dan Σ debit = Σ kredit seluruh buku.
        $total = JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) AS CHAR) AS D, CAST(SUM(`Kredit`) AS CHAR) AS K')->toBase()->first();
        expect($total?->D)->toBe($total?->K);
        foreach (Jurnal::query()->get() as $jurnal) {
            expect($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)->and($jurnal->UuidSumber)->not->toBeNull();
        }

        $this->get("/kelola/akuntansi/kas-bank/{$keluar->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/KasBank/Detail')
            ->where('Transaksi.Nomor', 'KB/2026/09/0001')
            ->where('Transaksi.AkunSumber', '1-1100 Kas Outlet')
            ->where('Transaksi.AkunTujuan', '6-2000 Beban Sewa, Listrik, Air, Internet')
            ->where('Transaksi.Jumlah', '1250000.00')
            ->where('Transaksi.NamaOutlet', 'Toko Sembako Berkah Jaya')
            ->has('Jurnal', 1)
            ->missing('Transaksi.PathLampiran'));
    });

    it('aturan akun: kas/bank wajib di sisinya, akun lawan sesuai jenis, transfer ke akun sama ditolak, jumlah > 0, akun nonaktif ditolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $mati = Akun::query()->create(['Kode' => '6-2100', 'Nama' => 'Beban Lama', 'Jenis' => TipeAkun::Beban, 'SaldoNormal' => SaldoNormal::Debit, 'Aktif' => false]);

        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidAkunSumber' => UuidAkunKode('1-1400')]))->assertSessionHasErrors('UuidAkunSumber');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidAkunTujuan' => UuidAkunKode('1-1200')]))->assertSessionHasErrors('UuidAkunTujuan');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidAkunTujuan' => UuidAkunKode('5-1000')]))->assertSessionHasErrors('UuidAkunTujuan');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidAkunTujuan' => $mati->Uuid]))->assertSessionHasErrors('UuidAkunTujuan');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Penerimaan', 'UuidAkunSumber' => UuidAkunKode('4-9000'), 'UuidAkunTujuan' => UuidAkunKode('6-2000')]))->assertSessionHasErrors('UuidAkunTujuan');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Transfer', 'UuidAkunSumber' => UuidAkunKode('1-1200'), 'UuidAkunTujuan' => UuidAkunKode('1-1200')]))->assertSessionHasErrors('UuidAkunTujuan');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jumlah' => '0']))->assertSessionHasErrors('Jumlah');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jumlah' => '-5000']))->assertSessionHasErrors('Jumlah');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jumlah' => '1.5e6']))->assertSessionHasErrors('Jumlah');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Tanggal' => '20/09/2026']))->assertSessionHasErrors('Tanggal');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(TransaksiKasBank::query()->count())->toBe(0)->and(Jurnal::query()->count())->toBe(0);
    });

    it('periode terkunci ditolak tanpa dokumen, jurnal, maupun nomor terpakai', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::KunciPeriode('2026-08');
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Tanggal' => '2026-08-31', 'Lampiran' => UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('Tanggal');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Tanggal' => '2026-09-01']))->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(TransaksiKasBank::query()->pluck('Nomor')->all())->toBe(['KB/2026/09/0001'])
            ->and(Storage::disk((string) config('akuntansi.DiskLampiran'))->allFiles())->toBe([]);
    });

    it('lampiran opsional tersimpan privat dan hanya diunduh lewat rute berizin; jenis berkas dibatasi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Lampiran' => UploadedFile::fake()->create('skrip.exe', 10, 'application/octet-stream')]))->assertSessionHasErrors('Lampiran');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Lampiran' => UploadedFile::fake()->image('Nota PLN September.png')]))->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $transaksi = TransaksiKasBank::query()->sole();
        expect($transaksi->PathLampiran)->toStartWith("akuntansi/kas-bank/{$t['Tenant']->Id}/")
            ->and($transaksi->NamaLampiran)->toBe('Nota PLN September.png');
        Storage::disk((string) config('akuntansi.DiskLampiran'))->assertExists((string) $transaksi->PathLampiran);

        $this->get("/kelola/akuntansi/kas-bank/{$transaksi->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Transaksi.Lampiran.Nama', 'Nota PLN September.png')->where('Transaksi.AdaLampiran', true));
        $this->get("/kelola/akuntansi/kas-bank/{$transaksi->Uuid}/lampiran")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('pembalik: dokumen KB baru + jurnal cermin (IdJurnalDibalik), asal tidak berubah, hanya sekali, pembalik tidak bisa dibalik', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertRedirect();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $asal = TransaksiKasBank::query()->sole();

        $this->post("/kelola/akuntansi/kas-bank/{$asal->Uuid}/pembalik", ['Tanggal' => '2026-09-19', 'Alasan' => 'Salah input nominal'])->assertSessionHasErrors('Tanggal');
        $this->post("/kelola/akuntansi/kas-bank/{$asal->Uuid}/pembalik", ['Tanggal' => '2026-09-22', 'Alasan' => 'Salah input nominal'])->assertRedirect();
        $this->post("/kelola/akuntansi/kas-bank/{$asal->Uuid}/pembalik", ['Tanggal' => '2026-09-22', 'Alasan' => 'Salah input lagi'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pembalik = TransaksiKasBank::query()->where('IdTransaksiDibalik', $asal->Id)->sole();
        $this->post("/kelola/akuntansi/kas-bank/{$pembalik->Uuid}/pembalik", ['Tanggal' => '2026-09-23', 'Alasan' => 'Batalkan pembalik'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $jurnalAsal = Jurnal::query()->where('JenisSumber', 'TransaksiKasBank')->where('IdSumber', $asal->Id)->sole();
        $jurnalBalik = Jurnal::query()->where('JenisSumber', 'TransaksiKasBank')->where('IdSumber', $pembalik->Id)->sole();

        expect($pembalik->Nomor)->toBe('KB/2026/09/0002')
            ->and($pembalik->Keterangan)->toBe('Pembalik KB/2026/09/0001: Salah input nominal')
            ->and($jurnalBalik->IdJurnalDibalik)->toBe($jurnalAsal->Id)
            ->and($jurnalBalik->UuidSumber)->toBe($pembalik->Uuid)
            ->and(BarisJurnalTransaksi($pembalik))->toBe(['1-1100' => ['1250000.00', '0.00'], '6-2000' => ['0.00', '1250000.00']])
            ->and($asal->fresh()?->Jumlah)->toBe('1250000.00')
            ->and(TransaksiKasBank::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'kas-bank.balik')->count())->toBe(1);

        // Saldo kas kembali seperti semula.
        $idKas = Akun::query()->where('Kode', '1-1100')->value('Id');
        expect((string) JurnalDetail::query()->where('IdAkun', $idKas)->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS CHAR) AS S')->value('S'))->toBe('0.00');

        $this->get('/kelola/akuntansi/kas-bank')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Transaksi.Data.0.Pembalik', true)
            ->where('Transaksi.Data.1.Dibalik', true));
    });

    it('dokumen append-only: model menolak ubah & hapus', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $t = TransaksiKasBank::query()->create([
            'Nomor' => 'KB/2026/09/0009', 'Jenis' => 'Pengeluaran', 'Tanggal' => '2026-09-20',
            'IdAkunSumber' => Akun::query()->where('Kode', '1-1100')->value('Id'), 'IdAkunTujuan' => Akun::query()->where('Kode', '6-2000')->value('Id'),
            'Jumlah' => '1000.00', 'Keterangan' => 'Uji',
        ]);

        expect(fn () => $t->forceFill(['Jumlah' => '2000.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => $t->delete())->toThrow(LogicException::class);
    });

    it('daftar & saldo kas/bank: TabelData server (cari, filter jenis/tanggal), saldo per akun kas/bank dari jurnal', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Penerimaan', 'UuidAkunSumber' => UuidAkunKode('3-1000'), 'UuidAkunTujuan' => UuidAkunKode('1-1100'), 'Jumlah' => '5000000', 'Keterangan' => 'Modal awal laci']))->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Jenis' => 'Transfer', 'UuidAkunSumber' => UuidAkunKode('1-1100'), 'UuidAkunTujuan' => UuidAkunKode('1-1200'), 'Jumlah' => '2000000', 'Keterangan' => 'Setor ke bank']))->assertRedirect();

        $this->get('/kelola/akuntansi/kas-bank')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/KasBank/Daftar')
            ->where('Transaksi.Meta.Total', 3)
            ->where('Saldo', fn ($saldo): bool => collect($saldo)->pluck('Saldo', 'Kode')->all() === ['1-1100' => '1750000.00', '1-1150' => '0.00', '1-1200' => '2000000.00'])
            ->has('OpsiJenis', 3)
            ->missing('OpsiAkun')
            ->where('Izin.Kelola', true));
        $this->getJson('/kelola/akuntansi/kas-bank?cari=listrik')->assertOk()->assertJsonPath('Meta.Total', 1);
        $this->getJson('/kelola/akuntansi/kas-bank?saring[Jenis]=Transfer,Penerimaan')->assertJsonPath('Meta.Total', 2);
        $this->getJson('/kelola/akuntansi/kas-bank?saring[Tanggal]=2026-09-21..2026-09-30')->assertJsonPath('Meta.Total', 0);
        $this->getJson('/kelola/akuntansi/kas-bank?urut=-Jumlah')->assertJsonPath('Data.0.Jumlah', '5000000.00');
    });

    it('halaman catat (halaman penuh): opsi jenis, outlet, akun kas/bank & lawan, aturan lampiran', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->get('/kelola/akuntansi/kas-bank/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/KasBank/Buat')
            ->where('OpsiJenis', fn ($jenis): bool => collect($jenis)->pluck('Nilai')->all() === ['Pengeluaran', 'Penerimaan', 'Transfer'])
            ->has('OpsiOutlet')
            ->where('OpsiAkun', fn ($akun): bool => collect($akun)->contains(fn (array $a): bool => $a['Kode'] === '1-1100' && $a['KasBank'] === true)
                && collect($akun)->contains(fn (array $a): bool => $a['Kode'] === '6-2000' && $a['KasBank'] === false))
            ->where('WajibOutlet', false)
            ->where('Lampiran.Ekstensi', (array) config('akuntansi.EkstensiLampiran'))
            ->where('Lampiran.UkuranMaksimalKb', (int) config('akuntansi.UkuranMaksimalLampiranKb'))
            ->missing('Transaksi'));

        // Simpan dari halaman catat: diarahkan ke detail dokumen baru (bukan kembali ke /buat).
        $respons = $this->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $respons->assertRedirect('/kelola/akuntansi/kas-bank/'.TransaksiKasBank::query()->sole()->Uuid);
    });

    it('izin, isolasi tenant, dan batas outlet', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $solo = BantuanJurnal::BuatOutlet();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidOutlet' => $a['Outlet']->Uuid]))->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidOutlet' => $solo->Uuid, 'Keterangan' => 'Listrik Solo']))->assertRedirect();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['Keterangan' => 'Listrik kantor pusat']))->assertRedirect();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        [$diUtama, $diSolo, $pusat] = TransaksiKasBank::query()->orderBy('Id')->get()->all();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/kas-bank')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/kas-bank/buat')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->get('/kelola/akuntansi/kas-bank/buat')->assertForbidden();

        // Akuntan outlet Solo: hanya melihat & mencatat di Solo; wajib memilih outlet.
        $akuntan = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        BantuanOrganisasi::Masuk($this, $akuntan, $a['Tenant']->Id);
        $this->get('/kelola/akuntansi/kas-bank')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Transaksi.Meta.Total', 1)
            ->where('Transaksi.Data.0.Uuid', $diSolo->Uuid)
            ->where('Saldo', fn ($saldo): bool => collect($saldo)->firstWhere('Kode', '1-1100')['Saldo'] === '-1250000.00'));
        $this->get('/kelola/akuntansi/kas-bank/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/KasBank/Buat')
            ->where('WajibOutlet', true)
            ->where('OpsiOutlet', fn ($outlet): bool => collect($outlet)->pluck('Uuid')->all() === [$solo->Uuid]));
        $this->get("/kelola/akuntansi/kas-bank/{$diUtama->Uuid}")->assertNotFound();
        $this->get("/kelola/akuntansi/kas-bank/{$pusat->Uuid}")->assertNotFound();
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertSessionHasErrors('UuidOutlet');
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank(['UuidOutlet' => $a['Outlet']->Uuid]))->assertNotFound();
        $this->post("/kelola/akuntansi/kas-bank/{$diUtama->Uuid}/pembalik", ['Tanggal' => '2026-09-22', 'Alasan' => 'Coba balik'])->assertNotFound();

        // Tenant lain.
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->get("/kelola/akuntansi/kas-bank/{$diUtama->Uuid}")->assertNotFound();
        $this->get("/kelola/akuntansi/kas-bank/{$diUtama->Uuid}/lampiran")->assertNotFound();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $this->post('/kelola/akuntansi/kas-bank', IsianKasBank())->assertSessionHasErrors('UuidAkunSumber');

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(TransaksiKasBank::query()->count())->toBe(3)
            ->and(DB::table('TransaksiKasBank')->where('IdTenant', $b['Tenant']->Id)->count())->toBe(0);
    });
});
