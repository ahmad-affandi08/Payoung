<?php

declare(strict_types=1);

use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Migrasi 2026_11_23_000172_HapusDataGerbangTokoNonDoku: seluruh tenant memakai DOKU, jadi data gerbang toko milik
 * Midtrans/Xendit/Tripay/Duitku/iPaymu (gerbang tenant, katalog izin, konfigurasi platform lama) dihapus supaya
 * tidak ada baris dengan nilai penyedia yang tidak dikenal enum. Gerbang DOKU dan gerbang billing platform
 * (jenis `GerbangBilling`) tidak boleh tersentuh oleh migrasi ini (baris billing lama dibersihkan migrasi 000173). Idempoten dan aman bila tabel kosong.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function JalankanMigrasiHapusGerbangNonDoku(): void
{
    $migrasi = require database_path('migrations/2026_11_23_000172_HapusDataGerbangTokoNonDoku.php');
    $migrasi->up();
}

function SisipkanGerbangMentah(int $idTenant, string $penyedia): void
{
    DB::table('GerbangPembayaranTenant')->insert([
        'Uuid' => (string) Str::ulid(),
        'IdTenant' => $idTenant,
        'Penyedia' => $penyedia,
        'Lingkungan' => 'Sandbox',
        'Pengaturan' => '{}',
        'Kredensial' => 'terenkripsi-uji',
        'PetunjukKredensial' => '{}',
        'StatusUji' => 'Berhasil',
        'Aktif' => true,
        'TokenWebhook' => base_convert((string) $idTenant, 10, 36).'-'.Str::random(40),
        'DibuatPada' => now(),
        'DiubahPada' => now(),
    ]);
}

function SisipkanKonfigurasiMentah(string $jenis, string $lingkungan, string $penyedia): void
{
    DB::table('KonfigurasiIntegrasi')->insert([
        'Uuid' => (string) Str::ulid(),
        'Jenis' => $jenis,
        'Lingkungan' => $lingkungan,
        'Penyedia' => $penyedia,
        'Pengaturan' => '{}',
        'Kredensial' => 'terenkripsi-uji',
        'PetunjukKredensial' => '{}',
        'KredensialDiubahPada' => now(),
        'DibuatPada' => now(),
        'DiubahPada' => now(),
    ]);
}

it('menghapus gerbang tenant & izin katalog penyedia lama, menyisakan DOKU. Gerbang billing platform tidak tersentuh', function (): void {
    $a = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Solo');
    $b = BantuanOrganisasi::BuatTenant('Warung Makan Sederhana Klaten');
    $c = BantuanOrganisasi::BuatTenant('Bengkel Motor Jaya Sragen');
    SisipkanGerbangMentah($a['Tenant']->Id, 'Midtrans');
    SisipkanGerbangMentah($b['Tenant']->Id, 'Doku');
    SisipkanGerbangMentah($c['Tenant']->Id, 'Ipaymu');
    foreach (['Midtrans', 'Xendit', 'Tripay', 'Duitku', 'Ipaymu'] as $lama) {
        DB::table('KatalogGerbangPembayaran')->insert(['Penyedia' => $lama, 'Diizinkan' => false, 'DibuatPada' => now(), 'DiubahPada' => now()]);
    }
    DB::table('KatalogGerbangPembayaran')->insert(['Penyedia' => 'Doku', 'Diizinkan' => false, 'DibuatPada' => now(), 'DiubahPada' => now()]);
    SisipkanKonfigurasiMentah('GerbangPembayaran', 'Staging', 'Midtrans');
    SisipkanKonfigurasiMentah('GerbangPembayaran', 'Produksi', 'Doku');
    SisipkanKonfigurasiMentah('GerbangBilling', 'Staging', 'MidtransBilling');

    JalankanMigrasiHapusGerbangNonDoku();

    expect(DB::table('GerbangPembayaranTenant')->pluck('Penyedia')->all())->toBe(['Doku'])
        ->and(DB::table('KatalogGerbangPembayaran')->pluck('Penyedia')->all())->toBe(['Doku'])
        ->and(DB::table('KonfigurasiIntegrasi')->orderBy('Id')->pluck('Penyedia', 'Jenis')->all())
        ->toBe(['GerbangPembayaran' => 'Doku', 'GerbangBilling' => 'MidtransBilling']);

    // Sisa baris terbaca normal oleh model (nilai enum semuanya dikenal) dan migrasi bisa diulang tanpa efek.
    JalankanMigrasiHapusGerbangNonDoku();
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(GerbangPembayaranTenant::query()->count())->toBe(1)
        ->and(KonfigurasiIntegrasi::query()->count())->toBe(2)
        ->and(DB::table('GerbangPembayaranTenant')->count())->toBe(1);
});

it('aman dijalankan bila semua tabel kosong', function (): void {
    JalankanMigrasiHapusGerbangNonDoku();

    expect(DB::table('GerbangPembayaranTenant')->count())->toBe(0)
        ->and(DB::table('KatalogGerbangPembayaran')->count())->toBe(0)
        ->and(DB::table('KonfigurasiIntegrasi')->count())->toBe(0);
});
