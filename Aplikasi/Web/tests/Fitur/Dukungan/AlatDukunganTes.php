<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\KategoriAksesDarurat;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Dukungan\Model\HasilAlatDukungan;
use App\Domain\Dukungan\Model\LogPerangkatDiunggah;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Dukungan\Aksi\BukaAksesDarurat;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Penjualan\Enum\JenisPesanKeluar;
use App\Domain\Penjualan\Enum\KanalPesanKeluar;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Model\PesanKeluar;
use App\Domain\Penjualan\Tugas\KirimStrukDigitalTugas;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Dukungan\BantuanAksesDukungan as Bantuan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/* PGL-16 bagian 2 / P-09: alat bantu dukungan di konsol (BR-P09.2, BR-P09.3). */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * Tenant berizin: Owner memberi izin (cakupan `$cakupan`) dan petugas Dukungan siap memakai konsol.
 *
 * @return array{0: array{Tenant: Tenant, Pemilik: Pengguna}, 1: PenggunaPengelola}
 */
function SiapkanAlatUji(TestCase $tes, string $cakupan = 'BacaUbah'): array
{
    $tenant = Bantuan::BuatTenant();
    Bantuan::OwnerBeriIzin($tes, $tenant, ['Cakupan' => $cakupan])->assertSessionHasNoErrors();

    return [$tenant, Bantuan::BuatPetugas()];
}

/** Petugas membuka konsol (peramban baru, 2FA baru saja dimasukkan). */
function KonsolPetugas(TestCase $tes, PenggunaPengelola $petugas, bool $duaFaktorBaru = true): TestCase
{
    Bantuan::LupakanLogin($tes);

    return $tes->actingAs($petugas, 'pengelola')->withSession($duaFaktorBaru ? BantuanPengelola::SesiTerverifikasi() : BantuanPengelola::SesiPerangkatTepercaya());
}

function UrlAlat(Tenant $tenant, string $jalur): string
{
    return BantuanPengelola::Url("/tenant/{$tenant->Uuid}/alat-dukungan/{$jalur}");
}

/** @return list<HasilAlatDukungan> */
function HasilAlatTenant(int $idTenant, ?AlatDukungan $alat = null): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return HasilAlatDukungan::query()->when($alat !== null, fn ($k) => $k->where('Alat', $alat->value))->orderBy('Id')->get()->all();
}

function AktifkanWhatsappAlat(Tenant $tenant): void
{
    OverrideTenant::query()->create([
        'IdTenant' => $tenant->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji alat bantu dukungan',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    BantuanOrganisasi::AturKonteks($tenant->Id);
}

describe('BR-P09.2: alat hanya jalan di bawah izin akses yang berlaku', function (): void {
    it('tanpa izin dari Owner, semua alat ditolak (AksesTidakAda) dan tidak ada yang tercatat', function (): void {
        $tenant = Bantuan::BuatTenant();
        $petugas = Bantuan::BuatPetugas();

        KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'AksesTidakAda');
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], 'saldo-stok/bangun-ulang'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'AksesTidakAda');

        expect(HasilAlatTenant($tenant['Tenant']->Id))->toBe([]);
    });

    it('peran tanpa izin alat (Keuangan) ditolak konsol', function (): void {
        [$tenant] = SiapkanAlatUji($this);

        KonsolPetugas($this, Bantuan::BuatPetugas(PeranPengelolaBawaan::Keuangan))
            ->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))->assertForbidden();
    });

    it('Baca saja: alat baca jalan, alat yang mengubah ditolak "Izin akses hanya baca" dan percobaannya tercatat Gagal (BR-P09.3)', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this, 'BacaSaja');
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))->assertOk()
            ->assertJsonPath('Perangkat.0.Uuid', $perangkat->Uuid);

        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'IzinHanyaBaca')->assertJsonPath('Galat.Pesan', 'Izin akses hanya baca.');

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(Perangkat::query()->whereKey($perangkat->Id)->value('DicabutPada'))->toBeNull();
        // Ditolak sebelum alat jalan, jadi tidak ada baris hasil; alat baca juga tidak menulis baris hasil.
        expect(HasilAlatTenant($tenant['Tenant']->Id))->toBe([]);
    });

    it('izin yang dicabut Owner langsung menutup alat', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        $akses = Bantuan::IzinAktif($tenant['Tenant']->Id);

        Bantuan::OwnerCabut($this, $tenant, $akses->Uuid)->assertSessionHasNoErrors();

        KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'AksesTidakAda');
    });

    it('akses darurat hanya bisa dipakai Super Admin yang membukanya', function (): void {
        $tenant = Bantuan::BuatTenant();
        $pembuka = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $lain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        app(BukaAksesDarurat::class)->Jalankan($pembuka, $tenant['Tenant']->Id, KategoriAksesDarurat::InsidenKeamanan, 'Dugaan kebocoran token perangkat kasir toko ini.', 2, CakupanAksesDukungan::BacaUbah);

        KonsolPetugas($this, $lain)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'AksesDaruratBukanMilikAnda');
        KonsolPetugas($this, $pembuka)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))->assertOk();
    });
});

