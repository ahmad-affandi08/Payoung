<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLangganan;
use App\Domain\Pengelola\Tagihan\Surel\PembayaranLanggananDiterima;
use App\Domain\Pengelola\Tagihan\Surel\PembayaranLanggananDitolak;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

function MasukTagihanPengelola(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** Owner membuat tagihan lalu mengunggah bukti; kembalikan pembayaran yang menunggu verifikasi. */
function BayarTagihanUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, string $paket = 'PRO', string $siklus = 'Bulanan'): PembayaranLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => $paket, 'Siklus' => $siklus])
        ->assertSessionHasNoErrors();

    return UnggahBuktiUji($tes, $pemilik, $tenant, TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail());
}

function UnggahBuktiUji(TestCase $tes, Pengguna $pemilik, Tenant $tenant, TagihanLangganan $tagihan): PembayaranLangganan
{
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    // Bersihkan sesi tenant agar request berikutnya ke subdomain pengelola bersih.
    $tes->flushSession();

    return $pembayaran;
}

function LanggananTagihanUji(Tenant $tenant): Langganan
{
    return Langganan::query()->where('IdTenant', $tenant->Id)->sole();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    $this->keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
});

describe('Hak akses tagihan (§19.3)', function (): void {
    it('Keuangan & Super Admin punya izin tagihan; Teknis, Konten & Legal, Mitra, Dukungan, dan Analis tidak', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);

        expect($this->keuangan->PunyaIzin(IzinPengelola::TagihanVerifikasi))->toBeTrue()
            ->and($superAdmin->PunyaIzin(IzinPengelola::TagihanVerifikasi))->toBeTrue();

        foreach ([PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::MitraPenjualan, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Analis] as $peran) {
            expect($peran->AmbilIzin())->not->toContain(IzinPengelola::TagihanLihat)->not->toContain(IzinPengelola::TagihanVerifikasi);
        }

        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukTagihanPengelola($this, $teknis)->get(BantuanPengelola::Url('/tagihan'))->assertForbidden();
        $this->get(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/bukti"))->assertForbidden();
        $this->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => '276390'])->assertForbidden();
    });

    it('rute tagihan pengelola tidak terbuka di domain tenant', function (): void {
        $this->actingAs($this->keuangan, 'pengelola')->get('/tagihan')->assertNotFound();
    });
});

describe('Antrean verifikasi (P-08 langkah 3)', function (): void {
    it('Keuangan melihat antrean lintas tenant beserta nama usaha, nomor tagihan, dan jumlah', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);

        MasukTagihanPengelola($this, $this->keuangan)->get(BantuanPengelola::Url('/tagihan'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Tagihan/Daftar')
                ->has('Antrean', 1)
                ->where('Antrean.0.Uuid', $pembayaran->Uuid)
                ->where('Antrean.0.NamaTenant', 'Kopi Nusantara')
                ->where('Antrean.0.NomorTagihan', 'INV/2026/09/000001')
                ->where('Antrean.0.Jumlah', '276390.00')
                ->where('Ringkasan.MenungguVerifikasi', 1)
                ->where('Tagihan.Data.0.NamaTenant', 'Kopi Nusantara'));

        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        $this->get(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Tagihan/Detail')
                ->where('Tagihan.Total', '276390.00')
                ->where('Pembayaran.0.Status', 'Menunggu'));
    });

    it('TabelData (D-16): JSON di URL yang sama dengan cari, filter status, dan Meta', function (): void {
        BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        MasukTagihanPengelola($this, $this->keuangan);

        $this->getJson(BantuanPengelola::Url('/tagihan?cari=Nusantara&saring[Status]='.$tagihan->Status->value))
            ->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Data.0.Uuid', $tagihan->Uuid)
            ->assertJsonPath('Data.0.NamaTenant', 'Kopi Nusantara');

        $this->getJson(BantuanPengelola::Url('/tagihan?cari=TidakAda'))->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->getJson(BantuanPengelola::Url('/tagihan?saring[TerbitPada]=2000-01-01..2000-01-31'))->assertOk()->assertJsonPath('Meta.Total', 0);
    });

    it('membuka bukti transfer dari disk privat tercatat di audit dengan IdTenant', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);

        MasukTagihanPengelola($this, $this->keuangan)->get(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/bukti"))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(BantuanPengelola::Url('/tagihan/pembayaran/01JTIDAKADA000000000000000/bukti'))->assertNotFound();

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.bukti.lihat')->sole();
        expect($log->IdTenant)->toBe($this->tenant->Id)
            ->and($log->IdPenggunaPengelola)->toBe($this->keuangan->Id);
    });
});

