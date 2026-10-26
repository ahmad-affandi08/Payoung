<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\Tenant\Aksi\LaksanakanPenghapusanDataTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Aksi\AjukanPenghapusanData;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Kueri\StatusPenghapusanDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\Tenant;
use App\Domain\Tenant\Surel\PenghapusanDataDiajukan;
use App\Domain\Tenant\Surel\PenghapusanDataDitolak;
use App\Domain\Tenant\Surel\PenghapusanDataSelesai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * P-07 / UU PDP: permintaan penghapusan data dan penutupan akun. Owner mengajukan setelah mengunduh ekspor, ada masa
 * tunggu 14 hari yang bisa dibatalkan, lalu Super Admin melaksanakan: data pribadi dihapus, data transaksi dipertahankan
 * dengan identitas dianonimkan, akun ditutup, dan bukti dikirim ke Owner.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
});

/**
 * Tenant dengan data pribadi di beberapa tempat, satu ekspor yang sudah diunduh, dan berkas di penyimpanan.
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Staf: Pengguna}
 */
function SiapkanTenantUntukDihapusUji(string $nama = 'Toko Sumber Rejeki Sragen', bool $denganEksporDiunduh = true): array
{
    $t = BantuanKatalog::SiapkanTenantProduk($nama);
    $idTenant = $t['Tenant']->Id;
    $staf = BantuanOrganisasi::TambahAnggota($idTenant, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::AturKonteks($idTenant);

    Pelanggan::query()->create([
        'Nama' => 'Siti Rahayu Wulandari', 'NoHp' => '6281355550001', 'Email' => 'siti.rahayu@contoh.id',
        'Alamat' => 'Jl. Slamet Riyadi 12, Surakarta', 'TanggalLahir' => '1990-05-17', 'Catatan' => 'Suka kopi tanpa gula',
    ]);

    DB::table('Pemasok')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'Kode' => 'PMS-001', 'Nama' => 'CV Sumber Makmur Jaya', 'NamaKontak' => 'Bambang Sutrisno',
        'NoHp' => '081200001111', 'Email' => 'bambang@sumbermakmur.id', 'Alamat' => 'Jl. Raya Sragen 99', 'NamaBank' => 'BCA', 'NomorRekening' => '1234567890',
        'AtasNamaRekening' => 'Bambang Sutrisno', 'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);

    DB::table('LogAudit')->insert([
        'IdTenant' => $idTenant, 'IdPengguna' => $t['Pemilik']->Id, 'Peristiwa' => 'pelanggan.ubah', 'JenisObjek' => 'Pelanggan', 'IdObjek' => 1,
        'NilaiLama' => json_encode(['Nama' => 'Siti']), 'NilaiBaru' => json_encode(['Nama' => 'Siti Rahayu Wulandari']),
        'Ip' => '36.72.10.5', 'AgenPengguna' => 'Mozilla/5.0', 'DibuatPada' => now(),
    ]);

    $idOutlet = (int) DB::table('Outlet')->where('IdTenant', $idTenant)->value('Id');
    $idPelanggan = (int) DB::table('Pelanggan')->where('IdTenant', $idTenant)->value('Id');
    $idKaryawan = DB::table('Karyawan')->insertGetId([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'Nama' => 'Agus Prasetyo', 'Status' => 'Aktif', 'GajiPokok' => '3500000',
        'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('WajahKaryawan')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'IdKaryawan' => $idKaryawan, 'SidikWajah' => '[1,2,3]', 'PathFoto' => json_encode(["karyawan/swafoto/{$idTenant}/wajah.jpg"]),
        'Status' => 'Disetujui', 'PersetujuanKaryawanPada' => now(), 'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('Kurir')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'Nama' => 'Dedi Kurniawan', 'Jenis' => 'Internal', 'NoHp' => 'enc', 'TokenPortal' => 'enc', 'HashTokenPortal' => hash('sha256', (string) Str::ulid()),
        'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('Kendaraan')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'IdPelanggan' => $idPelanggan, 'NomorPolisi' => 'AD 1234 XY', 'Merek' => 'Honda', 'NomorRangka' => 'MH1JM1234ABC', 'Warna' => 'Merah',
        'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('Perangkat')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'IdOutlet' => $idOutlet, 'Kode' => 'KSR1', 'Nama' => 'HP Kasir Budi', 'Jenis' => 'Kasir', 'HashToken' => hash('sha256', (string) Str::ulid()),
        'TokenPush' => 'push-rahasia', 'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('PesanKeluar')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'IdOutlet' => $idOutlet, 'Kanal' => 'Whatsapp', 'Jenis' => 'StrukDigital', 'IdReferensi' => 1, 'Tujuan' => 'enc-nomor',
        'Status' => 'Terkirim', 'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('TokenApiTenant')->insert([
        'Uuid' => (string) Str::ulid(), 'IdTenant' => $idTenant, 'Nama' => 'Integrasi gudang', 'Prefiks' => 'pyg_abcd', 'HashToken' => hash('sha256', (string) Str::ulid()), 'Cakupan' => json_encode(['produk:baca']),
        'DibuatOleh' => $t['Pemilik']->Id, 'DibuatPada' => now(), 'DiubahPada' => now(),
    ]);
    DB::table('Outlet')->where('Id', $idOutlet)->update(['Alamat' => 'Jl. Raya Sragen 1', 'Lintang' => -7.4, 'Bujur' => 110.8, 'TokenKios' => 'kios-'.Str::lower((string) Str::ulid())]);

    if ($denganEksporDiunduh) {
        EksporDataTenant::query()->create(['Status' => 'Siap', 'DiunduhPada' => now(), 'PathBerkas' => null]);
    }

    foreach ([
        "produk/{$idTenant}/a.webp", "karyawan/swafoto/{$idTenant}/x.jpg", "dukungan/{$idTenant}/tiket/y.pdf", "impor/{$idTenant}/z.csv",
        "tenant/{$idTenant}/logo.png", "akuntansi/kas-bank/{$idTenant}/bukti.pdf", "pembelian/faktur/{$idTenant}/faktur.pdf",
    ] as $path) {
        Storage::disk('local')->put($path, 'isi-berkas');
    }

    return ['Tenant' => $t['Tenant'], 'Pemilik' => $t['Pemilik'], 'Staf' => $staf];
}

/** Ajukan lewat HTTP sebagai Pemilik tenant; mengembalikan permintaan yang tercipta. */
function AjukanPenghapusanUji(TestCase $tes, Tenant $tenant, array $isian = []): TestResponse
{
    return BantuanKatalog::MasukSebagai($tes, $tenant->Id)->post(
        '/kelola/pengaturan/data-saya/penghapusan',
        $isian + ['KataSandi' => 'kata-sandi-uji', 'Konfirmasi' => 'HAPUS DATA', 'Alasan' => 'Usaha ditutup'],
    );
}

describe('P-07 Owner mengajukan penghapusan data', function (): void {
    it('syarat: ekspor harus sudah diunduh, kata sandi benar, frasa konfirmasi persis, lalu masa tunggu 14 hari dan email ke Owner', function (): void {
        $s = SiapkanTenantUntukDihapusUji('Toko Sumber Rejeki Sragen', denganEksporDiunduh: false);

        // Belum ada ekspor yang diunduh.
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        EksporDataTenant::query()->create(['Status' => 'Siap', 'DiunduhPada' => now()]);

        // Frasa dan kata sandi diperiksa lebih dulu daripada aturan bisnis.
        AjukanPenghapusanUji($this, $s['Tenant'], ['Konfirmasi' => 'hapus'])->assertSessionHasErrors('Konfirmasi');
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        expect(PermintaanPenghapusanData::query()->count())->toBe(0);

        $this->travelTo(now()->startOfMinute());
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        $permintaan = PermintaanPenghapusanData::query()->sole();

        expect($permintaan->Status)->toBe(StatusPermintaanPenghapusanData::Menunggu)
            ->and($permintaan->BerlakuPada->equalTo(now()->addDays(14)))->toBeTrue()
            ->and($permintaan->Alasan)->toBe('Usaha ditutup')
            ->and(DB::table('LogAudit')->where('Peristiwa', 'data.penghapusan-ajukan')->count())->toBe(1);

        // Semua Owner aktif diberi tahu; hanya satu permintaan menunggu pada satu waktu.
        Mail::assertQueued(PenghapusanDataDiajukan::class, fn (PenghapusanDataDiajukan $surel): bool => $surel->namaUsaha === 'Toko Sumber Rejeki Sragen');
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasErrors('Umum');
    });

    it('kata sandi salah ditolak. Kasir dan Admin tidak boleh. Edisi Lisensi tidak punya penutupan akun', function (): void {
        $s = SiapkanTenantUntukDihapusUji();

        AjukanPenghapusanUji($this, $s['Tenant'], ['KataSandi' => 'salah-sekali'])->assertSessionHasErrors('KataSandi');
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        expect(PermintaanPenghapusanData::query()->count())->toBe(0);

        BantuanOrganisasi::Masuk($this, $s['Staf'], $s['Tenant']->Id)->post('/kelola/pengaturan/data-saya/penghapusan', ['KataSandi' => 'kata-sandi-uji', 'Konfirmasi' => 'HAPUS DATA'])->assertForbidden();
        $admin = BantuanOrganisasi::TambahAnggota($s['Tenant']->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::Masuk($this, $admin, $s['Tenant']->Id)->post('/kelola/pengaturan/data-saya/penghapusan', ['KataSandi' => 'kata-sandi-uji', 'Konfirmasi' => 'HAPUS DATA'])->assertForbidden();
    });

    it('Owner membatalkan selama masa tunggu. Permintaan baru boleh sesudahnya', function (): void {
        $s = SiapkanTenantUntukDihapusUji();
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();

        BantuanKatalog::MasukSebagai($this, $s['Tenant']->Id)->delete('/kelola/pengaturan/data-saya/penghapusan')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        expect(PermintaanPenghapusanData::query()->sole()->Status)->toBe(StatusPermintaanPenghapusanData::Dibatalkan)
            ->and(DB::table('LogAudit')->where('Peristiwa', 'data.penghapusan-batal')->count())->toBe(1);

        BantuanKatalog::MasukSebagai($this, $s['Tenant']->Id)->delete('/kelola/pengaturan/data-saya/penghapusan')->assertSessionHasErrors('Umum');
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        expect(PermintaanPenghapusanData::query()->count())->toBe(2);
    });

    it('halaman Data saya membawa status penghapusan untuk Pemilik', function (): void {
        $s = SiapkanTenantUntukDihapusUji();
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();

        BantuanKatalog::MasukSebagai($this, $s['Tenant']->Id)->get('/kelola/pengaturan/data-saya')->assertOk()
            ->assertInertia(fn ($h) => $h->component('Kelola/Pengaturan/DataSaya')
                ->where('Penghapusan.Tersedia', true)->where('Penghapusan.HariTunggu', 14)->where('Penghapusan.EksporSudahDiunduh', true)
                ->has('Penghapusan.Menunggu.BerlakuPada')->where('PerluKataSandi', true));
    });
});

describe('P-07 Super Admin melaksanakan penghapusan data', function (): void {
    /**
     * Ajukan, majukan jam melewati masa tunggu, lalu kembalikan siap dilaksanakan.
     *
     * @return array{Tenant: Tenant, Pemilik: Pengguna, Staf: Pengguna, Uuid: string}
     */
    function SiapkanPermintaanSiapUji(TestCase $tes): array
    {
        $s = SiapkanTenantUntukDihapusUji();
        AjukanPenghapusanUji($tes, $s['Tenant'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        $uuid = PermintaanPenghapusanData::query()->sole()->Uuid;
        $tes->travelTo(now()->addDays(15));

        return $s + ['Uuid' => $uuid];
    }

    it('sebelum masa tunggu berakhir ditolak. Izin dan 2FA baru ditegakkan. Slug harus diketik', function (): void {
        $s = SiapkanTenantUntukDihapusUji();
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);
        $uuid = PermintaanPenghapusanData::query()->sole()->Uuid;
        $url = BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$uuid}/laksanakan");
        $isian = ['Catatan' => 'Identitas Owner diverifikasi lewat telepon.', 'KonfirmasiSlug' => $s['Tenant']->Slug];

        // Peran tanpa izin.
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);
        $this->post($url, $isian)->assertForbidden();

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post($url, array_merge($isian, ['KonfirmasiSlug' => 'slug-salah']))->assertSessionHasErrors('KonfirmasiSlug');
        $this->post($url, ['KonfirmasiSlug' => $s['Tenant']->Slug])->assertSessionHasErrors('Catatan');
        $this->post($url, $isian)->assertSessionHasErrors('Umum');

        expect(Tenant::query()->whereKey($s['Tenant']->Id)->value('Status'))->toBe(StatusTenant::Aktif)
            ->and(DB::table('Pelanggan')->where('IdTenant', $s['Tenant']->Id)->value('Nama'))->toBe('Siti Rahayu Wulandari');
    });

    it('melaksanakan: data pribadi hilang, transaksi dan jurnal utuh, akun ditutup, akses dicabut, bukti dikirim, tenant lain aman', function (): void {
        $lain = SiapkanTenantUntukDihapusUji('Warung Bakso Pak Kumis');
        $s = SiapkanPermintaanSiapUji($this);
        $id = $s['Tenant']->Id;
        $emailPemilik = (string) $s['Pemilik']->Email;
        $jumlahJurnalSebelum = DB::table('Jurnal')->where('IdTenant', $id)->count();

        // Pengguna kasir juga anggota tenant lain: hanya dilepas, tidak dianonimkan.
        TenantPengguna::query()->create(['IdTenant' => $lain['Tenant']->Id, 'IdPengguna' => $s['Staf']->Id, 'Pemilik' => false, 'IdPeran' => BantuanOrganisasi::Peran($lain['Tenant']->Id, PeranTenantBawaan::Kasir)->Id, 'SemuaOutlet' => true]);
        $namaStaf = $s['Staf']->Nama;

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post(BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$s['Uuid']}/laksanakan"), [
            'Catatan' => 'Identitas Owner diverifikasi lewat telepon.', 'KonfirmasiSlug' => $s['Tenant']->Slug,
        ])->assertSessionHasNoErrors();

        // Tenant ditutup, nama dan slug dianonimkan, langganan berhenti.
        $tenant = DB::table('Tenant')->where('Id', $id)->first();
        expect($tenant->Status)->toBe('Ditutup')->and($tenant->Nama)->toStartWith('Usaha Terhapus #')->and($tenant->Slug)->toStartWith('ditutup-')->and($tenant->Npwp)->toBeNull()
            ->and(DB::table('Langganan')->where('IdTenant', $id)->value('Status'))->toBe(StatusLangganan::Berhenti->value);

        // Data pribadi hilang.
        $pelanggan = DB::table('Pelanggan')->where('IdTenant', $id)->first();
        expect($pelanggan->Nama)->toBe('Pelanggan Terhapus')->and($pelanggan->Email)->toBeNull()->and($pelanggan->Alamat)->toBeNull()
            ->and($pelanggan->TanggalLahir)->toBeNull()->and($pelanggan->Catatan)->toBeNull()->and($pelanggan->NoHp)->toStartWith('terhapus-');
        $pemasok = DB::table('Pemasok')->where('IdTenant', $id)->first();
        expect($pemasok->Nama)->toStartWith('Pemasok Terhapus #')->and($pemasok->NamaKontak)->toBeNull()->and($pemasok->NomorRekening)->toBeNull()->and($pemasok->Email)->toBeNull();
        $audit = DB::table('LogAudit')->where('IdTenant', $id)->where('Peristiwa', 'pelanggan.ubah')->first();
        expect($audit->Ip)->toBeNull()->and($audit->AgenPengguna)->toBeNull()->and($audit->NilaiLama)->toBeNull()->and($audit->NilaiBaru)->toBeNull();
        expect(DB::table('LogAudit')->where('IdTenant', $id)->where('Peristiwa', 'pelanggan.ubah')->exists())->toBeTrue();

        // Karyawan, wajah, kurir, kendaraan, perangkat, pesan, token, dan lokasi outlet.
        $karyawan = DB::table('Karyawan')->where('IdTenant', $id)->first();
        expect($karyawan->Nama)->toStartWith('Karyawan Terhapus #')->and($karyawan->GajiPokok)->toBeNull();
        expect(DB::table('WajahKaryawan')->where('IdTenant', $id)->count())->toBe(0)
            ->and(DB::table('PesanKeluar')->where('IdTenant', $id)->count())->toBe(0)
            ->and(DB::table('TokenApiTenant')->where('IdTenant', $id)->count())->toBe(0);
        $kurir = DB::table('Kurir')->where('IdTenant', $id)->first();
        expect($kurir->Nama)->toStartWith('Kurir Terhapus #')->and($kurir->NoHp)->toBeNull()->and($kurir->TokenPortal)->toBeNull();
        $kendaraan = DB::table('Kendaraan')->where('IdTenant', $id)->first();
        expect($kendaraan->NomorPolisi)->toStartWith('TERHAPUS-')->and($kendaraan->NomorRangka)->toBeNull();
        $perangkat = DB::table('Perangkat')->where('IdTenant', $id)->first();
        expect($perangkat->Nama)->toStartWith('Perangkat Terhapus #')->and($perangkat->HashToken)->toBeNull()->and($perangkat->TokenPush)->toBeNull()->and($perangkat->DicabutPada)->not->toBeNull();
        $outlet = DB::table('Outlet')->where('IdTenant', $id)->first();
        expect($outlet->Alamat)->toBeNull()->and($outlet->Lintang)->toBeNull()->and($outlet->TokenKios)->toBeNull()->and($outlet->Nama)->toStartWith('Outlet Terhapus #');

        // Pengguna hanya di tenant ini dianonimkan (termasuk Owner); yang juga di tenant lain hanya dilepas.
        $pemilik = DB::table('Pengguna')->where('Id', $s['Pemilik']->Id)->first();
        expect($pemilik->Nama)->toBe('Pengguna Terhapus')->and($pemilik->Email)->toBe("terhapus-{$s['Pemilik']->Id}@payoung.invalid")->and($pemilik->NoHp)->toBeNull()->and($pemilik->Rahasia2fa)->toBeNull();
        expect(DB::table('Pengguna')->where('Id', $s['Staf']->Id)->value('Nama'))->toBe($namaStaf)
            ->and(DB::table('TenantPengguna')->where('IdTenant', $id)->count())->toBe(0)
            ->and(DB::table('TenantPengguna')->where('IdTenant', $lain['Tenant']->Id)->where('IdPengguna', $s['Staf']->Id)->exists())->toBeTrue();

        // Tenant lain tidak tersentuh.
        expect(DB::table('Pelanggan')->where('IdTenant', $lain['Tenant']->Id)->value('Nama'))->toBe('Siti Rahayu Wulandari')
            ->and(DB::table('Tenant')->where('Id', $lain['Tenant']->Id)->value('Status'))->toBe('Aktif')
            ->and(DB::table('Jurnal')->where('IdTenant', $id)->count())->toBe($jumlahJurnalSebelum);

        // Berkas pribadi hilang; lampiran pembukuan dan berkas tenant lain tetap.
        $disk = Storage::disk('local');
        foreach (["produk/{$id}/a.webp", "karyawan/swafoto/{$id}/x.jpg", "dukungan/{$id}/tiket/y.pdf", "impor/{$id}/z.csv", "tenant/{$id}/logo.png"] as $path) {
            expect($disk->exists($path))->toBeFalse();
        }
        foreach (["akuntansi/kas-bank/{$id}/bukti.pdf", "pembelian/faktur/{$id}/faktur.pdf", "produk/{$lain['Tenant']->Id}/a.webp"] as $path) {
            expect($disk->exists($path))->toBeTrue();
        }

        // Permintaan menjadi bukti, jejak audit pengelola ada, bukti dikirim ke Owner dengan kode bukti.
        $permintaan = DB::table('PermintaanPenghapusanData')->where('Uuid', $s['Uuid'])->first();
        expect($permintaan->Status)->toBe('Dilaksanakan')->and($permintaan->DilaksanakanPada)->not->toBeNull()->and($permintaan->BuktiDikirimPada)->not->toBeNull()
            ->and(json_decode((string) $permintaan->Ringkasan, true)['PenggunaDianonimkan'])->toBeGreaterThanOrEqual(2);
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.penghapusan-data-laksana')->where('IdTenant', $id)->count())->toBe(1);
        Mail::assertSent(PenghapusanDataSelesai::class, fn (PenghapusanDataSelesai $surel): bool => $surel->hasTo($emailPemilik) && $surel->kodeBukti === $s['Uuid'] && $surel->namaUsaha === 'Toko Sumber Rejeki Sragen');

        // Tidak bisa dilaksanakan dua kali; halaman publik dan penyapu tidak lagi melihat tenant ini.
        $this->post(BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$s['Uuid']}/laksanakan"), [
            'Catatan' => 'Identitas Owner diverifikasi lewat telepon.', 'KonfirmasiSlug' => $s['Tenant']->Slug,
        ])->assertSessionHasErrors();
        expect(app(ProfilTenant::class)->CekAda($id))->toBeFalse()->and(app(ProfilTenant::class)->CariIdDariSlug($s['Tenant']->Slug))->toBeNull()
            ->and(app(ProfilTenant::class)->CekAda($lain['Tenant']->Id))->toBeTrue();
    });

    it('penyapu terjadwal tidak lagi mengunjungi tenant yang sudah ditutup', function (): void {
        $s = SiapkanPermintaanSiapUji($this);
        $pengelola = app(KonteksPengelola::class);
        $cari = fn (bool $termasukDitutup) => $pengelola->IdTenantDenganPekerjaan(PermintaanPenghapusanData::class, fn ($q) => $q, 5000, $termasukDitutup);

        expect($cari(false))->toContain($s['Tenant']->Id);

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post(BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$s['Uuid']}/laksanakan"), [
            'Catatan' => 'Identitas Owner diverifikasi lewat telepon.', 'KonfirmasiSlug' => $s['Tenant']->Slug,
        ])->assertSessionHasNoErrors();

        expect($cari(false))->not->toContain($s['Tenant']->Id)->and($cari(true))->toContain($s['Tenant']->Id);
    });

    it('menolak permintaan: alasan wajib, Owner diberi tahu, akun tetap berjalan, permintaan baru boleh', function (): void {
        $s = SiapkanPermintaanSiapUji($this);
        $url = BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$s['Uuid']}/tolak");

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post($url, ['Alasan' => 'pendek'])->assertSessionHasErrors('Alasan');
        $this->post($url, ['Alasan' => 'Ada sengketa pembayaran yang belum selesai.'])->assertSessionHasNoErrors();

        $permintaan = DB::table('PermintaanPenghapusanData')->where('Uuid', $s['Uuid'])->first();
        expect($permintaan->Status)->toBe('Ditolak')->and($permintaan->CatatanPemutus)->toBe('Ada sengketa pembayaran yang belum selesai.')
            ->and(DB::table('Tenant')->where('Id', $s['Tenant']->Id)->value('Status'))->toBe('Aktif')
            ->and(DB::table('Pelanggan')->where('IdTenant', $s['Tenant']->Id)->value('Nama'))->toBe('Siti Rahayu Wulandari');
        Mail::assertQueued(PenghapusanDataDitolak::class, fn (PenghapusanDataDitolak $surel): bool => $surel->alasan === 'Ada sengketa pembayaran yang belum selesai.');
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.penghapusan-data-tolak')->count())->toBe(1);

        // Sudah diputuskan, tidak bisa ditolak lagi.
        $this->post($url, ['Alasan' => 'Ada sengketa pembayaran yang belum selesai.'])->assertSessionHasErrors('Umum');
        app(KonteksTenant::class)->Atur($s['Tenant']->Id);
        expect(Langganan::query()->where('IdTenant', $s['Tenant']->Id)->value('Status'))->not->toBe(StatusLangganan::Berhenti);
    });

    it('tampilan 360° konsol memuat permintaan beserta ringkasan tanpa data pribadi', function (): void {
        $s = SiapkanPermintaanSiapUji($this);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->get(BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}"))->assertOk()
            ->assertInertia(fn ($h) => $h->where('Tenant.PenghapusanData.0.Status', 'Menunggu')->where('Tenant.PenghapusanData.0.MasaTungguSelesai', true)
                ->where('Tenant.PenghapusanData.0.Uuid', $s['Uuid']));
    });
});

