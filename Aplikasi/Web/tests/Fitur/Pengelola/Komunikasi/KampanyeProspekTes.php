<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiSmtp;
use App\Domain\Pengelola\Komunikasi\Aksi\BuatKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\ImporKontakProspek;
use App\Domain\Pengelola\Komunikasi\Aksi\KirimKampanyeProspekJatuhTempo;
use App\Domain\Pengelola\Komunikasi\Aksi\UbahStatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Layanan\PengirimKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\TautanBerhentiProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaKampanyeProspek;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;

/*
 * D-216: kampanye email ke calon pelanggan. Tidak ada email yang keluar sungguhan: pengirim kampanye memakai transport
 * `array` (pesan bisa diperiksa, termasuk header `List-Unsubscribe`), dan `smtp` ke port yang tertutup untuk menguji
 * kegagalan. Yang dijaga: impor hanya Email+Sektor tanpa membangkitkan yang sudah berhenti, penerima ditetapkan saat
 * dijalankan, pengiriman pelan dan berbatas per hari pada jam kerja, kontak yang berhenti tidak dikirimi, jeda otomatis,
 * dan tautan berhenti yang bertanda tangan.
 */

function PelakuKampanyeUji(): PenggunaPengelola
{
    return BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
}

/** @param array<string, mixed> $transport */
function AktifkanEmailKampanyeUji(array $transport = ['transport' => 'array']): void
{
    config([
        'mail.mailers.kampanye' => $transport,
        'integrasi.EmailKampanyeAktif' => true,
        'integrasi.EmailKampanye' => ['AlamatPengirim' => 'info@kampanye.payoung.id', 'NamaPengirim' => 'Payoung'],
    ]);
}

function BuatKontakProspekUji(string $email, SektorProspek $sektor = SektorProspek::KafeKopi, StatusKontakProspek $status = StatusKontakProspek::Aktif): KontakProspek
{
    return KontakProspek::query()->create([
        'Email' => $email,
        'Sektor' => $sektor,
        'Sumber' => 'Data Pelaku Ekonomi Kreatif di Kota Pontianak (ckan.pontianak.go.id)',
        'Status' => $status,
    ]);
}

/** @param list<SektorProspek> $sektor */
function BuatKampanyeProspekUji(array $sektor = [SektorProspek::KafeKopi], int $batas = 40, ?PenggunaPengelola $pelaku = null): KampanyeProspek
{
    return app(BuatKampanyeProspek::class)->Jalankan(
        $pelaku ?? PelakuKampanyeUji(),
        'Perkenalan Payoung untuk kafe',
        'Kasir yang cocok untuk {sektor}',
        "Halo,\n\nPayoung adalah aplikasi kasir untuk usaha {sektor}. Boleh kami tunjukkan caranya?\n\nSalam,\nTim Payoung",
        array_map(fn (SektorProspek $s): string => $s->value, $sektor),
        $batas,
    );
}

function JalankanKampanyeProspekUji(KampanyeProspek $kampanye): KampanyeProspek
{
    return app(UbahStatusKampanyeProspek::class)->Jalankan(PelakuKampanyeUji(), $kampanye, StatusKampanyeProspek::Berjalan);
}

beforeEach(function (): void {
    // Senin pagi WIB, di dalam jam kirim 08.00–17.00.
    $this->travelTo(Carbon::parse('2026-10-26 10:00:00', 'Asia/Jakarta'));
});

describe('SektorProspek', function (): void {
    it('membaca kode maupun nama sektor tanpa membedakan huruf besar kecil, yang tak dikenal null', function (): void {
        expect(SektorProspek::DariTeks('FNB-CAF'))->toBe(SektorProspek::KafeKopi)
            ->and(SektorProspek::DariTeks('  kafe & KEDAI kopi '))->toBe(SektorProspek::KafeKopi)
            ->and(SektorProspek::DariTeks('Jasa profesional & agensi'))->toBe(SektorProspek::JasaProfesional)
            ->and(SektorProspek::DariTeks('Seni rupa'))->toBeNull()
            ->and(SektorProspek::DariTeks(''))->toBeNull();
    });
});