describe('Terima pembayaran → tagihan Lunas → langganan Aktif (BR-00.7)', function (): void {
    it('Trial → Aktif: paket, siklus, dan periode sebulan diperbarui. Audit & email Owner', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $this->travel(2)->hours();

        MasukTagihanPengelola($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => '276390', 'Catatan' => 'Mutasi BCA 23/09'])
            ->assertSessionHasNoErrors();

        $pembayaran->refresh();
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        $langganan = LanggananTagihanUji($this->tenant);
        expect($pembayaran->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($pembayaran->IdPenggunaPengelolaVerifikator)->toBe($this->keuangan->Id)
            ->and($pembayaran->JumlahDiterima)->toBe('276390.00')
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($tagihan->DibayarPada?->equalTo(now()))->toBeTrue()
            ->and($tagihan->PeriodeMulai?->equalTo(now()))->toBeTrue()
            ->and($tagihan->PeriodeSelesai?->equalTo(now()->addMonthNoOverflow()))->toBeTrue()
            ->and($tagihan->MulaiLanggananPaket?->toDateString())->toBe('2026-09-23')
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->Paket->Kode)->toBe('PRO')
            ->and($langganan->SiklusTagihan)->toBe(SiklusTagihan::Bulanan)
            ->and($langganan->PeriodeSelesai?->equalTo(now()->addMonthNoOverflow()))->toBeTrue();

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.pembayaran.terima')->sole();
        expect($log->IdTenant)->toBe($this->tenant->Id)
            ->and($log->Alasan)->toBe('Mutasi BCA 23/09')
            ->and($log->NilaiLama['Langganan']['Status'] ?? null)->toBe('Trial')
            ->and($log->NilaiBaru['Langganan']['Status'] ?? null)->toBe('Aktif');
        Mail::assertQueued(PembayaranLanggananDiterima::class, fn (PembayaranLanggananDiterima $surel) => $surel->hasTo('rina@kopinusantara.id')
            && $surel->nomorTagihan === 'INV/2026/09/000001');
    });

    it('menolak Terima bila jumlah di rekening berbeda dari total', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);

        MasukTagihanPengelola($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => '220000'])
            ->assertSessionHasErrors('JumlahDiterima');

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and(LanggananTagihanUji($this->tenant)->Status)->toBe(StatusLangganan::Trial);
    });

    it('idempoten: pembayaran yang sudah diterima tidak bisa diterima atau ditolak lagi', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        MasukTagihanPengelola($this, $this->keuangan);
        $url = BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}");

        $this->post("{$url}/terima", ['JumlahDiterima' => '276390'])->assertSessionHasNoErrors();
        $periodeSelesai = LanggananTagihanUji($this->tenant)->PeriodeSelesai;
        $this->post("{$url}/terima", ['JumlahDiterima' => '276390'])->assertSessionHasErrors(['Umum' => 'Pembayaran ini sudah Diterima.']);
        $this->post("{$url}/tolak", ['Alasan' => 'Salah klik, seharusnya ditolak'])->assertSessionHasErrors('Umum');

        expect(LanggananTagihanUji($this->tenant)->PeriodeSelesai?->equalTo($periodeSelesai))->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'like', 'tagihan.pembayaran.%')->count())->toBe(1);
        Mail::assertQueuedCount(1);
    });

    it('race dua verifikator: yang kedua memeriksa ulang status di bawah kunci dan ditolak', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $keuanganLain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        // Keduanya membuka halaman saat masih Menunggu; verifikator pertama menang.
        $aksi = app(TerimaPembayaranLangganan::class);
        $aksi->Jalankan($this->keuangan, $pembayaran->Uuid, '276390');

        expect(fn () => app(TerimaPembayaranLangganan::class)->Jalankan($keuanganLain, $pembayaran->Uuid, '276390'))
            ->toThrow(PelanggaranAturanBisnis::class, 'Pembayaran ini sudah Diterima.');
        expect($pembayaran->refresh()->IdPenggunaPengelolaVerifikator)->toBe($this->keuangan->Id)
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->sole()->Status)->toBe(StatusTagihanLangganan::Lunas);
    });
});

