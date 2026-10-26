<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Lisensi\Kontrak\PengaturIntegrasiServer;
use App\Domain\Organisasi\Layanan\PembuatQrKodeAktivasi;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\Pengelola\DataBawaan\Aksi\EksporDataMasterLisensi;
use App\Domain\Pengelola\DataBawaan\Aksi\ImporDataMasterLisensi;
use App\Domain\Pengelola\Integrasi\Aksi\SimpanKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Data\DataKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Referensi\Enum\JenisHariLibur;
use App\Domain\Referensi\Model\HariLibur;
use Illuminate\Support\Facades\Route;

/*
 * D-35: jalan pintas edisi Lisensi (data bawaan langsung terbit, integrasi tanpa pelaku konsol) tertutup di edisi SaaS,
 * tempat four-eyes & audit pengelola tetap berlaku.
 */
describe('Edisi SaaS menolak jalan pintas edisi Lisensi (D-35)', function (): void {
    it('lisensi:siapkan-data dan lisensi:atur-integrasi ditolak', function (): void {
        $this->artisan('lisensi:siapkan-data')->expectsOutputToContain('hanya di edisi Lisensi')->assertFailed();
        $this->artisan('lisensi:atur-integrasi', ['jenis' => 'Email'])->expectsOutputToContain('hanya untuk edisi Lisensi')->assertFailed();
    });

    it('ekspor data master hanya berisi tarif & hari libur terbit (dan pembatalannya), lalu impor ditolak di SaaS', function (): void {
        app(SiapkanPajakBawaan::class)->Jalankan();
        $ppn = JenisPajak::query()->where('Kode', 'Ppn')->sole();
        TarifPajak::query()->create(['IdJenisPajak' => $ppn->Id, 'Tarif' => '12', 'PengaliDppPembilang' => 11, 'PengaliDppPenyebut' => 12, 'BerlakuMulai' => '2025-01-01', 'Status' => StatusDataMaster::Terbit, 'NomorDasarHukum' => 'PMK 131 Tahun 2024']);
        HariLibur::query()->create(['Tanggal' => '2026-08-17', 'Nama' => 'Hari Kemerdekaan RI', 'Jenis' => JenisHariLibur::Nasional, 'Status' => StatusDataMaster::Terbit]);
        HariLibur::query()->create(['Tanggal' => '2026-12-24', 'Nama' => 'Cuti Bersama Natal', 'Jenis' => JenisHariLibur::CutiBersama, 'Status' => StatusDataMaster::Dibatalkan, 'DibatalkanPada' => now()]);
        HariLibur::query()->create(['Tanggal' => '2026-12-31', 'Nama' => 'Draf belum ditinjau', 'Jenis' => JenisHariLibur::Nasional]);

        $paket = app(EksporDataMasterLisensi::class)->Jalankan();

        expect($paket['TarifPajak'])->toHaveCount(1)
            ->and($paket['TarifPajak'][0]['KodeJenisPajak'])->toBe('Ppn')
            ->and($paket['TarifPajak'][0]['BerlakuMulai'])->toBe('2025-01-01')
            ->and(array_column($paket['HariLibur'], 'Nama'))->toBe(['Hari Kemerdekaan RI', 'Cuti Bersama Natal'])
            ->and($paket['HariLibur'][1]['Dibatalkan'])->toBeTrue()
            ->and(fn () => app(ImporDataMasterLisensi::class)->Jalankan((string) json_encode($paket)))->toThrow(PelanggaranAturanBisnis::class, 'edisi Lisensi');
    });

    it('halaman integrasi server Owner tidak ada di SaaS, dan kontraknya menolak dipanggil langsung', function (): void {
        expect(Route::has('kelola.pengaturan.integrasi-server'))->toBeFalse()
            ->and(Route::has('kelola.pengaturan.integrasi-server.simpan'))->toBeFalse();
        expect(fn () => app(PengaturIntegrasiServer::class)->AmbilDaftar())->toThrow(PelanggaranAturanBisnis::class);
    });

    it('D-36: penanda edisi terkunci paket Payoung Mandiri menang atas EDISI di .env. Repo tidak membawanya', function (): void {
        $berkas = base_path('bootstrap/EdisiTerkunci.php');
        expect(is_file($berkas))->toBeFalse()
            ->and(config('lisensi.EdisiTerkunci'))->toBeFalse();

        file_put_contents($berkas, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'Lisensi';\n");

        try {
            $konfigurasi = require config_path('lisensi.php');
        } finally {
            unlink($berkas);
        }

        expect(env('EDISI'))->toBe('Saas')
            ->and($konfigurasi['Edisi'])->toBe('Lisensi')
            ->and($konfigurasi['EdisiTerkunci'])->toBeTrue();
    });

    it('QR aktivasi perangkat tetap berisi kode saja', function (): void {
        expect(PembuatQrKodeAktivasi::AmbilIsi('AB12CD34'))->toBe('AB12CD34');
    });

    it('menyimpan konfigurasi integrasi tanpa anggota Platform Pengelola ditolak', function (): void {
        $data = new DataKonfigurasiIntegrasi(JenisIntegrasi::Whatsapp, LingkunganIntegrasi::Staging, [], ['Token' => 'x'], 90, null, PenyediaIntegrasi::Fonnte);

        expect(fn () => app(SimpanKonfigurasiIntegrasi::class)->Jalankan(null, $data))->toThrow(PelanggaranAturanBisnis::class, 'Platform Pengelola')
            ->and(KonfigurasiIntegrasi::query()->count())->toBe(0);
    });
});
