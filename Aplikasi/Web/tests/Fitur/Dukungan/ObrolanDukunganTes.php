<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Layanan\KehadiranPetugasObrolan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\Dukungan\Surel\BalasanTiketDukungan;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganBaru;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/*
 * PLT-12 (D-163): obrolan langsung = tiket berkanal `Obrolan` yang percakapannya ditampilkan waktu nyata (polling).
 * Tanpa petugas online obrolan tetap sah dan berlanjut sebagai tiket biasa.
 */

function MasukSebagaiPetugasObrolan(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function UrlObrolan(string $jalur = ''): string
{
    return BantuanPengelola::Url('/dukungan/obrolan'.$jalur);
}

/** URL absolut obrolan di back-office tenant: setelah request ke subdomain pengelola, path relatif ikut host pengelola. */
function UrlObrolanTenant(string $jalur = ''): string
{
    return rtrim((string) config('app.url'), '/').'/kelola/obrolan'.$jalur;
}

/** Tiket obrolan milik pelapor, dibaca tanpa batas tenant untuk assertion. */
function TiketObrolanUji(int $idTenant): ?TiketDukungan
{
    $konteks = app(KonteksTenant::class);
    $sebelumnya = $konteks->Ambil();
    $konteks->Atur($idTenant);

    try {
        return TiketDukungan::query()->where('Kanal', KanalTiketDukungan::Obrolan->value)->orderByDesc('Id')->first();
    } finally {
        $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
    }
}

beforeEach(function (): void {
    // Kamis 09.00 WIB: jam kerja tim dukungan.
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    Mail::fake();
    Storage::fake('local');
    Cache::flush();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanDukungan::BuatTenant();
});

describe('Keadaan layanan untuk pelapor', function (): void {
    it('tanpa petugas siap: jujur bahwa pesan menjadi tiket, dan membedakan jam kerja dari di luar jam kerja', function (): void {
        $masuk = fn () => BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant);

        $dalam = $masuk()->getJson(UrlObrolanTenant())->assertOk()->json();
        expect($dalam['Obrolan'])->toBeNull()
            ->and($dalam['Layanan']['Buka'])->toBeFalse()
            ->and($dalam['Layanan']['DalamJamKerja'])->toBeTrue()
            ->and($dalam['Layanan']['Pesan'])->toContain('tercatat sebagai tiket');

        // Sabtu 10.00 WIB.
        $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'Asia/Jakarta'));
        $luar = $masuk()->getJson(UrlObrolanTenant())->json();
        expect($luar['Layanan']['DalamJamKerja'])->toBeFalse()
            ->and($luar['Layanan']['Pesan'])->toContain('Di luar jam layanan')->toContain('08.00-17.00 WIB');
    });

    it('petugas yang siap dan masih berdetak membuat layanan "Buka"; detak yang basi tidak dihitung', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        app(KehadiranPetugasObrolan::class)->Atur($petugas->Id, true);

        $buka = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->getJson(UrlObrolanTenant())->json();
        expect($buka['Layanan']['Buka'])->toBeTrue()->and($buka['Layanan']['PetugasOnline'])->toBe(1);

        $this->travel(2)->minutes();
        $basi = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->getJson(UrlObrolanTenant())->json();
        expect($basi['Layanan']['Buka'])->toBeFalse();
    });
});