describe('Tolak pembayaran', function (): void {
    it('alasan wajib. Tagihan tetap terbuka, Owner diberi email, dan bisa mengunggah bukti baru', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        MasukTagihanPengelola($this, $this->keuangan);
        $url = BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/tolak");

        $this->post($url, ['Alasan' => 'kurang'])->assertSessionHasErrors('Alasan');
        $this->post($url, ['Alasan' => 'Dana belum masuk ke rekening BCA per 23/09 pukul 12.00.'])->assertSessionHasNoErrors();

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Ditolak)
            ->and($pembayaran->AlasanTolak)->toBe('Dana belum masuk ke rekening BCA per 23/09 pukul 12.00.')
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->sole()->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.pembayaran.tolak')->sole()->IdTenant)->toBe($this->tenant->Id);
        Mail::assertQueued(PembayaranLanggananDitolak::class, fn (PembayaranLanggananDitolak $surel) => $surel->hasTo('rina@kopinusantara.id'));

        $baru = UnggahBuktiUji($this, $this->pemilik, $this->tenant, TagihanLangganan::query()->withoutGlobalScopes()->sole());
        expect($baru->Id)->not->toBe($pembayaran->Id)
            ->and($baru->Status)->toBe(StatusPembayaranLangganan::Menunggu);
    });
});

describe('Perpanjangan, tunggakan, dan grandfathering (BR-P04.1)', function (): void {
    it('perpanjangan saat Aktif menyambung dari akhir periode berjalan', function (): void {
        $pertama = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $pertama->Uuid, '276390');
        $akhirPertama = LanggananTagihanUji($this->tenant)->PeriodeSelesai;
        $this->travel(20)->days();

        $kedua = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $tagihanKedua = TagihanLangganan::query()->withoutGlobalScopes()->findOrFail($kedua->IdTagihanLangganan);
        expect($tagihanKedua->Jenis->value)->toBe('Perpanjangan')
            ->and($tagihanKedua->JatuhTempoPada->equalTo($akhirPertama))->toBeTrue();

        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $kedua->Uuid, '276390');
        $langganan = LanggananTagihanUji($this->tenant);
        expect($langganan->PeriodeMulai?->equalTo($akhirPertama))->toBeTrue()
            ->and($langganan->PeriodeSelesai?->equalTo($akhirPertama?->copy()->addMonthNoOverflow()))->toBeTrue()
            ->and($tagihanKedua->refresh()->MulaiLanggananPaket?->toDateString())->toBe('2026-09-23');
    });

    it('Tertunggak → Aktif lewat pembayaran tagihan yang sudah lewat jatuh tempo. Periode tetap menyambung', function (): void {
        $pertama = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $pertama->Uuid, '276390');
        $akhirPertama = LanggananTagihanUji($this->tenant)->PeriodeSelesai;
        $this->travel(25)->days();
        $kedua = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $this->travel(8)->days();

        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        expect(LanggananTagihanUji($this->tenant)->Status)->toBe(StatusLangganan::Tertunggak)
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->findOrFail($kedua->IdTagihanLangganan)->Status)->toBe(StatusTagihanLangganan::JatuhTempo);

        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $kedua->Uuid, '276390');
        $langganan = LanggananTagihanUji($this->tenant);
        expect($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->PeriodeMulai?->equalTo($akhirPertama))->toBeTrue();
    });

    it('harga baru yang tidak diterapkan ke pelanggan lama: perpanjangan tetap harga lama, tenant baru harga baru', function (): void {
        $pertama = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $pertama->Uuid, '276390');

        HargaPaket::query()->create([
            'IdPaket' => Paket::query()->where('Kode', 'PRO')->sole()->Id,
            'HargaBulanan' => '249000',
            'HargaTahunan' => '2390400',
            'BerlakuMulai' => '2026-10-01',
            'TerapkanKePelangganLama' => false,
            'Status' => StatusDataMaster::Terbit,
        ]);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));

        $perpanjangan = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        ['Tenant' => $tenantBaru, 'Pengguna' => $pemilikBaru] = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081298765432', 'Toko Budi');
        $baru = BayarTagihanUji($this, $pemilikBaru, $tenantBaru);

        expect(TagihanLangganan::query()->withoutGlobalScopes()->findOrFail($perpanjangan->IdTagihanLangganan)->Subtotal)->toBe('249000.00')
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->findOrFail($baru->IdTagihanLangganan)->Subtotal)->toBe('249000.00');
    });
});