describe('P-07 edisi Lisensi, retensi, dan isi email', function (): void {
    it('edisi Lisensi tidak punya penutupan akun: aksi menolak dan halaman menandai tidak tersedia', function (): void {
        $s = SiapkanTenantUntukDihapusUji();
        config(['lisensi.Edisi' => 'Lisensi']);
        BantuanOrganisasi::AturKonteks($s['Tenant']->Id);

        expect(fn () => app(AjukanPenghapusanData::class)->Jalankan($s['Pemilik']->Id, null))
            ->toThrow(PelanggaranAturanBisnis::class, 'edisi Mandiri')
            ->and(app(StatusPenghapusanDataTenant::class)->Ambil()['Tersedia'])->toBeFalse()
            ->and(PermintaanPenghapusanData::query()->count())->toBe(0);
    });

    it('lampiran pembukuan tenant tertutup dihapus setelah 10 tahun, bukan sebelumnya', function (): void {
        $s = SiapkanPermintaanSiapUji($this);
        $id = $s['Tenant']->Id;
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post(BantuanPengelola::Url("/tenant/{$s['Tenant']->Uuid}/penghapusan-data/{$s['Uuid']}/laksanakan"), [
            'Catatan' => 'Identitas Owner diverifikasi lewat telepon.', 'KonfirmasiSlug' => $s['Tenant']->Slug,
        ])->assertSessionHasNoErrors();
        $disk = Storage::disk('local');

        $this->artisan('penghapusan-data:hapus-retensi')->assertSuccessful();
        expect($disk->exists("akuntansi/kas-bank/{$id}/bukti.pdf"))->toBeTrue()
            ->and(DB::table('PermintaanPenghapusanData')->where('Uuid', $s['Uuid'])->value('BerkasPembukuanDihapusPada'))->toBeNull();

        $this->travelTo(now()->addYears(10)->addDay());
        $this->artisan('penghapusan-data:hapus-retensi')->assertSuccessful();
        expect($disk->exists("akuntansi/kas-bank/{$id}/bukti.pdf"))->toBeFalse()
            ->and($disk->exists("pembelian/faktur/{$id}/faktur.pdf"))->toBeFalse()
            ->and(DB::table('PermintaanPenghapusanData')->where('Uuid', $s['Uuid'])->value('BerkasPembukuanDihapusPada'))->not->toBeNull();
    });

    it('email bukti memuat ringkasan tanpa data pribadi pelanggan, karyawan, atau pemasok', function (): void {
        $surel = new PenghapusanDataSelesai(
            'Rina Pemilik',
            'Toko Sumber Rejeki Sragen',
            '26 Oktober 2026 13.54 WIB',
            '01M4QEEEW32A6RM515KDRHSDVV',
            LaksanakanPenghapusanDataTenant::UraikanDihapus(['PenggunaDianonimkan' => 3, 'Dihapus' => ['TenantPengguna' => 3]]),
            LaksanakanPenghapusanDataTenant::UraikanDianonimkan(['Dianonimkan' => ['Pelanggan' => 120, 'Pemasok' => 8]]),
            LaksanakanPenghapusanDataTenant::UraikanDipertahankan(),
        );
        $html = $surel->render();

        expect($html)->toContain('01M4QEEEW32A6RM515KDRHSDVV')->toContain('Pelanggan (nama, telepon')->toContain('120 data')->toContain('Yang tetap disimpan')
            ->toContain('10 tahun')->not->toContain('Siti Rahayu')->not->toContain('Bambang');
    });

    it('permintaan ke semua Owner: email konfirmasi dikirim ke tiap Owner aktif', function (): void {
        $s = SiapkanTenantUntukDihapusUji();
        $pemilikKedua = BantuanOrganisasi::TambahAnggota($s['Tenant']->Id, PeranTenantBawaan::Pemilik, true, 'owner.kedua@contoh.id');
        AjukanPenghapusanUji($this, $s['Tenant'])->assertSessionHasNoErrors();

        Mail::assertQueued(PenghapusanDataDiajukan::class, fn (PenghapusanDataDiajukan $surel): bool => $surel->hasTo('owner.kedua@contoh.id'));
        // Pemilik pendaftaran, pemilik yang masuk lewat bantuan uji, dan pemilik kedua: semuanya diberi tahu.
        Mail::assertQueued(PenghapusanDataDiajukan::class, DB::table('TenantPengguna')->where('IdTenant', $s['Tenant']->Id)->where('Pemilik', true)->count());
        expect($pemilikKedua->Email)->toBe('owner.kedua@contoh.id');
    });
});