describe('Mengirim pesan obrolan', function (): void {
    it('pesan pertama membuat tiket berkanal Obrolan dengan SLA paket. Pesan berikutnya masuk ke tiket yang sama', function (): void {
        $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));

        $pertama = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)
            ->postJson(UrlObrolanTenant(), ['Isi' => 'Halo, printer struk tidak mencetak.', 'HalamanAsal' => '/kelola/kasir'])
            ->assertCreated()->json();

        $tiket = TiketObrolanUji($this->tenant->Id);
        expect($tiket)->not->toBeNull()
            ->and($tiket->Kanal)->toBe(KanalTiketDukungan::Obrolan)
            ->and($tiket->Status)->toBe(StatusTiketDukungan::Baru)
            ->and($tiket->Judul)->toStartWith('Obrolan: Halo, printer struk')
            ->and($tiket->Konteks)->toMatchArray(['Obrolan' => true, 'HalamanAsal' => '/kelola/kasir'])
            // Trial bawaan paket PRO, prioritas Normal = 8 jam kerja.
            ->and($tiket->JamSla)->toBe(8)
            ->and($pertama['Baru'])->toBeTrue()
            ->and($pertama['Obrolan']['Pesan'])->toHaveCount(1);

        $this->travel(1)->minutes();
        $kedua = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)
            ->postJson(UrlObrolanTenant(), ['Isi' => 'Sudah saya restart.'])->assertOk()->json();

        expect($kedua['Baru'])->toBeFalse()
            ->and($kedua['Obrolan']['Uuid'])->toBe($tiket->Uuid)
            ->and($kedua['Obrolan']['Pesan'])->toHaveCount(2)
            ->and(TiketDukungan::query()->withoutGlobalScopes()->where('Kanal', 'Obrolan')->count())->toBe(1);
    });

    it('pesan kosong dan terlalu panjang ditolak', function (): void {
        $masuk = fn () => BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant);

        $masuk()->postJson(UrlObrolanTenant(), ['Isi' => '   '])->assertUnprocessable();
        $masuk()->postJson(UrlObrolanTenant(), ['Isi' => str_repeat('a', (int) config('dukungan.Obrolan.MaksPanjangPesan') + 1)])->assertUnprocessable();
        expect(TiketObrolanUji($this->tenant->Id))->toBeNull();
    });

    it('polling dengan penanda "setelah" hanya mengembalikan pesan baru dan tidak pernah memuat catatan internal', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $kirim = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)
            ->postJson(UrlObrolanTenant(), ['Isi' => 'Tolong cek stok minus.'])->json();
        $uuidPertama = $kirim['Obrolan']['Pesan'][0]['Uuid'];
        $uuidTiket = $kirim['Obrolan']['Uuid'];

        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(BantuanPengelola::Url("/dukungan/tiket/{$uuidTiket}/balasan"), ['Isi' => 'Catatan: curiga retur ganda.', 'CatatanInternal' => true])->assertRedirect();
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuidTiket}/pesan"), ['Isi' => 'Halo Rina, saya cek ya.'])->assertCreated();

        $baru = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->getJson(UrlObrolanTenant('?setelah='.$uuidPertama));
        $baru = $baru->json();

        expect($baru['Obrolan']['Pesan'])->toHaveCount(1)
            ->and($baru['Obrolan']['Pesan'][0]['Isi'])->toBe('Halo Rina, saya cek ya.')
            ->and($baru['Obrolan']['Pesan'][0]['NamaPengirim'])->toContain('Tim Dukungan')
            ->and(json_encode($baru))->not->toContain('curiga retur ganda')
            ->and($baru['Obrolan']['Status'])->toBe('Ditangani');
    });

    it('izin: peran tanpa izin kelola bantuan tidak bisa mengirim, tanpa izin lihat tidak bisa membaca', function (): void {
        $kasir = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Kasir);

        BantuanDukungan::MasukSebagaiTenant($this, $kasir, $this->tenant)->getJson(UrlObrolanTenant())->assertForbidden();
        BantuanDukungan::MasukSebagaiTenant($this, $kasir, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Halo'])->assertForbidden();
        expect(TiketObrolanUji($this->tenant->Id))->toBeNull();
    });

    it('isolasi: obrolan satu pengguna dan satu tenant tidak terlihat pengguna/tenant lain', function (): void {
        ['Tenant' => $lain, 'Pengguna' => $pemilikLain] = BantuanDukungan::BuatTenant('Laundry Bersih', 'budi@laundry.id');
        BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Rahasia tenant A.'])->assertCreated();

        $admin = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Admin);
        $milikAdmin = BantuanDukungan::MasukSebagaiTenant($this, $admin, $this->tenant)->getJson(UrlObrolanTenant())->json();
        $milikLain = BantuanDukungan::MasukSebagaiTenant($this, $pemilikLain, $lain)->getJson(UrlObrolanTenant())->json();

        expect($milikAdmin['Obrolan'])->toBeNull()
            ->and($milikLain['Obrolan'])->toBeNull()
            ->and(json_encode($milikLain))->not->toContain('Rahasia tenant A');
    });

    it('mengakhiri obrolan menandai tiket Selesai. Pesan berikutnya membuka obrolan baru', function (): void {
        $masuk = fn () => BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant);
        $masuk()->postJson(UrlObrolanTenant(), ['Isi' => 'Halo.'])->assertCreated();
        $pertama = TiketObrolanUji($this->tenant->Id);

        $masuk()->postJson(UrlObrolanTenant('/akhiri'))->assertOk()->assertJsonPath('Obrolan', null);
        expect(BantuanDukungan::MuatUlang($pertama)->Status)->toBe(StatusTiketDukungan::Selesai);

        $masuk()->postJson(UrlObrolanTenant('/akhiri'))->assertNotFound();
        $masuk()->postJson(UrlObrolanTenant(), ['Isi' => 'Halo lagi.'])->assertCreated();
        expect(TiketObrolanUji($this->tenant->Id)->Id)->not->toBe($pertama->Id);
    });
});