describe('D-216 integrasi email kampanye (P-05)', function (): void {
    it('jenis terpisah dari email transaksional dengan isian SMTP yang sama dan penguji SMTP', function (): void {
        $bidang = array_column(PenyediaIntegrasi::SmtpKampanye->AmbilBidangPengaturan(), 'Kunci');

        expect(PenyediaIntegrasi::SmtpKampanye->AmbilJenis())->toBe(JenisIntegrasi::EmailKampanye)
            ->and(JenisIntegrasi::EmailKampanye->AmbilPenyedia())->toBe(PenyediaIntegrasi::SmtpKampanye)
            ->and(JenisIntegrasi::AmbilJenisPlatform())->toContain(JenisIntegrasi::EmailKampanye)
            ->and($bidang)->toBe(['Host', 'Port', 'Enkripsi', 'NamaPengguna', 'AlamatPengirim', 'NamaPengirim'])
            ->and(array_column(PenyediaIntegrasi::SmtpKampanye->AmbilBidangKredensial(), 'Kunci'))->toBe(['KataSandi'])
            ->and(PenyediaIntegrasi::SmtpKampanye->AmbilKelasPenguji())->toBe(PengujiSmtp::class)
            ->and(JenisIntegrasi::Email->AmbilDaftarPenyedia())->not->toContain(PenyediaIntegrasi::SmtpKampanye);
    });
});

