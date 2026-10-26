<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\CatatJurnalUmum;
use App\Domain\Akuntansi\Data\DataBarisJurnalUmum;
use App\Domain\Akuntansi\Data\DataJurnalUmum;
use App\Domain\Akuntansi\Enum\JenisJurnalUmum;
use App\Domain\Akuntansi\Enum\SaldoNormal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\JurnalUmum;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake((string) config('akuntansi.DiskLampiran'));
});

function UuidAkunJu(string $kode): string
{
    return (string) Akun::query()->where('Kode', $kode)->value('Uuid');
}

/** Akun kewajiban biasa (bukan akun kendali) untuk akrual beban. */
function BuatAkunAkrual(): string
{
    return Akun::query()->create(['Kode' => '2-9500', 'Nama' => 'Beban Yang Masih Harus Dibayar', 'Jenis' => TipeAkun::Kewajiban, 'SaldoNormal' => SaldoNormal::Kredit])->Uuid;
}

/** Uuid akun yang dipetakan ke peran `$kunci` (akun kendali bila perannya persediaan/piutang/dst.). */
function UuidAkunPeran(string $kunci): string
{
    $idAkun = PemetaanAkun::query()->where('Kunci', $kunci)->whereNull('IdOutlet')->value('IdAkun');

    return (string) Akun::query()->whereKey($idAkun)->value('Uuid');
}