describe('Penangguhan manual tidak dicabut lewat tagihan (BR-P07.4 × BR-P08.9)', function (): void {
    it('Keuangan tidak bisa menerima pembayaran tenant yang ditangguhkan manual; Owner tidak bisa membuat tagihan baru', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $langganan = LanggananTagihanUji($this->tenant);
        $asal = $langganan->Status;
        $langganan->update(['Status' => StatusLangganan::Ditangguhkan, 'StatusSebelumDitangguhkan' => $asal]);

        expect(fn () => app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $pembayaran->Uuid, '276390'))
            ->toThrow(PelanggaranAturanBisnis::class, 'ditangguhkan manual');
        expect(LanggananTagihanUji($this->tenant)->Status)->toBe(StatusLangganan::Ditangguhkan)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusTagihanLangganan::Dibatalkan->value]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasErrors(['Umum']);
    });

    it('penangguhan karena tunggakan tetap pulih lewat pembayaran yang diterima', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update([
            'Status' => StatusLangganan::Ditangguhkan->value,
            'StatusSebelumDitangguhkan' => null,
        ]);

        app(TerimaPembayaranLangganan::class)->Jalankan($this->keuangan, $pembayaran->Uuid, '276390');

        expect(LanggananTagihanUji($this->tenant)->Status)->toBe(StatusLangganan::Aktif);
    });
});

describe('Terima massal antrean verifikasi', function (): void {
    it('menerima pembayaran terpilih setelah dicentang sudah dicocokkan, yang sudah tidak menunggu dilewati dengan alasan; Dukungan ditolak', function (): void {
        $pembayaran = BayarTagihanUji($this, $this->pemilik, $this->tenant);
        $alamat = BantuanPengelola::Url('/tagihan/pembayaran/terima-massal');
        $masuk = fn () => MasukTagihanPengelola($this, $this->keuangan);

        // Tanpa centang konfirmasi, atau tanpa pilihan: ditolak dan tidak ada yang berubah.
        $masuk()->post($alamat, ['Uuid' => [$pembayaran->Uuid]])->assertSessionHasErrors('SudahDicocokkan');
        $masuk()->post($alamat, ['Uuid' => [], 'SudahDicocokkan' => true])->assertSessionHasErrors('Uuid');
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        $masuk()->post($alamat, ['Uuid' => [$pembayaran->Uuid, '01J9ZZZZZZZZZZZZZZZZZZZZZZ'], 'SudahDicocokkan' => true, 'Catatan' => 'Mutasi BCA 23/09'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', fn (string $pesan): bool => str_starts_with($pesan, '1 pembayaran diterima, tagihan lunas dan langganan aktif.') && str_contains($pesan, '1 dilewati: Pembayaran tidak lagi menunggu verifikasi.'));
        $pembayaran->refresh();
        expect($pembayaran->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($pembayaran->JumlahDiterima)->toBe($pembayaran->Jumlah)
            ->and($pembayaran->IdPenggunaPengelolaVerifikator)->toBe($this->keuangan->Id)
            ->and(LanggananTagihanUji($this->tenant)->Status)->toBe(StatusLangganan::Aktif)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.pembayaran.terima')->count())->toBe(1);

        // Diulang: tidak ada yang diterima, jadi galat umum (bukan pesan berhasil).
        $masuk()->post($alamat, ['Uuid' => [$pembayaran->Uuid], 'SudahDicocokkan' => true])->assertSessionHasErrors('Umum');

        MasukTagihanPengelola($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan))
            ->post($alamat, ['Uuid' => [$pembayaran->Uuid], 'SudahDicocokkan' => true])->assertForbidden();
    });
});