describe('Status perangkat & antrean kirim', function (): void {
    it('menandai perangkat dengan antrean tertunda lama dan tidak membocorkan perangkat tenant lain', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this, 'BacaSaja');
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id, nama: 'Kasir Depan');
        ['Perangkat' => $milikLain] = BantuanPerangkat::BuatDanAktifkan($this, BantuanOrganisasi::BuatTenant('Toko Lain')['Tenant']->Id, nama: 'Kasir Toko Lain');
        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        Perangkat::query()->whereKey($perangkat->Id)->update(['JumlahOutboxTertunda' => 7, 'TerakhirAktifPada' => now()->subHours(5)]);

        $respons = KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'perangkat'))->assertOk();

        $respons->assertJsonCount(1, 'Perangkat')
            ->assertJsonPath('Perangkat.0.JumlahOutboxTertunda', 7)
            ->assertJsonPath('Perangkat.0.Status', 'Aktif');
        expect($respons->json('Perangkat.0.Perhatian'))->toContain('7 item belum terkirim')
            ->and(json_encode($respons->json()))->not->toContain('Kasir Toko Lain');
    });
});

describe('BR-P09.3: cabut perangkat', function (): void {
    it('mencabut perangkat dan mencatat sebelum/sesudah di hasil, audit tenant bertanda pelaku pengelola, dan audit pengelola', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertForbidden();
        $hasil = HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::CabutPerangkat);
        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]->Status)->toBe(StatusHasilAlatDukungan::Berhasil)
            ->and($hasil[0]->NamaPetugas)->toBe($petugas->Nama)
            ->and($hasil[0]->Sebelum['Status'])->toBe('Aktif')
            ->and($hasil[0]->Sesudah['Status'])->toBe('Dicabut')
            ->and($hasil[0]->Parameter)->toBe(['UuidPerangkat' => $perangkat->Uuid]);

        $audit = LogAudit::query()->where('Peristiwa', 'dukungan.alat')->where('IdTenant', $tenant['Tenant']->Id)->sole();
        expect($audit->IdPenggunaPengelola)->toBe($petugas->Id)
            ->and(LogAudit::query()->where('Peristiwa', 'perangkat.cabut')->count())->toBe(1)
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.alat.CabutPerangkat')->where('IdPenggunaPengelola', $petugas->Id)->count())->toBe(1);
    });

    it('mencabut lagi perangkat yang sudah dicabut = TanpaPerubahan. Perangkat tenant lain tidak ditemukan (isolasi tenant)', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);
        ['Perangkat' => $milikLain] = BantuanPerangkat::BuatDanAktifkan($this, BantuanOrganisasi::BuatTenant('Toko Lain')['Tenant']->Id);

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "perangkat/{$milikLain->Uuid}/cabut"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PerangkatTidakDitemukan');

        $hasil = HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::CabutPerangkat);
        expect(array_map(fn (HasilAlatDukungan $h): string => $h->Status->value, $hasil))->toBe(['Berhasil', 'TanpaPerubahan', 'Gagal']);
        BantuanOrganisasi::AturKonteks((int) $milikLain->IdTenant);
        expect(Perangkat::query()->whereKey($milikLain->Id)->value('DicabutPada'))->toBeNull();
    });

    it('cabut perangkat meminta 2FA baru bila kode terakhir sudah lama (D-42)', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        KonsolPetugas($this, $petugas, duaFaktorBaru: false)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))
            ->assertRedirectToRoute('pengelola.dua-faktor.konfirmasi');

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(Perangkat::query()->whereKey($perangkat->Id)->value('DicabutPada'))->toBeNull();
    });
});