describe('Pemberitahuan: obrolan tidak membanjiri email', function (): void {
    it('obrolan baru dengan petugas online tidak dikirimi email tiket baru, tanpa petugas tetap dikirim', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);

        BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Halo, tanpa petugas online.'])->assertCreated();
        Mail::assertSent(TiketDukunganBaru::class, 1);

        app(KehadiranPetugasObrolan::class)->Atur($petugas->Id, true);
        $admin = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Admin);
        BantuanDukungan::MasukSebagaiTenant($this, $admin, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Halo, ada petugas online.'])->assertCreated();

        Mail::assertSent(TiketDukunganBaru::class, 1);
    });

    it('balasan petugas saat pelapor sedang membaca tidak dikirim lewat email. Saat ia tidak membaca tetap dikirim', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $kirim = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Halo.'])->json();
        $uuid = $kirim['Obrolan']['Uuid'];
        // Mengirim/membaca menandai pelapor sedang membaca.
        BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->getJson(UrlObrolanTenant())->assertOk();

        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuid}/pesan"), ['Isi' => 'Balasan 1.'])->assertCreated();
        Mail::assertNotQueued(BalasanTiketDukungan::class);

        // Pelapor menutup lembar: tidak ada polling lagi sampai penanda kedaluwarsa.
        $this->travel(60)->seconds();
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuid}/pesan"), ['Isi' => 'Balasan 2.'])->assertCreated();
        Mail::assertQueued(BalasanTiketDukungan::class, 1);
    });
});

