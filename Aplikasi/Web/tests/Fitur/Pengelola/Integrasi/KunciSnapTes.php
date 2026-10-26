<?php

declare(strict_types=1);

use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use App\Domain\Pengelola\Integrasi\Aksi\BuatKunciSnapIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\StatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuBilling;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * D-159: pasangan kunci RSA untuk DOKU SNAP dibuat server di konsol Integrasi. Yang dijaga: kunci pribadi tidak pernah
 * keluar ke peramban, status/keaktifan integrasi Checkout tidak terganggu, kunci selamat dari simpan ulang, dan uji
 * koneksi ikut menguji token SNAP tanpa membuat Checkout gagal diaktifkan.
 */

function BuatKonfigurasiDokuBillingUji(array $kredensial = ['KunciRahasia' => 'SK-uji-rahasia']): KonfigurasiIntegrasi
{
    return KonfigurasiIntegrasi::query()->create([
        'Jenis' => 'GerbangBilling', 'Lingkungan' => 'Staging', 'Penyedia' => 'DokuBilling',
        'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-0201-1'], 'Kredensial' => $kredensial,
        'PetunjukKredensial' => ['KunciRahasia' => '••••'], 'KredensialDiubahPada' => now(),
        'Aktif' => true, 'Status' => StatusIntegrasi::Terhubung,
    ]);
}