describe('Minta dan baca log perangkat', function (): void {
    it('permintaan ditandai, muncul di konfigurasi-aplikasi, log yang diunggah difilter dan bisa dibaca dukungan', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('PermintaanLog.Diminta', false);
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))->assertRedirect();

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('PermintaanLog.Diminta', true);

        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => [
            '2026-10-12 09:58:01 [Galat] sinkron gagal untuk kasir budi@toko.id',
            '2026-10-12 09:59:12 [Info] hubungi 0812-3456-7890 bila macet',
        ]])->assertOk()->assertJsonPath('Diterima', 2);

        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('PermintaanLog.Diminta', false);

        $baca = KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/log"))->assertOk();
        expect($baca->json('Log.Isi'))->toContain('sinkron gagal untuk kasir [disamarkan]')->toContain('hubungi [disamarkan] bila macet')
            ->not->toContain('budi@toko.id')->not->toContain('3456');

        $hasil = HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::MintaLogPerangkat);
        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]->Sebelum['MintaLogPada'])->toBeNull()
            ->and($hasil[0]->Sesudah['MintaLogPada'])->not->toBeNull();
    });

    it('perangkat tidak bisa mengunggah log tanpa diminta, dan satu permintaan hanya satu unggahan (409 LogTidakDiminta)', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => ['baris liar']])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'LogTidakDiminta');

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))->assertRedirect();
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => ['baris pertama']])->assertOk();
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => ['baris kedua']])->assertStatus(409);

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(LogPerangkatDiunggah::query()->count())->toBe(1);
    });

    it('meminta dua kali sebelum dijawab = TanpaPerubahan. Permintaan kedaluwarsa setelah 7 hari', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))->assertRedirect();
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))->assertRedirect();
        expect(array_map(fn (HasilAlatDukungan $h): string => $h->Status->value, HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::MintaLogPerangkat)))->toBe(['Berhasil', 'TanpaPerubahan']);

        $this->travel(8)->days();
        $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('PermintaanLog.Diminta', false);
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => ['terlambat']])->assertStatus(409);
    });

    it('membaca log perangkat yang belum mengunggah = LogBelumAda. Perangkat dicabut tidak bisa diminta', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);

        KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/log"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'LogBelumAda');

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PerangkatTidakAktif');
    });

    it('perintah dukungan:bersihkan-log-perangkat menghapus log lebih dari 30 hari saja', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/minta-log"))->assertRedirect();
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/log', ['Baris' => ['log lama']])->assertOk();

        $this->travel(29)->days();
        $this->artisan('dukungan:bersihkan-log-perangkat')->expectsOutputToContain('0 log perangkat dihapus.')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('dukungan:bersihkan-log-perangkat')->expectsOutputToContain('1 log perangkat dihapus.')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(LogPerangkatDiunggah::query()->count())->toBe(0);
    });
});