describe('D-216 impor kontak', function (): void {
    it('hanya Email dan Sektor yang dibaca: huruf kecil, duplikat dan tak valid dihitung, kolom lain tak disimpan', function (): void {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'No,Nama Usaha,Email,Telepon,Sektor',
            '1,Kedai Rahasia,Budi@Contoh.id,08123456789,Kafe & kedai kopi',
            '2,Toko Dua,siti@contoh.id,08111,FNB-QSR',
            '3,Toko Tiga,BUDI@contoh.id,08999,Kafe & kedai kopi',
            '4,Rusak,bukan-email,0812,Kafe & kedai kopi',
            '5,Seni,seni@contoh.id,0812,Seni rupa',
            '6,Sudah ada,lama@contoh.id,0812,Laundry',
        ]);
        BuatKontakProspekUji('lama@contoh.id', SektorProspek::Laundry);

        $hasil = app(ImporKontakProspek::class)->Jalankan(PelakuKampanyeUji(), $csv, 'Data Pelaku Ekonomi Kreatif Kota Pontianak');

        expect($hasil)->toBe(['Ditambah' => 2, 'SudahAda' => 2, 'TidakValid' => 1, 'SektorTidakDikenal' => 1]);

        $budi = KontakProspek::query()->where('Email', 'budi@contoh.id')->sole();

        expect($budi->Sektor)->toBe(SektorProspek::KafeKopi)
            ->and($budi->Sumber)->toBe('Data Pelaku Ekonomi Kreatif Kota Pontianak')
            ->and($budi->Status)->toBe(StatusKontakProspek::Aktif)
            ->and(array_keys($budi->getAttributes()))->not->toContain('Nama', 'Telepon')
            ->and(KontakProspek::query()->count())->toBe(3);

        // Log audit hanya memuat hitungan dan sumber, bukan alamat email.
        $log = LogAuditPengelola::query()->where('Aksi', 'prospek.kontak.impor')->sole();

        expect($log->NilaiBaru)->toMatchArray(['Ditambah' => 2, 'SudahAda' => 2])
            ->and(json_encode($log->NilaiBaru))->not->toContain('contoh.id');
    });

    it('kontak yang sudah berhenti tidak hidup kembali lewat impor ulang. Titik koma dan urutan kolom bebas', function (): void {
        $berhenti = BuatKontakProspekUji('pergi@contoh.id', SektorProspek::Salon, StatusKontakProspek::Berhenti);

        $hasil = app(ImporKontakProspek::class)->Jalankan(PelakuKampanyeUji(), "Sektor;Email\nSalon, barbershop & spa;pergi@contoh.id\nSVC-SLN;baru@contoh.id", 'Dataset uji kota Bandung');

        expect($hasil['Ditambah'])->toBe(1)->and($hasil['SudahAda'])->toBe(1)
            ->and($berhenti->refresh()->Status)->toBe(StatusKontakProspek::Berhenti);
    });

    it('menolak berkas tanpa kolom Email/Sektor, sumber yang terlalu singkat, dan berkas terlalu banyak baris', function (): void {
        $pelaku = PelakuKampanyeUji();
        $impor = app(ImporKontakProspek::class);

        expect(fn () => $impor->Jalankan($pelaku, "Nama,Telepon\nA,1", 'Dataset uji kota Bandung'))->toThrow(PelanggaranAturanBisnis::class, 'kolom Email dan Sektor')
            ->and(fn () => $impor->Jalankan($pelaku, "Email,Sektor\na@b.id,Laundry", 'x'))->toThrow(PelanggaranAturanBisnis::class, 'sumber data')
            ->and(fn () => $impor->Jalankan($pelaku, "Email,Sektor\n".str_repeat("a@b.id,Laundry\n", ImporKontakProspek::MAKS_BARIS + 1), 'Dataset uji kota Bandung'))->toThrow(PelanggaranAturanBisnis::class, 'Paling banyak');
        expect(KontakProspek::query()->count())->toBe(0);
    });

    it('lewat halaman: hanya Super Admin (komunikasi.kelola). Berkas CSV diunggah dengan sumber', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        $this->get(BantuanPengelola::Url('/kampanye-prospek/kontak'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/kampanye-prospek/kontak/impor'), [])->assertForbidden();

        $this->flushSession();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $berkas = UploadedFile::fake()->createWithContent('prospek.csv', "Email,Sektor\nkopi@contoh.id,Kafe & kedai kopi\n");

        $this->post(BantuanPengelola::Url('/kampanye-prospek/kontak/impor'), ['Berkas' => $berkas, 'Sumber' => 'Data IKM Kecamatan Tanjungpinang Kota'])
            ->assertSessionHas('Kilat', 'Impor selesai: 1 kontak ditambahkan, 0 sudah ada, 0 email tidak valid, 0 sektor tidak dikenal.');
        $this->get(BantuanPengelola::Url('/kampanye-prospek/kontak'))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/KampanyeProspek/Kontak')
            ->has('Kontak', 1)
            ->where('Kontak.0.Email', 'kopi@contoh.id')
            ->where('Ringkasan.Aktif', 1));
        $this->post(BantuanPengelola::Url('/kampanye-prospek/kontak/impor'), ['Berkas' => $berkas, 'Sumber' => 'x'])->assertSessionHasErrors('Sumber');
    });

    it('tandai berhenti dan hapus kontak tercatat di audit tanpa memuat alamat email', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $a = BuatKontakProspekUji('a@contoh.id');
        $b = BuatKontakProspekUji('b@contoh.id');

        $this->put(BantuanPengelola::Url("/kampanye-prospek/kontak/{$a->Uuid}/henti"))->assertSessionHas('Kilat');
        $this->delete(BantuanPengelola::Url("/kampanye-prospek/kontak/{$b->Uuid}"))->assertSessionHas('Kilat');

        expect($a->refresh()->Status)->toBe(StatusKontakProspek::Berhenti)
            ->and($a->BerhentiPada)->not->toBeNull()
            ->and(KontakProspek::query()->whereKey($b->Id)->exists())->toBeFalse()
            ->and(LogAuditPengelola::query()->whereIn('Aksi', ['prospek.kontak.henti', 'prospek.kontak.hapus'])->count())->toBe(2)
            ->and(json_encode(LogAuditPengelola::query()->where('Aksi', 'prospek.kontak.hapus')->sole()->NilaiLama))->not->toContain('contoh.id');
    });
});