describe('Inbox petugas', function (): void {
    it('kehadiran: siap, detak memperpanjang, tidak berdetak = offline, mematikan = offline, tanpa izin tangani ditolak', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);

        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan('/kehadiran'), ['Siap' => true])->assertOk()
            ->assertJson(['Siap' => true, 'PetugasOnline' => 1]);

        $this->travel(40)->seconds();
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan('/kehadiran'), [])->assertOk()->assertJson(['Siap' => true]);
        $this->travel(40)->seconds();
        expect(app(KehadiranPetugasObrolan::class)->HitungOnline())->toBe(1);

        $this->travel(61)->seconds();
        expect(app(KehadiranPetugasObrolan::class)->HitungOnline())->toBe(0);

        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan('/kehadiran'), ['Siap' => true])->assertOk();
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan('/kehadiran'), ['Siap' => false])->assertOk()->assertJson(['Siap' => false, 'PetugasOnline' => 0]);

        // Detak tidak menyalakan petugas yang sedang tidak siap.
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan('/kehadiran'), [])->assertJson(['Siap' => false]);

        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiPetugasObrolan($this, $teknis)->postJson(UrlObrolan('/kehadiran'), ['Siap' => true])->assertForbidden();
    });

    it('daftar menampilkan hanya obrolan terbuka, yang menunggu balasan lebih dulu, dan mencatat audit akses sekali per rentang', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $lain, 'Pengguna' => $pemilikLain] = BantuanDukungan::BuatTenant('Laundry Bersih', 'budi@laundry.id');
        BantuanDukungan::BuatTiket($this->tenant, $this->pemilik, 'Tiket biasa bukan obrolan');

        $a = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Pesan dari Kopi Nusantara.'])->json();
        $this->travel(1)->minutes();
        BantuanDukungan::MasukSebagaiTenant($this, $pemilikLain, $lain)->postJson(UrlObrolanTenant(), ['Isi' => 'Pesan dari Laundry Bersih.'])->assertCreated();
        // Petugas membalas A sehingga A tidak lagi menunggu balasan.
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$a['Obrolan']['Uuid']}/pesan"), ['Isi' => 'Siap, saya bantu.'])->assertCreated();

        $sebelum = LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->count();
        $hasil = MasukSebagaiPetugasObrolan($this, $petugas)->getJson(UrlObrolan('/daftar'))->assertOk()->json();
        MasukSebagaiPetugasObrolan($this, $petugas)->getJson(UrlObrolan('/daftar'))->assertOk();
        MasukSebagaiPetugasObrolan($this, $petugas)->getJson(UrlObrolan('/daftar'))->assertOk();

        expect($hasil['Obrolan'])->toHaveCount(2)
            ->and($hasil['Obrolan'][0]['NamaTenant'])->toBe('Laundry Bersih')
            ->and($hasil['Obrolan'][0]['MenungguBalasan'])->toBeTrue()
            ->and($hasil['Obrolan'][1]['NamaTenant'])->toBe('Kopi Nusantara')
            ->and($hasil['Obrolan'][1]['MenungguBalasan'])->toBeFalse()
            ->and($hasil['Obrolan'][1]['Petugas'])->toBe($petugas->Nama)
            ->and(collect($hasil['Obrolan'])->pluck('Judul')->implode(' '))->not->toContain('Tiket biasa')
            // Tiga polling, satu audit.
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->count())->toBe($sebelum + 1);
    });

    it('membalas mengisi respons pertama, menugaskan pembalas, dan mengubah Baru menjadi Ditangani. Menyelesaikan menutup obrolan', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $kirim = BantuanDukungan::MasukSebagaiTenant($this, $this->pemilik, $this->tenant)->postJson(UrlObrolanTenant(), ['Isi' => 'Halo.'])->json();
        $uuid = $kirim['Obrolan']['Uuid'];

        $this->travel(2)->minutes();
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuid}/pesan"), ['Isi' => 'Halo, ada yang bisa dibantu?'])->assertCreated()
            ->assertJsonPath('Pesan.1.Isi', 'Halo, ada yang bisa dibantu?');

        $tiket = BantuanDukungan::MuatUlang(TiketObrolanUji($this->tenant->Id));
        expect($tiket->Status)->toBe(StatusTiketDukungan::Ditangani)
            ->and($tiket->IdPenanggungJawab)->toBe($petugas->Id)
            ->and($tiket->ResponsPertamaPada)->not->toBeNull();

        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuid}/selesai"))->assertOk()->assertJsonPath('Status', 'Selesai')->assertJsonPath('BisaDibalas', false);

        // Obrolan yang sudah selesai tidak bisa dibalas lagi dari inbox: galat JSON seragam.
        MasukSebagaiPetugasObrolan($this, $petugas)->postJson(UrlObrolan("/{$uuid}/pesan"), ['Isi' => 'Masih di sini?'])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'P-09');
    });

    it('tiket biasa tidak bisa dibuka lewat inbox obrolan', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $tiket = BantuanDukungan::BuatTiket($this->tenant, $this->pemilik);

        MasukSebagaiPetugasObrolan($this, $petugas)->getJson(UrlObrolan("/{$tiket->Uuid}/pesan"))->assertNotFound();
    });

    it('peran tanpa izin lihat tiket tidak bisa membuka inbox maupun membaca obrolan', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);

        MasukSebagaiPetugasObrolan($this, $teknis)->get(UrlObrolan())->assertForbidden();
        MasukSebagaiPetugasObrolan($this, $teknis)->getJson(UrlObrolan('/daftar'))->assertForbidden();
    });
});