describe('BR-P09.3: bangun ulang saldo stok', function (): void {
    it('memperbaiki saldo yang menyimpang dari buku stok dan mencatat jumlah perbedaan sebelum dan sesudah', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $tenant = ['Tenant' => $t['Tenant'], 'Pemilik' => $t['Pemilik']];
        Bantuan::OwnerBeriIzin($this, $tenant, ['Cakupan' => 'BacaUbah'])->assertSessionHasNoErrors();
        $petugas = Bantuan::BuatPetugas();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        BantuanPersediaan::TulisMutasiLangsung($produk['Stok']->Id, $t['Gudang']->Id, '10', '12345.68');
        BantuanPersediaan::TulisMutasiLangsung($produk['Stok']->Id, $t['Gudang']->Id, '-3', '-3703.70');
        SaldoStok::query()->where('IdProduk', $produk['Stok']->Id)->update(['JumlahTersedia' => '99.0000', 'NilaiPersediaan' => '1.00']);

        KonsolPetugas($this, $petugas)->post(UrlAlat($t['Tenant'], 'saldo-stok/bangun-ulang'))->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $saldo = SaldoStok::query()->where('IdProduk', $produk['Stok']->Id)->sole();
        expect((string) $saldo->JumlahTersedia)->toBe('7.0000');
        $hasil = HasilAlatTenant($t['Tenant']->Id, AlatDukungan::BangunUlangSaldoStok);
        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]->Status)->toBe(StatusHasilAlatDukungan::Berhasil)
            ->and($hasil[0]->Sebelum['JumlahPerbedaan'])->toBeGreaterThan(0)
            ->and($hasil[0]->Sesudah['JumlahPerbedaan'])->toBe(0)
            ->and($hasil[0]->Sesudah['JumlahPasanganDiperbaiki'])->toBe(1);

        // Sudah benar: dijalankan lagi tidak mengubah apa pun.
        KonsolPetugas($this, $petugas)->post(UrlAlat($t['Tenant'], 'saldo-stok/bangun-ulang'))->assertRedirect();
        $hasil = HasilAlatTenant($t['Tenant']->Id, AlatDukungan::BangunUlangSaldoStok);
        expect($hasil[1]->Status)->toBe(StatusHasilAlatDukungan::TanpaPerubahan);
    });

    it('meminta 2FA baru dan tidak menyentuh buku stok (MutasiStok tetap)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $tenant = ['Tenant' => $t['Tenant'], 'Pemilik' => $t['Pemilik']];
        Bantuan::OwnerBeriIzin($this, $tenant, ['Cakupan' => 'BacaUbah'])->assertSessionHasNoErrors();
        $petugas = Bantuan::BuatPetugas();

        KonsolPetugas($this, $petugas, duaFaktorBaru: false)->post(UrlAlat($t['Tenant'], 'saldo-stok/bangun-ulang'))
            ->assertRedirectToRoute('pengelola.dua-faktor.konfirmasi');
        expect(HasilAlatTenant($t['Tenant']->Id))->toBe([]);
    });
});

describe('BR-P09.3: bangun ulang ringkasan harian', function (): void {
    it('menghitung ulang dari dokumen sumber: baris ringkasan tanpa dokumen dihapus, hasil mencatat sebelum/sesudah', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $outlet = Outlet::query()->orderBy('Id')->firstOrFail();
        RingkasanPenjualanHarian::query()->create([
            'IdOutlet' => $outlet->Id, 'TanggalBisnis' => '2026-10-10',
            'Kotor' => '5000000.00', 'Diskon' => '0.00', 'Retur' => '0.00', 'Bersih' => '5000000.00', 'Pajak' => '0.00', 'BiayaLayanan' => '0.00', 'Hpp' => '0.00',
            'JumlahTransaksi' => 40, 'JumlahRetur' => 0, 'JumlahVoid' => 0, 'PerMetodeBayar' => [], 'PerKanal' => [], 'DihitungPada' => now()->subDays(2),
        ]);

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], 'ringkasan-harian/bangun-ulang'), ['Dari' => '2026-10-09', 'Sampai' => '2026-10-11'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(RingkasanPenjualanHarian::query()->count())->toBe(0);
        $hasil = HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::BangunUlangRingkasanHarian);
        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]->Status)->toBe(StatusHasilAlatDukungan::Berhasil)
            ->and($hasil[0]->Sebelum)->toBe(['JumlahBaris' => 1, 'TotalBersih' => '5000000.00', 'TotalTransaksi' => 40])
            ->and($hasil[0]->Sesudah)->toBe(['JumlahBaris' => 0, 'TotalBersih' => '0.00', 'TotalTransaksi' => 0])
            ->and($hasil[0]->Parameter)->toBe(['Dari' => '2026-10-09', 'Sampai' => '2026-10-11']);
    });

    it('menolak rentang terbalik, lebih dari 31 hari, dan tanggal di masa depan', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        $url = UrlAlat($tenant['Tenant'], 'ringkasan-harian/bangun-ulang');

        KonsolPetugas($this, $petugas)->postJson($url, ['Dari' => '2026-10-11', 'Sampai' => '2026-10-09'])->assertUnprocessable()->assertJsonValidationErrors('Sampai');
        KonsolPetugas($this, $petugas)->postJson($url, ['Dari' => '2026-08-01', 'Sampai' => '2026-10-01'])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'RentangTerlaluPanjang');
        KonsolPetugas($this, $petugas)->postJson($url, ['Dari' => '2026-10-12', 'Sampai' => '2026-10-20'])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'TanggalDiMasaDepan');
        KonsolPetugas($this, $petugas)->postJson($url, ['Dari' => 'kemarin', 'Sampai' => '2026-10-11'])->assertUnprocessable()->assertJsonValidationErrors('Dari');
    });
});