describe('D-159 kunci SNAP di konsol Integrasi', function (): void {
    it('membuat pasangan kunci: kunci pribadi terenkripsi, publik tampil di daftar, status & keaktifan tidak berubah, audit mencatat sidik jari', function (): void {
        $pelaku = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $konfigurasi = BuatKonfigurasiDokuBillingUji();

        $this->actingAs($pelaku, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/kunci-snap"))
            ->assertRedirect();

        $konfigurasi->refresh();
        expect($konfigurasi->Kredensial['KunciPribadiSnap'])->toContain('PRIVATE KEY')
            ->and($konfigurasi->Kredensial['KunciPublikSnap'])->toStartWith('-----BEGIN PUBLIC KEY-----')
            ->and($konfigurasi->Kredensial['KunciRahasia'])->toBe('SK-uji-rahasia')
            ->and($konfigurasi->Aktif)->toBeTrue()
            ->and($konfigurasi->Status)->toBe(StatusIntegrasi::Terhubung)
            // Di basis data kunci pribadi tidak pernah tersimpan terbuka.
            ->and((string) DB::table('KonfigurasiIntegrasi')->where('Id', $konfigurasi->Id)->value('Kredensial'))->not->toContain('PRIVATE KEY');

        $log = LogAuditPengelola::query()->where('Aksi', 'integrasi.kunci-snap-dibuat')->sole();
        expect($log->NilaiBaru['SidikJariKunciPublik'])->toHaveLength(64)
            ->and(json_encode($log->NilaiBaru))->not->toContain('PRIVATE KEY');

        // Daftar integrasi mengirim kunci publik, tidak pernah kunci pribadi.
        $daftar = $this->actingAs($pelaku, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())->get(BantuanPengelola::Url('/integrasi'))->assertOk();
        $isi = json_encode($daftar->viewData('page')['props']['Integrasi']);
        expect($isi)->toContain('BEGIN PUBLIC KEY')->and($isi)->not->toContain('PRIVATE KEY');
    });

    it('mengganti kunci mencatat aksi berbeda dan kunci lama tidak dipakai lagi', function (): void {
        $pelaku = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $konfigurasi = BuatKonfigurasiDokuBillingUji();
        $buat = app(BuatKunciSnapIntegrasi::class);

        $pertama = $buat->Jalankan($pelaku, $konfigurasi);
        $kedua = $buat->Jalankan($pelaku, $konfigurasi->refresh());

        expect($kedua)->not->toBe($pertama)
            ->and($konfigurasi->refresh()->Kredensial['KunciPublikSnap'])->toBe($kedua)
            ->and(LogAuditPengelola::query()->where('Aksi', 'integrasi.kunci-snap-dibuat')->count())->toBe(1)
            ->and(LogAuditPengelola::query()->where('Aksi', 'integrasi.kunci-snap-diganti')->count())->toBe(1);
    });

    it('hanya penyedia DokuBilling yang boleh punya kunci SNAP', function (): void {
        $pelaku = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $lain = KonfigurasiIntegrasi::query()->create([
            'Jenis' => 'Email', 'Lingkungan' => 'Staging', 'Penyedia' => 'Smtp', 'Pengaturan' => [],
            'Kredensial' => ['KataSandi' => 'x'], 'PetunjukKredensial' => [], 'KredensialDiubahPada' => now(),
        ]);

        $this->actingAs($pelaku, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url("/integrasi/{$lain->Uuid}/kunci-snap"))
            ->assertSessionHasErrors();

        expect(isset($lain->refresh()->Kredensial['KunciPribadiSnap']))->toBeFalse();
    });

    it('peran selain Teknis dan Super Admin ditolak', function (PeranPengelolaBawaan $peran): void {
        $pelaku = BantuanPengelola::BuatAnggota($peran);
        $konfigurasi = BuatKonfigurasiDokuBillingUji();

        $this->actingAs($pelaku, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/kunci-snap"))
            ->assertForbidden();
    })->with([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Analis]);

    it('menyimpan ulang konfigurasi tidak menghapus kunci SNAP dan tidak menampilkannya sebagai isian', function (): void {
        $pelaku = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $konfigurasi = BuatKonfigurasiDokuBillingUji();
        app(BuatKunciSnapIntegrasi::class)->Jalankan($pelaku, $konfigurasi);
        $publik = $konfigurasi->refresh()->Kredensial['KunciPublikSnap'];

        $this->actingAs($pelaku, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/integrasi'), [
                'Jenis' => 'GerbangBilling', 'Lingkungan' => 'Staging', 'Penyedia' => 'DokuBilling',
                'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-0201-1', 'IdPedagangQris' => 'MID1', 'IdTerminalQris' => 'T01', 'KodePosQris' => '12345', 'KanalVa' => 'BNI|VIRTUAL_ACCOUNT_BNI|98829172|3', 'DompetDana' => 'Aktif', 'DompetShopeePay' => 'Mati'],
                'Kredensial' => ['KunciRahasia' => ''],
                'RotasiSetiapHari' => 90, 'Alasan' => null,
            ])
            ->assertSessionHasNoErrors();

        $konfigurasi->refresh();
        expect($konfigurasi->Kredensial['KunciPublikSnap'])->toBe($publik)
            ->and($konfigurasi->Kredensial['KunciPribadiSnap'])->toContain('PRIVATE KEY')
            ->and($konfigurasi->Pengaturan['IdPedagangQris'])->toBe('MID1')
            // Ganti pengaturan = harus diuji ulang (BR-P05.4), seperti integrasi lain.
            ->and($konfigurasi->Aktif)->toBeFalse()
            ->and(array_column(PenyediaIntegrasi::DokuBilling->AmbilBidangKredensial(), 'Kunci'))->toBe(['KunciRahasia']);
    });

    it('uji koneksi: token SNAP diterima = keterangan sukses. Ditolak = keterangan "belum siap" tanpa menggagalkan Checkout', function (): void {
        $pasangan = (new PembuatKunciSnap)->Buat();
        $penguji = new PengujiDokuBilling;
        $pengaturan = ['Mode' => 'Sandbox', 'IdKlien' => 'BRN-0201-1'];
        $kredensial = ['KunciRahasia' => 'SK-uji', 'KunciPribadiSnap' => $pasangan['Pribadi']];

        $jawabanToken = Http::response(['responseCode' => '2007300', 'accessToken' => 'T', 'expiresIn' => 900]);
        Http::fake(function (Request $permintaan) use (&$jawabanToken) {
            return str_ends_with($permintaan->url(), '/authorization/v1/access-token/b2b') ? $jawabanToken : Http::response([], 404);
        });
        $berhasil = $penguji->Uji($pengaturan, $kredensial);
        expect($berhasil->berhasil)->toBeTrue()->and($berhasil->pesan)->toContain('SNAP: Token SNAP diterima DOKU');

        $jawabanToken = Http::response(['responseCode' => '4017300', 'responseMessage' => 'Unauthorized. Signature'], 401);
        $ditolak = $penguji->Uji($pengaturan, $kredensial);
        expect($ditolak->berhasil)->toBeTrue()->and($ditolak->pesan)->toContain('SNAP belum siap')->toContain('Merchant Public Key');

        $tanpaKunci = $penguji->Uji($pengaturan, ['KunciRahasia' => 'SK-uji']);
        expect($tanpaKunci->berhasil)->toBeTrue()->and($tanpaKunci->pesan)->toContain('SNAP: kunci belum dibuat');
    });
});