describe('D-216 membuat dan menjalankan kampanye', function (): void {
    it('draf dibuat dengan sektor terpilih, tanpa sektor, tanpa kontak aktif, atau batas di luar rentang ditolak', function (): void {
        BuatKontakProspekUji('kopi@contoh.id');
        $aksi = app(BuatKampanyeProspek::class);
        $pelaku = PelakuKampanyeUji();

        $kampanye = BuatKampanyeProspekUji(batas: 25, pelaku: $pelaku);

        expect($kampanye->Status)->toBe(StatusKampanyeProspek::Draf)
            ->and($kampanye->Segmen)->toBe(['Sektor' => ['FNB-CAF']])
            ->and($kampanye->BatasPerHari)->toBe(25)
            ->and(LogAuditPengelola::query()->where('Aksi', 'prospek.kampanye.buat')->count())->toBe(1);
        expect(fn () => $aksi->Jalankan($pelaku, 'Nama kampanye', 'Subjek', str_repeat('x', 30), ['ngawur'], 40))->toThrow(PelanggaranAturanBisnis::class, 'sedikitnya satu sektor')
            ->and(fn () => $aksi->Jalankan($pelaku, 'Nama kampanye', 'Subjek', str_repeat('x', 30), ['SVC-LDR'], 40))->toThrow(PelanggaranAturanBisnis::class, 'Tidak ada kontak aktif')
            ->and(fn () => $aksi->Jalankan($pelaku, 'Nama kampanye', 'Subjek', str_repeat('x', 30), ['FNB-CAF'], 0))->toThrow(PelanggaranAturanBisnis::class, 'Batas kirim')
            ->and(fn () => $aksi->Jalankan($pelaku, 'Nama kampanye', 'Subjek', str_repeat('x', 30), ['FNB-CAF'], 501))->toThrow(PelanggaranAturanBisnis::class, 'Batas kirim');
    });

    it('tanpa pengirim email sama sekali (kampanye maupun bawaan) kampanye tidak bisa dijalankan', function (): void {
        BuatKontakProspekUji('kopi@contoh.id');
        $kampanye = BuatKampanyeProspekUji();

        expect(fn () => JalankanKampanyeProspekUji($kampanye))->toThrow(PelanggaranAturanBisnis::class, 'Belum ada pengirim email')
            ->and($kampanye->refresh()->Status)->toBe(StatusKampanyeProspek::Draf)
            ->and(PenerimaKampanyeProspek::query()->count())->toBe(0);
    });

    it('menjalankan menetapkan penerima: hanya kontak aktif di sektor terpilih. Jeda, lanjut, dan batal', function (): void {
        AktifkanEmailKampanyeUji();
        BuatKontakProspekUji('a@contoh.id');
        BuatKontakProspekUji('b@contoh.id');
        BuatKontakProspekUji('henti@contoh.id', SektorProspek::KafeKopi, StatusKontakProspek::Berhenti);
        BuatKontakProspekUji('laundry@contoh.id', SektorProspek::Laundry);
        $kampanye = BuatKampanyeProspekUji();

        $jalan = JalankanKampanyeProspekUji($kampanye);

        expect($jalan->Status)->toBe(StatusKampanyeProspek::Berjalan)
            ->and($jalan->JumlahPenerima)->toBe(2)
            ->and($jalan->MulaiKirimPada)->not->toBeNull()
            ->and(PenerimaKampanyeProspek::query()->where('IdKampanyeProspek', $kampanye->Id)->count())->toBe(2);

        $ubah = app(UbahStatusKampanyeProspek::class);
        $pelaku = PelakuKampanyeUji();

        expect($ubah->Jalankan($pelaku, $kampanye, StatusKampanyeProspek::Dijeda)->Status)->toBe(StatusKampanyeProspek::Dijeda)
            ->and($ubah->Jalankan($pelaku, $kampanye, StatusKampanyeProspek::Berjalan)->Status)->toBe(StatusKampanyeProspek::Berjalan)
            ->and(PenerimaKampanyeProspek::query()->where('IdKampanyeProspek', $kampanye->Id)->count())->toBe(2);

        $batal = $ubah->Jalankan($pelaku, $kampanye, StatusKampanyeProspek::Dibatalkan);

        expect($batal->Status)->toBe(StatusKampanyeProspek::Dibatalkan)
            ->and($batal->SelesaiPada)->not->toBeNull()
            ->and($batal->JumlahDilewati)->toBe(2)
            ->and(PenerimaKampanyeProspek::query()->where('Status', StatusPenerimaSiaran::Menunggu->value)->count())->toBe(0)
            ->and(fn () => $ubah->Jalankan($pelaku, $kampanye, StatusKampanyeProspek::Berjalan))->toThrow(PelanggaranAturanBisnis::class, 'tidak bisa diubah')
            ->and(LogAuditPengelola::query()->where('Aksi', 'prospek.kampanye.status')->count())->toBe(4);
    });

    it('lewat halaman: Super Admin membuat, menjalankan, dan melihat rincian; Analis ditolak', function (): void {
        AktifkanEmailKampanyeUji();
        BuatKontakProspekUji('a@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        $this->get(BantuanPengelola::Url('/kampanye-prospek'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/kampanye-prospek'), [])->assertForbidden();

        $this->flushSession();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->get(BantuanPengelola::Url('/kampanye-prospek/buat'))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/KampanyeProspek/Buat')
            ->where('PilihanSektor.0.Nilai', 'FNB-CAF')
            ->where('PilihanSektor.0.Aktif', 1)
            ->where('EmailKampanyeAktif', true));

        $this->post(BantuanPengelola::Url('/kampanye-prospek'), ['Nama' => 'Kampanye kafe', 'Subjek' => 'Untuk {sektor}', 'Isi' => str_repeat('Isi pesan. ', 5), 'Sektor' => ['FNB-CAF'], 'BatasPerHari' => 30])->assertRedirect();
        $kampanye = KampanyeProspek::query()->sole();
        $this->get(BantuanPengelola::Url('/kampanye-prospek'))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/KampanyeProspek/Daftar')->has('Kampanye', 1)->where('Kampanye.0.Status', 'Draf')->where('Kampanye.0.DapatDijalankan', true)->where('EmailKampanyeAktif', true)->where('Kontak.Aktif', 1));

        $this->put(BantuanPengelola::Url("/kampanye-prospek/{$kampanye->Uuid}/status"), ['Status' => 'Berjalan'])->assertSessionHas('Kilat');
        $this->put(BantuanPengelola::Url("/kampanye-prospek/{$kampanye->Uuid}/status"), ['Status' => 'Selesai'])->assertSessionHasErrors('Status');
        $this->get(BantuanPengelola::Url("/kampanye-prospek/{$kampanye->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/KampanyeProspek/Tampil')->where('Kampanye.Status', 'Berjalan')->has('DiJamKirim')->has('Penerima', 1)->where('Penerima.0.Email', 'a@contoh.id'));
    });

    it('email uji dikirim ke alamat petugas sendiri dengan awalan [UJI] dan tautan berhenti contoh', function (): void {
        AktifkanEmailKampanyeUji();
        BuatKontakProspekUji('kopi@contoh.id');
        $kampanye = BuatKampanyeProspekUji();
        $petugas = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->post(BantuanPengelola::Url("/kampanye-prospek/{$kampanye->Uuid}/uji"))->assertSessionHas('Kilat');

        $pesan = Mail::mailer('kampanye')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        expect($pesan->getSubject())->toBe('[UJI] Kasir yang cocok untuk kafe & kedai kopi')
            ->and($pesan->getTo()[0]->getAddress())->toBe($petugas->Email)
            ->and($pesan->getFrom()[0]->getAddress())->toBe('info@kampanye.payoung.id')
            ->and($pesan->getTextBody())->toContain('/berhenti-prospek/');
        expect(KontakProspek::query()->where('Email', 'kopi@contoh.id')->sole()->Status)->toBe(StatusKontakProspek::Aktif);
    });
});