describe('Kirim ulang pesan WhatsApp & email verifikasi', function (): void {
    /** @return array{0: PesanKeluar, 1: array{Tenant: Tenant, Pemilik: Pengguna}, 2: PenggunaPengelola} */
    function SiapkanPesanGagal(TestCase $tes, bool $whatsappAktif = true): array
    {
        [$tenant, $petugas] = SiapkanAlatUji($tes);
        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);

        if ($whatsappAktif) {
            AktifkanWhatsappAlat($tenant['Tenant']);
        }

        $pesan = PesanKeluar::query()->create([
            'Uuid' => (string) Str::ulid(),
            'IdOutlet' => Outlet::query()->orderBy('Id')->firstOrFail()->Id,
            'Kanal' => KanalPesanKeluar::Whatsapp,
            'Jenis' => JenisPesanKeluar::StrukDigital,
            'IdReferensi' => 9001,
            'Tujuan' => '6281234567890',
            'Status' => StatusPesanKeluar::Gagal,
            'PesanGalat' => 'Penyedia WhatsApp menolak pesan.',
            'Percobaan' => 3,
        ]);

        return [$pesan, $tenant, $petugas];
    }

    it('daftar kirim ulang menyamarkan nomor. Kirim ulang membuat pesan baru ke antrean dan mencatat hasil', function (): void {
        Queue::fake();
        [$pesan, $tenant, $petugas] = SiapkanPesanGagal($this);

        $daftar = KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'kirim-ulang'))->assertOk();
        expect($daftar->json('PesanGagal.0.Uuid'))->toBe($pesan->Uuid)
            ->and($daftar->json('PesanGagal.0.TujuanTersamar'))->toBe('••••••890')
            ->and(json_encode($daftar->json()))->not->toContain('6281234567890');

        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "pesan/{$pesan->Uuid}/kirim-ulang"))->assertRedirect();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $baru = PesanKeluar::query()->where('Id', '>', $pesan->Id)->sole();
        expect($baru->Status)->toBe(StatusPesanKeluar::Diantrekan)
            ->and($baru->Tujuan)->toBe('6281234567890')
            ->and($baru->IdReferensi)->toBe(9001)
            ->and(PesanKeluar::query()->whereKey($pesan->Id)->value('Status'))->toBe(StatusPesanKeluar::Gagal);
        Queue::assertPushed(KirimStrukDigitalTugas::class, fn (KirimStrukDigitalTugas $t): bool => $t->idPesanKeluar === $baru->Id);
        $hasil = HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::KirimUlangPesanWhatsapp);
        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]->Sebelum['Status'])->toBe('Gagal')
            ->and($hasil[0]->Sesudah['UuidPesanBaru'])->toBe($baru->Uuid);

        // Sudah dikirim ulang: tidak boleh dikirim ganda ke pelanggan.
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "pesan/{$pesan->Uuid}/kirim-ulang"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'SudahDikirimUlang');
        expect(PesanKeluar::query()->count())->toBe(2);
    });

    it('WhatsApp tenant belum aktif = KanalTidakAktif. Pesan yang tidak gagal atau milik tenant lain ditolak', function (): void {
        Queue::fake();
        [$pesan, $tenant, $petugas] = SiapkanPesanGagal($this, whatsappAktif: false);
        config(['integrasi.Whatsapp' => null]);

        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "pesan/{$pesan->Uuid}/kirim-ulang"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'KanalTidakAktif');

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $terkirim = PesanKeluar::query()->whereKey($pesan->Id)->first();
        $terkirim->forceFill(['Status' => StatusPesanKeluar::Terkirim])->save();
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "pesan/{$pesan->Uuid}/kirim-ulang"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PesanTidakGagal');
        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], 'pesan/'.Str::ulid().'/kirim-ulang'))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PesanTidakDitemukan');

        Queue::assertNothingPushed();
        expect(array_map(fn (HasilAlatDukungan $h): string => $h->Status->value, HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::KirimUlangPesanWhatsapp)))->toBe(['Gagal', 'Gagal', 'Gagal']);
    });

    it('email verifikasi dikirim ulang ke anggota yang belum verifikasi (email tersamar), tidak lebih dari sekali per 10 menit', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        Pengguna::query()->whereKey($tenant['Pemilik']->Id)->update(['EmailDiverifikasiPada' => null]);

        $daftar = KonsolPetugas($this, $petugas)->getJson(UrlAlat($tenant['Tenant'], 'kirim-ulang'))->assertOk();
        expect($daftar->json('AnggotaBelumVerifikasi.0.Uuid'))->toBe($tenant['Pemilik']->Uuid)
            ->and($daftar->json('AnggotaBelumVerifikasi.0.EmailTersamar'))->toStartWith(substr($tenant['Pemilik']->Email, 0, 1).'•••@')
            ->and(json_encode($daftar->json()))->not->toContain($tenant['Pemilik']->Email);

        $url = UrlAlat($tenant['Tenant'], "pengguna/{$tenant['Pemilik']->Uuid}/kirim-ulang-verifikasi");
        KonsolPetugas($this, $petugas)->post($url)->assertRedirect();
        Mail::assertQueuedCount(1);
        KonsolPetugas($this, $petugas)->postJson($url)->assertUnprocessable()->assertJsonPath('Galat.Kode', 'TerlaluSering');
        Mail::assertQueuedCount(1);

        $this->travel(11)->minutes();
        Pengguna::query()->whereKey($tenant['Pemilik']->Id)->update(['EmailDiverifikasiPada' => now()]);
        KonsolPetugas($this, $petugas)->post($url)->assertRedirect();
        Mail::assertQueuedCount(1);
        expect(array_map(fn (HasilAlatDukungan $h): string => $h->Status->value, HasilAlatTenant($tenant['Tenant']->Id, AlatDukungan::KirimUlangVerifikasiEmail)))->toBe(['Berhasil', 'Gagal', 'TanpaPerubahan']);
    });

    it('pengguna yang bukan anggota tenant ini tidak ditemukan (isolasi tenant)', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        $lain = BantuanOrganisasi::BuatTenant('Toko Lain');

        KonsolPetugas($this, $petugas)->postJson(UrlAlat($tenant['Tenant'], "pengguna/{$lain['Pemilik']->Uuid}/kirim-ulang-verifikasi"))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'PenggunaTidakDitemukan');
        Mail::assertNothingQueued();
    });
});