/**
 * Isian jurnal umum bawaan: akrual listrik September Rp 1.500.000 (Dr beban listrik, Cr beban yang masih harus dibayar).
 *
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsianJurnalUmum(string $uuidAkrual, array $timpa = []): array
{
    return [
        'UuidKlien' => (string) Str::ulid(),
        'Jenis' => 'Penyesuaian',
        'Tanggal' => '2026-09-30',
        'Keterangan' => 'Akrual listrik September belum ditagih PLN',
        'Baris' => [
            ['UuidAkun' => UuidAkunJu('6-2000'), 'Debit' => '1500000.00', 'Kredit' => ''],
            ['UuidAkun' => $uuidAkrual, 'Debit' => '', 'Kredit' => '1500000.00'],
        ],
        ...$timpa,
    ];
}

describe('FIN-05 jurnal umum manual (D-131)', function (): void {
    it('catat: dokumen JM/{YYYY}/{MM}/{SEQ4} + jurnal manual seimbang (Otomatis=false) di transaksi yang sama, diaudit, tampil di detail', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Referensi' => 'MEMO-AKR-09']))->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Tanggal' => '2026-10-02', 'Jenis' => 'Umum']))->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        [$pertama, $kedua] = JurnalUmum::query()->orderBy('Id')->get()->all();
        $jurnal = Jurnal::query()->where('JenisSumber', 'JurnalUmum')->where('IdSumber', $pertama->Id)->sole();

        expect([$pertama->Nomor, $kedua->Nomor])->toBe(['JM/2026/09/0001', 'JM/2026/10/0001'])
            ->and($pertama->Jenis)->toBe(JenisJurnalUmum::Penyesuaian)
            ->and($pertama->Referensi)->toBe('MEMO-AKR-09')
            ->and($pertama->TotalNilai)->toBe('1500000.00')
            ->and($jurnal->Otomatis)->toBeFalse()
            ->and($jurnal->UuidSumber)->toBe($pertama->Uuid)
            ->and($jurnal->NomorSumber)->toBe('JM/2026/09/0001')
            ->and($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)
            ->and($jurnal->Nomor)->toStartWith('JU/2026/09/')
            ->and(LogAudit::query()->where('Peristiwa', 'jurnal-umum.simpan')->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'jurnal.posting')->count())->toBe(2);

        $baris = JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get();
        expect($baris->map(fn (JurnalDetail $d): array => [(string) Akun::query()->whereKey($d->IdAkun)->value('Kode'), $d->Debit, $d->Kredit])->all())
            ->toBe([['6-2000', '1500000.00', '0.00'], ['2-9500', '0.00', '1500000.00']]);

        // Invarian: seluruh buku seimbang dan neraca tetap seimbang setelah jurnal manual.
        $total = JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) AS CHAR) AS D, CAST(SUM(`Kredit`) AS CHAR) AS K')->toBase()->first();
        expect($total?->D)->toBe($total?->K);
        $this->get('/kelola/akuntansi/laporan/neraca?dari=2026-09-01&sampai=2026-10-31')->assertInertia(fn (AssertableInertia $h) => $h->where('Laporan.Seimbang', true));

        $this->get("/kelola/akuntansi/jurnal-umum/{$pertama->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/JurnalUmum/Detail')
            ->where('JurnalUmum.Nomor', 'JM/2026/09/0001')
            ->where('JurnalUmum.LabelJenis', 'Jurnal penyesuaian')
            ->where('JurnalUmum.Referensi', 'MEMO-AKR-09')
            ->where('JurnalUmum.Dibalik', false)
            ->where('Jurnal.Nomor', $jurnal->Nomor)
            ->has('Baris', 2)
            ->where('Total.Debit', '1500000.00')
            ->where('Izin.Kelola', true)
            ->missing('JurnalUmum.PathLampiran'));

        // Jurnal muncul di daftar Jurnal dengan sumber & tautan ke dokumennya.
        $this->getJson('/kelola/akuntansi/jurnal?saring[JenisSumber]=JurnalUmum')->assertOk()
            ->assertJsonPath('Meta.Total', 2)
            ->assertJsonPath('Data.0.LabelJenisSumber', 'Jurnal umum')
            ->assertJsonPath('Data.1.TautanSumber', "/kelola/akuntansi/jurnal-umum/{$pertama->Uuid}");
    });

    it('aturan jurnal: seimbang, minimal dua baris, satu sisi per baris, akun aktif milik tenant, tidak ada dua sisi pada akun sama, format uang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        $mati = Akun::query()->create(['Kode' => '6-2100', 'Nama' => 'Beban Lama', 'Jenis' => TipeAkun::Beban, 'SaldoNormal' => SaldoNormal::Debit, 'Aktif' => false])->Uuid;
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $listrik = UuidAkunJu('6-2000');

        // Tidak seimbang.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $listrik, 'Debit' => '1500000', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '1499999.99'],
        ]]))->assertSessionHasErrors('Baris');
        // Satu baris.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [['UuidAkun' => $listrik, 'Debit' => '1500000', 'Kredit' => '']]]))->assertSessionHasErrors('Baris');
        // Baris dengan dua sisi.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $listrik, 'Debit' => '100', 'Kredit' => '100'], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '100'],
        ]]))->assertSessionHasErrors('Baris.0.Kredit');
        // Baris kosong.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $listrik, 'Debit' => '100', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => ''],
        ]]))->assertSessionHasErrors('Baris.1.Debit');
        // Akun nonaktif, tidak dikenal, dan akun yang sama di dua sisi.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $mati, 'Debit' => '100', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '100'],
        ]]))->assertSessionHasErrors('Baris.0.UuidAkun');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => (string) Str::ulid(), 'Debit' => '100', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '100'],
        ]]))->assertSessionHasErrors('Baris.0.UuidAkun');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $akrual, 'Debit' => '100', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '100'],
        ]]))->assertSessionHasErrors('Baris.1.UuidAkun');
        // Format uang, keterangan kosong, jenis tidak dikenal, tanggal salah.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $listrik, 'Debit' => '1.5e6', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '1500000'],
        ]]))->assertSessionHasErrors('Baris.0.Debit');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => $listrik, 'Debit' => '-5', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '5'],
        ]]))->assertSessionHasErrors('Baris.0.Debit');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Keterangan' => '']))->assertSessionHasErrors('Keterangan');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Jenis' => 'Bebas']))->assertSessionHasErrors('Jenis');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Tanggal' => '30/09/2026']))->assertSessionHasErrors('Tanggal');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['UuidKlien' => 'bukan-ulid']))->assertSessionHasErrors('UuidKlien');
        // Tanggal masa depan.
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Tanggal' => CarbonImmutable::today()->addDays(3)->toDateString()]))->assertSessionHasErrors('Tanggal');

        // Tidak ada yang tersimpan dan tidak ada nomor yang hangus.
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(JurnalUmum::query()->count())->toBe(0)->and(Jurnal::query()->count())->toBe(0);
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(JurnalUmum::query()->sole()->Nomor)->toBe('JM/2026/09/0001');
    });

    it('akun kendali ditolak (persediaan, piutang, hutang, deposit, aset tetap): saldonya turunan buku pembantu (aturan #9)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        foreach (['PersediaanBarangDagang', 'PiutangUsaha', 'HutangUsaha'] as $peran) {
            $uuid = UuidAkunPeran($peran);
            expect($uuid)->not->toBe('');
            $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
                ['UuidAkun' => $uuid, 'Debit' => '250000', 'Kredit' => ''], ['UuidAkun' => $akrual, 'Debit' => '', 'Kredit' => '250000'],
            ]]))->assertSessionHasErrors('Baris.0.UuidAkun');
            $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
                ['UuidAkun' => UuidAkunJu('6-2000'), 'Debit' => '250000', 'Kredit' => ''], ['UuidAkun' => $uuid, 'Debit' => '', 'Kredit' => '250000'],
            ]]))->assertSessionHasErrors('Baris.1.UuidAkun');
        }

        // Override outlet pada peran kendali juga dijaga, dan akun nonkendali (kas, ekuitas) tetap boleh.
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(JurnalUmum::query()->count())->toBe(0);
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => [
            ['UuidAkun' => UuidAkunJu('1-1100'), 'Debit' => '250000', 'Kredit' => ''], ['UuidAkun' => UuidAkunJu('3-1000'), 'Debit' => '', 'Kredit' => '250000'],
        ]]))->assertSessionHasNoErrors();

        // Halaman catat tidak menawarkan akun kendali.
        $this->get('/kelola/akuntansi/jurnal-umum/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/JurnalUmum/Buat')
            ->where('OpsiAkun', function ($akun) use ($t): bool {
                $kode = collect($akun)->pluck('Kode')->all();
                $kendali = Akun::query()->whereIn('Id', PemetaanAkun::query()->whereIn('Kunci', ['PersediaanBarangDagang', 'PiutangUsaha', 'HutangUsaha'])->pluck('IdAkun'))->pluck('Kode')->all();

                return $t['Tenant']->Id > 0 && in_array('6-2000', $kode, true) && in_array('1-1100', $kode, true) && array_intersect($kode, $kendali) === [];
            }));
    });

    it('periode terkunci ditolak tanpa dokumen, jurnal, lampiran, maupun nomor terpakai', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::KunciPeriode('2026-08');
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Tanggal' => '2026-08-31', 'Lampiran' => UploadedFile::fake()->create('memo.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('Tanggal');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Tanggal' => '2026-09-01']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(JurnalUmum::query()->pluck('Nomor')->all())->toBe(['JM/2026/09/0001'])
            ->and(Jurnal::query()->count())->toBe(1)
            ->and(Storage::disk((string) config('akuntansi.DiskLampiran'))->allFiles())->toBe([]);
    });

    it('idempoten per UuidKlien: kiriman ganda menghasilkan satu dokumen & satu jurnal. Aksi langsung mengembalikan dokumen yang sama', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $isian = IsianJurnalUmum($akrual);

        $pertama = $this->post('/kelola/akuntansi/jurnal-umum', $isian)->assertSessionHasNoErrors();
        $kedua = $this->post('/kelola/akuntansi/jurnal-umum', $isian)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $dokumen = JurnalUmum::query()->sole();
        $pertama->assertRedirect("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}");
        $kedua->assertRedirect("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}");
        expect(Jurnal::query()->count())->toBe(1)
            ->and(JurnalDetail::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'jurnal-umum.simpan')->count())->toBe(1);
        $this->get("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}")->assertOk();

        // Lewat aksi langsung (dipanggil dari mana pun): sama, dan lampiran kiriman ulang tidak tertinggal.
        $idAkun = fn (string $uuid): string => $uuid;
        $data = new DataJurnalUmum(
            $dokumen->UuidKlien,
            JenisJurnalUmum::Umum,
            CarbonImmutable::parse('2026-09-30'),
            null,
            'Kiriman ulang',
            [new DataBarisJurnalUmum($idAkun(UuidAkunJu('6-2000')), null, Uang::Dari('1'), Uang::Nol()), new DataBarisJurnalUmum($akrual, null, Uang::Nol(), Uang::Dari('1'))],
            UploadedFile::fake()->create('ulang.pdf', 10, 'application/pdf'),
            null,
        );
        expect(app(CatatJurnalUmum::class)->Jalankan($data)->Id)->toBe($dokumen->Id)
            ->and(Storage::disk((string) config('akuntansi.DiskLampiran'))->allFiles())->toBe([])
            ->and(JurnalUmum::query()->count())->toBe(1);
    });

    it('lampiran opsional tersimpan privat, hanya diunduh lewat rute berizin. Jenis berkas dibatasi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Lampiran' => UploadedFile::fake()->create('skrip.exe', 10, 'application/octet-stream')]))->assertSessionHasErrors('Lampiran');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Lampiran' => UploadedFile::fake()->image('Memo Akrual PLN.png')]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $dokumen = JurnalUmum::query()->sole();
        expect($dokumen->PathLampiran)->toStartWith("akuntansi/jurnal-umum/{$t['Tenant']->Id}/")->and($dokumen->NamaLampiran)->toBe('Memo Akrual PLN.png');
        Storage::disk((string) config('akuntansi.DiskLampiran'))->assertExists((string) $dokumen->PathLampiran);

        $this->get("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->where('JurnalUmum.Lampiran.Nama', 'Memo Akrual PLN.png'));
        $this->get("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}/lampiran")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/akuntansi/jurnal-umum/{$dokumen->Uuid}/lampiran")->assertForbidden();
    });

    it('pembalik: dokumen JM baru + jurnal cermin (IdJurnalDibalik), asal tidak berubah, hanya sekali, pembalik tidak bisa dibalik, saldo kembali nol', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $asal = JurnalUmum::query()->sole();
        $alamat = "/kelola/akuntansi/jurnal-umum/{$asal->Uuid}/pembalik";

        $this->post($alamat, ['Tanggal' => '2026-09-29', 'Alasan' => 'Akrual dibalik awal bulan'])->assertSessionHasErrors('Tanggal');
        $this->post($alamat, ['Tanggal' => CarbonImmutable::today()->addDays(3)->toDateString(), 'Alasan' => 'Akrual dibalik awal bulan'])->assertSessionHasErrors('Tanggal');
        $this->post($alamat, ['Tanggal' => '2026-10-01', 'Alasan' => 'abc'])->assertSessionHasErrors('Alasan');
        $this->post($alamat, ['Tanggal' => '2026-10-01', 'Alasan' => 'Akrual dibalik awal bulan'])->assertSessionHasNoErrors();
        $this->post($alamat, ['Tanggal' => '2026-10-01', 'Alasan' => 'Balik lagi'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pembalik = JurnalUmum::query()->where('IdJurnalUmumDibalik', $asal->Id)->sole();
        $this->post("/kelola/akuntansi/jurnal-umum/{$pembalik->Uuid}/pembalik", ['Tanggal' => '2026-10-02', 'Alasan' => 'Batalkan pembalik'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $jurnalAsal = Jurnal::query()->where('JenisSumber', 'JurnalUmum')->where('IdSumber', $asal->Id)->sole();
        $jurnalBalik = Jurnal::query()->where('JenisSumber', 'JurnalUmum')->where('IdSumber', $pembalik->Id)->sole();
        expect($pembalik->Nomor)->toBe('JM/2026/10/0001')
            ->and($pembalik->Keterangan)->toBe('Pembalik JM/2026/09/0001: Akrual dibalik awal bulan')
            ->and($pembalik->Jenis)->toBe(JenisJurnalUmum::Penyesuaian)
            ->and($pembalik->TotalNilai)->toBe('1500000.00')
            ->and($jurnalBalik->IdJurnalDibalik)->toBe($jurnalAsal->Id)
            ->and($jurnalBalik->UuidSumber)->toBe($pembalik->Uuid)
            ->and($jurnalBalik->NomorSumber)->toBe($pembalik->Nomor)
            ->and($jurnalBalik->Otomatis)->toBeFalse()
            ->and($asal->fresh()?->TotalNilai)->toBe('1500000.00')
            ->and(JurnalUmum::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'jurnal-umum.balik')->count())->toBe(1);

        // Setiap akun kembali bersaldo nol setelah dibalik.
        foreach (['6-2000', '2-9500'] as $kode) {
            $idAkun = Akun::query()->where('Kode', $kode)->value('Id');
            expect((string) JurnalDetail::query()->where('IdAkun', $idAkun)->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS CHAR) AS S')->value('S'))->toBe('0.00');
        }

        $this->get("/kelola/akuntansi/jurnal-umum/{$asal->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('JurnalUmum.Dibalik', true)->where('JurnalUmum.NomorPembalik', $pembalik->Nomor));
        $this->get("/kelola/akuntansi/jurnal-umum/{$pembalik->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('JurnalUmum.Pembalik', true)->where('JurnalUmum.NomorDibalik', $asal->Nomor));
    });

    it('pembalik di periode terkunci ditolak dan tidak menghabiskan nomor', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $asal = JurnalUmum::query()->sole();
        BantuanPersediaan::KunciPeriode('2026-10');

        $this->post("/kelola/akuntansi/jurnal-umum/{$asal->Uuid}/pembalik", ['Tanggal' => '2026-10-01', 'Alasan' => 'Akrual dibalik awal bulan'])->assertSessionHasErrors('Tanggal');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(JurnalUmum::query()->count())->toBe(1)->and(Jurnal::query()->count())->toBe(1);
    });

    it('dokumen append-only: model menolak ubah & hapus. Satu dokumen tidak bisa punya dua pembalik di skema', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akrual = BuatAkunAkrual();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $dokumen = JurnalUmum::query()->sole();

        expect(fn () => $dokumen->forceFill(['Keterangan' => 'Diubah'])->save())->toThrow(LogicException::class)
            ->and(fn () => $dokumen->delete())->toThrow(LogicException::class);

        $baris = ['Nomor' => 'JM/2026/10/0090', 'Jenis' => 'Umum', 'Tanggal' => '2026-10-01', 'Keterangan' => 'Pembalik ganda', 'TotalNilai' => '1.00', 'IdJurnalUmumDibalik' => $dokumen->Id];
        JurnalUmum::query()->create([...$baris, 'UuidKlien' => (string) Str::ulid()]);
        expect(fn () => JurnalUmum::query()->create([...$baris, 'Nomor' => 'JM/2026/10/0091', 'UuidKlien' => (string) Str::ulid()]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('izin, isolasi tenant, dan batas outlet', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $akrual = BuatAkunAkrual();
        $solo = BantuanJurnal::BuatOutlet();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $baris = fn (?string $outlet) => [
            ['UuidAkun' => UuidAkunJu('6-2000'), 'UuidOutlet' => $outlet, 'Debit' => '700000', 'Kredit' => ''],
            ['UuidAkun' => $akrual, 'UuidOutlet' => $outlet, 'Debit' => '', 'Kredit' => '700000'],
        ];
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris($solo->Uuid), 'Keterangan' => 'Akrual Solo']))->assertSessionHasNoErrors();
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris($a['Outlet']->Uuid), 'Keterangan' => 'Akrual utama']))->assertSessionHasNoErrors();
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris(null), 'Keterangan' => 'Akrual pusat']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        [$diSolo, $diUtama, $pusat] = JurnalUmum::query()->orderBy('Id')->get()->all();

        // Peran tanpa izin kelola/lihat keuangan.
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/akuntansi/jurnal-umum/{$diSolo->Uuid}")->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/jurnal-umum/buat')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post("/kelola/akuntansi/jurnal-umum/{$diSolo->Uuid}/pembalik", ['Tanggal' => '2026-10-01', 'Alasan' => 'Coba balik'])->assertForbidden();

        // Akuntan yang dibatasi ke outlet Solo: hanya melihat jurnal Solo; tiap baris wajib berisi outlet aksesnya.
        $akuntan = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        BantuanOrganisasi::Masuk($this, $akuntan, $a['Tenant']->Id);
        $this->get("/kelola/akuntansi/jurnal-umum/{$diSolo->Uuid}")->assertOk();
        $this->get("/kelola/akuntansi/jurnal-umum/{$diUtama->Uuid}")->assertNotFound();
        $this->get("/kelola/akuntansi/jurnal-umum/{$pusat->Uuid}")->assertNotFound();
        $this->get('/kelola/akuntansi/jurnal-umum/buat')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('WajibOutlet', true)->where('OpsiOutlet', fn ($o): bool => collect($o)->pluck('Uuid')->all() === [$solo->Uuid]));
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris(null)]))->assertSessionHasErrors('Baris.0.UuidOutlet');
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris($a['Outlet']->Uuid)]))->assertNotFound();
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual, ['Baris' => $baris($solo->Uuid), 'Keterangan' => 'Akrual Solo 2']))->assertSessionHasNoErrors();
        $this->post("/kelola/akuntansi/jurnal-umum/{$diUtama->Uuid}/pembalik", ['Tanggal' => '2026-10-01', 'Alasan' => 'Coba balik'])->assertNotFound();
        $this->get("/kelola/akuntansi/jurnal-umum/{$diUtama->Uuid}/lampiran")->assertNotFound();

        // Tenant lain tidak melihat dokumen ini.
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->get("/kelola/akuntansi/jurnal-umum/{$diUtama->Uuid}")->assertNotFound();
        $this->post("/kelola/akuntansi/jurnal-umum/{$diUtama->Uuid}/pembalik", ['Tanggal' => '2026-10-01', 'Alasan' => 'Coba balik'])->assertNotFound();
        $this->post('/kelola/akuntansi/jurnal-umum', IsianJurnalUmum($akrual))->assertSessionHasErrors('Baris.1.UuidAkun');

        expect(DB::table('JurnalUmum')->where('IdTenant', $b['Tenant']->Id)->count())->toBe(0)
            ->and(DB::table('JurnalUmum')->where('IdTenant', $a['Tenant']->Id)->count())->toBe(4);
    });
});