describe('D-216 pengiriman bertahap', function (): void {
    it('sedikit demi sedikit dan berbatas per hari. Email memuat sumber, tautan berhenti, dan List-Unsubscribe satu klik', function (): void {
        AktifkanEmailKampanyeUji();

        foreach (range(1, 12) as $nomor) {
            BuatKontakProspekUji("kopi{$nomor}@contoh.id");
        }

        $kampanye = JalankanKampanyeProspekUji(BuatKampanyeProspekUji(batas: 8));
        $kirim = app(KirimKampanyeProspekJatuhTempo::class);
        $transport = Mail::mailer('kampanye')->getSymfonyTransport();

        expect($kirim->Jalankan())->toBe(['Kampanye' => 1, 'Terkirim' => 5])
            ->and($kirim->Jalankan())->toBe(['Kampanye' => 1, 'Terkirim' => 3])
            // Jatah hari ini (8) habis: tidak ada yang terkirim lagi sampai besok.
            ->and($kirim->Jalankan()['Terkirim'])->toBe(0)
            ->and($transport->messages())->toHaveCount(8)
            ->and($kampanye->refresh()->JumlahTerkirim)->toBe(8)
            ->and($kampanye->Status)->toBe(StatusKampanyeProspek::Berjalan);

        $pesan = $transport->messages()->first()->getOriginalMessage();
        $tautan = (string) $pesan->getHeaders()->get('List-Unsubscribe')?->getBodyAsString();

        expect($pesan->getSubject())->toBe('Kasir yang cocok untuk kafe & kedai kopi')
            ->and($pesan->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
            ->and($tautan)->toStartWith('<')->toContain('/berhenti-prospek/')->toContain('signature=')
            ->and($pesan->getTextBody())->toContain('usaha kafe & kedai kopi')->toContain('Data Pelaku Ekonomi Kreatif di Kota Pontianak')->toContain('/berhenti-prospek/')
            ->and($pesan->getHtmlBody())->toContain('Berhenti berlangganan')->toContain('Data Pelaku Ekonomi Kreatif di Kota Pontianak');

        // Besok jatahnya baru: sisa 4 penerima terkirim dan kampanye selesai.
        $this->travelTo(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));

        expect($kirim->Jalankan())->toBe(['Kampanye' => 1, 'Terkirim' => 4])
            ->and($kampanye->refresh()->Status)->toBe(StatusKampanyeProspek::Selesai)
            ->and($kampanye->JumlahTerkirim)->toBe(12)
            ->and($kampanye->SelesaiPada)->not->toBeNull();
    });

    it('di luar jam kerja (sebelum 08.00 dan sesudah 17.00 WIB) tidak ada yang dikirim', function (): void {
        AktifkanEmailKampanyeUji();
        BuatKontakProspekUji('kopi@contoh.id');
        JalankanKampanyeProspekUji(BuatKampanyeProspekUji());

        foreach (['2026-10-26 07:59:00', '2026-10-26 17:00:00', '2026-10-26 22:30:00'] as $waktu) {
            $this->travelTo(Carbon::parse($waktu, 'Asia/Jakarta'));

            expect(app(KirimKampanyeProspekJatuhTempo::class)->Jalankan())->toBe(['Kampanye' => 0, 'Terkirim' => 0]);
        }

        expect(Mail::mailer('kampanye')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('kontak yang berhenti setelah kampanye dijalankan dilewati dan tidak dikirimi', function (): void {
        AktifkanEmailKampanyeUji();
        $tetap = BuatKontakProspekUji('tetap@contoh.id');
        $pergi = BuatKontakProspekUji('pergi@contoh.id');
        $kampanye = JalankanKampanyeProspekUji(BuatKampanyeProspekUji());
        $pergi->update(['Status' => StatusKontakProspek::Berhenti]);

        $hasil = app(KirimKampanyeProspekJatuhTempo::class)->Jalankan();
        $penerima = PenerimaKampanyeProspek::query()->where('IdKontakProspek', $pergi->Id)->sole();

        expect($hasil['Terkirim'])->toBe(1)
            ->and($penerima->Status)->toBe(StatusPenerimaSiaran::Dilewati)
            ->and($penerima->Alasan)->toBe('Sudah berhenti berlangganan.')
            ->and(PenerimaKampanyeProspek::query()->where('IdKontakProspek', $tetap->Id)->sole()->Status)->toBe(StatusPenerimaSiaran::Terkirim)
            ->and($kampanye->refresh()->Status)->toBe(StatusKampanyeProspek::Selesai)
            ->and($kampanye->JumlahDilewati)->toBe(1);
    });

    it('lima kegagalan beruntun menjeda kampanye dengan alasan. Email kampanye dimatikan juga menjeda', function (): void {
        AktifkanEmailKampanyeUji(['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]);

        foreach (range(1, 7) as $nomor) {
            BuatKontakProspekUji("kopi{$nomor}@contoh.id");
        }

        $kampanye = JalankanKampanyeProspekUji(BuatKampanyeProspekUji());
        $hasil = app(KirimKampanyeProspekJatuhTempo::class)->Jalankan();

        expect($hasil['Terkirim'])->toBe(0)
            ->and($kampanye->refresh()->Status)->toBe(StatusKampanyeProspek::Dijeda)
            ->and($kampanye->AlasanJeda)->toContain('Lima email terakhir gagal')
            ->and($kampanye->JumlahGagal)->toBe(5)
            ->and(PenerimaKampanyeProspek::query()->where('Status', StatusPenerimaSiaran::Menunggu->value)->count())->toBe(2);

        // Dilanjutkan, tetapi pengirimnya dimatikan di Integrasi: dijeda lagi, tidak ada yang terkirim.
        app(UbahStatusKampanyeProspek::class)->Jalankan(PelakuKampanyeUji(), $kampanye, StatusKampanyeProspek::Berjalan);
        config(['integrasi.EmailKampanyeAktif' => false]);

        expect(app(KirimKampanyeProspekJatuhTempo::class)->Jalankan()['Terkirim'])->toBe(0)
            ->and($kampanye->refresh()->Status)->toBe(StatusKampanyeProspek::Dijeda)
            ->and($kampanye->AlasanJeda)->toContain('tidak aktif');
    });

    it('perintah prospek:kirim-kampanye berjalan', function (): void {
        AktifkanEmailKampanyeUji();
        BuatKontakProspekUji('kopi@contoh.id');
        JalankanKampanyeProspekUji(BuatKampanyeProspekUji());

        $this->artisan('prospek:kirim-kampanye')->expectsOutputToContain('1 kampanye diproses, 1 email terkirim.')->assertSuccessful();
    });
});

describe('D-216 pengirim bawaan bila Email kampanye kosong', function (): void {
    function PasangEmailBawaanUji(string $mailer = 'smtp'): void
    {
        config([
            'integrasi.EmailKampanyeAktif' => false,
            'mail.default' => $mailer,
            'mail.mailers.smtp' => ['transport' => 'array'],
            'mail.from.address' => 'halo@payoung.id',
            'mail.from.name' => 'Payoung',
        ]);
    }

    it('tanpa Email kampanye tetapi dengan email bawaan sungguhan: tersedia, memakai alamat pengirim bawaan', function (): void {
        PasangEmailBawaanUji();

        expect(PengirimKampanyeProspek::MemakaiPengirimKhusus())->toBeFalse()
            ->and(PengirimKampanyeProspek::MemakaiPengirimBawaan())->toBeTrue()
            ->and(PengirimKampanyeProspek::Tersedia())->toBeTrue()
            ->and(PengirimKampanyeProspek::AlamatPengirim())->toBe('halo@payoung.id')
            ->and(PengirimKampanyeProspek::NamaPengirim())->toBe('Payoung');
    });

    it('pengirim bawaan palsu (log/array) atau tanpa alamat pengirim tidak dianggap tersedia', function (): void {
        PasangEmailBawaanUji('log');
        expect(PengirimKampanyeProspek::Tersedia())->toBeFalse();

        PasangEmailBawaanUji('array');
        expect(PengirimKampanyeProspek::Tersedia())->toBeFalse();

        PasangEmailBawaanUji();
        config(['mail.from.address' => '']);
        expect(PengirimKampanyeProspek::Tersedia())->toBeFalse();
    });

    it('Email kampanye aktif tetap didahulukan atas email bawaan', function (): void {
        PasangEmailBawaanUji();
        AktifkanEmailKampanyeUji();

        expect(PengirimKampanyeProspek::MemakaiPengirimKhusus())->toBeTrue()
            ->and(PengirimKampanyeProspek::MemakaiPengirimBawaan())->toBeFalse()
            ->and(PengirimKampanyeProspek::AlamatPengirim())->toBe('info@kampanye.payoung.id');
    });

    it('kampanye berjalan dan terkirim lewat email bawaan, dengan email uji dari alamat bawaan', function (): void {
        PasangEmailBawaanUji();
        BuatKontakProspekUji('kopi@contoh.id');
        $kampanye = BuatKampanyeProspekUji();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->post(BantuanPengelola::Url("/kampanye-prospek/{$kampanye->Uuid}/uji"))->assertSessionHas('Kilat');
        JalankanKampanyeProspekUji($kampanye);

        expect(app(KirimKampanyeProspekJatuhTempo::class)->Jalankan())->toBe(['Kampanye' => 1, 'Terkirim' => 1]);

        $pesan = Mail::mailer('smtp')->getSymfonyTransport()->messages();

        expect($pesan)->toHaveCount(2)
            ->and($pesan->last()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('halo@payoung.id')
            ->and($pesan->last()->getOriginalMessage()->getTextBody())->toContain('/berhenti-prospek/');
    });

    it('tanpa pengirim apa pun kampanye tidak bisa dijalankan', function (): void {
        config(['integrasi.EmailKampanyeAktif' => false, 'mail.default' => 'array']);
        BuatKontakProspekUji('kopi@contoh.id');
        $kampanye = BuatKampanyeProspekUji();

        expect(fn () => JalankanKampanyeProspekUji($kampanye))->toThrow(PelanggaranAturanBisnis::class, 'Belum ada pengirim email');
    });
});

describe('D-216 berhenti berlangganan publik', function (): void {
    it('GET hanya konfirmasi, POST menghentikan. Tautan diubah atau tanda tangan hilang = 404. Alamat email tak pernah tampil', function (): void {
        $kontak = BuatKontakProspekUji('rahasia@contoh.id');
        $tautan = TautanBerhentiProspek::Buat($kontak);

        $respons = $this->get($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/BerhentiProspek')->where('Ditemukan', true)->where('SudahBerhenti', false));

        expect($respons->getContent())->not->toContain('rahasia@contoh.id')
            ->and($kontak->refresh()->Status)->toBe(StatusKontakProspek::Aktif);

        $this->post($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('SudahBerhenti', true));
        $this->post($tautan)->assertOk();

        expect($kontak->refresh()->Status)->toBe(StatusKontakProspek::Berhenti)
            ->and($kontak->BerhentiPada)->not->toBeNull();

        $lain = BuatKontakProspekUji('lain@contoh.id');
        $this->get(str_replace($kontak->Uuid, $lain->Uuid, $tautan))->assertNotFound();
        $this->get(strtok($tautan, '?'))->assertNotFound();
        $this->post(strtok($tautan, '?'))->assertNotFound();
        expect($lain->refresh()->Status)->toBe(StatusKontakProspek::Aktif);
    });
});