describe('BR-P09.3: Owner melihat apa yang dikerjakan petugas', function (): void {
    it('halaman Akses dukungan Owner memuat hasil alat dengan sebelum/sesudah', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();

        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $tenant['Pemilik'], $tenant['Tenant']->Id)
            ->get('/kelola/pengaturan/akses-dukungan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Pengaturan/AksesDukungan')
                ->where('Riwayat.Alat.0.Alat', 'CabutPerangkat')
                ->where('Riwayat.Alat.0.NamaPetugas', $petugas->Nama)
                ->where('Riwayat.Alat.0.Status', 'Berhasil')
                ->where('Riwayat.Alat.0.Sebelum.Status', 'Aktif')
                ->where('Riwayat.Alat.0.Sesudah.Status', 'Dicabut'));
    });

    it('hasil alat append-only: tidak bisa diubah atau dihapus', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatDanAktifkan($this, $tenant['Tenant']->Id);
        KonsolPetugas($this, $petugas)->post(UrlAlat($tenant['Tenant'], "perangkat/{$perangkat->Uuid}/cabut"))->assertRedirect();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $hasil = HasilAlatDukungan::query()->firstOrFail();

        expect(fn () => $hasil->update(['Ringkasan' => 'diubah']))->toThrow(LogicException::class)
            ->and(fn () => $hasil->delete())->toThrow(LogicException::class);
    });

    it('alat hanya ada di konsol: sesi petugas di back-office tenant tidak punya jalur alat', function (): void {
        [$tenant, $petugas] = SiapkanAlatUji($this);
        Bantuan::PetugasMasuk($this, $petugas, $tenant['Tenant']);

        // Peramban petugas di domain tenant tidak punya jalur alat; alat hanya ada di konsol.
        $this->getJson('/tenant/'.$tenant['Tenant']->Uuid.'/alat-dukungan/perangkat')->assertNotFound();
    });
});
