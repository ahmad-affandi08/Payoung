<?php

declare(strict_types=1);

use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PaketFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Server yang dipasang sebelum sebuah fitur ditambahkan ke katalog tidak pernah mendapat fitur itu pada paket yang
 * sudah ada (seeder hanya membuat paket baru). Contoh nyata: persetujuan jarak jauh pada Bisnis tidak muncul di kasir.
 * `katalog:lengkapi-fitur` menambah saja, tidak pernah mencabut, dan tidak memasang ulang fitur lama yang sengaja dicabut
 * lewat konsol walau berkas data masih mencantumkannya (D-260).
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('katalog:lengkapi-fitur', function (): void {
    it('memasang fitur katalog yang baru dibuat ke paket; --kering tidak mengubah; idempoten; tidak mencabut apa pun', function (): void {
        ['Tenant' => $tenant] = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('bisnis@contoh.id', '081234500001', 'BISNIS', 'Toko Bisnis'));
        $bisnis = Paket::query()->where('Kode', 'BISNIS')->sole();
        // Server lama: fitur ini belum pernah ada di katalog (baris Fitur dan semua kaitannya ke paket tidak ada).
        PaketFitur::query()->where('KunciFitur', 'persetujuan.jarak-jauh')->delete();
        Fitur::query()->where('Kunci', 'persetujuan.jarak-jauh')->delete();
        // Fitur yang sengaja ditambahkan tim lewat konsol tetap ada setelah dilengkapi.
        Fitur::query()->firstOrCreate(['Kunci' => 'uji.khusus'], ['Nama' => 'Uji khusus', 'Modul' => 'Uji']);
        PaketFitur::query()->create(['IdPaket' => $bisnis->Id, 'KunciFitur' => 'uji.khusus']);
        $jumlahFiturSebelum = Fitur::query()->count();
        $jumlahSebelum = PaketFitur::query()->count();

        expect(app(PemeriksaFiturTenant::class)->CekAktif($tenant->Id, 'persetujuan.jarak-jauh'))->toBeFalse();

        $this->artisan('katalog:lengkapi-fitur', ['--kering' => true])
            ->expectsOutputToContain('[kering] paket BISNIS + persetujuan.jarak-jauh')
            ->assertSuccessful();
        expect(PaketFitur::query()->count())->toBe($jumlahSebelum)
            ->and(Fitur::query()->count())->toBe($jumlahFiturSebelum);

        $this->artisan('katalog:lengkapi-fitur')->expectsOutputToContain('paket BISNIS + persetujuan.jarak-jauh')->assertSuccessful();
        expect(PaketFitur::query()->where('IdPaket', $bisnis->Id)->where('KunciFitur', 'persetujuan.jarak-jauh')->exists())->toBeTrue()
            ->and(app(PemeriksaFiturTenant::class)->CekAktif($tenant->Id, 'persetujuan.jarak-jauh'))->toBeTrue()
            ->and(PaketFitur::query()->where('IdPaket', $bisnis->Id)->where('KunciFitur', 'uji.khusus')->exists())->toBeTrue();
        $jumlahSesudah = PaketFitur::query()->count();

        $this->artisan('katalog:lengkapi-fitur')->expectsOutputToContain('Menambah 0 fitur katalog dan 0 fitur ke paket.')->assertSuccessful();
        expect(PaketFitur::query()->count())->toBe($jumlahSesudah);
    });

    it('tidak memasang ulang fitur lama yang sengaja dicabut dari paket lewat konsol, kecuali --termasuk-lama', function (): void {
        ['Tenant' => $tenant] = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('bisnis@contoh.id', '081234500001', 'BISNIS', 'Toko Bisnis'));
        $bisnis = Paket::query()->where('Kode', 'BISNIS')->sole();
        // Fitur ada di katalog dan di berkas data untuk Bisnis, tetapi Owner platform mencabutnya dari paket lewat konsol.
        expect(Fitur::query()->where('Kunci', 'persetujuan.jarak-jauh')->exists())->toBeTrue();
        PaketFitur::query()->where('IdPaket', $bisnis->Id)->where('KunciFitur', 'persetujuan.jarak-jauh')->delete();
        $jumlahSebelum = PaketFitur::query()->count();

        $this->artisan('katalog:lengkapi-fitur', ['--kering' => true])->expectsOutputToContain('Akan menambah 0 fitur katalog dan 0 fitur ke paket.')->assertSuccessful();
        $this->artisan('katalog:lengkapi-fitur')->expectsOutputToContain('Menambah 0 fitur katalog dan 0 fitur ke paket.')->assertSuccessful();
        $this->artisan('katalog:lengkapi-fitur')->assertSuccessful();

        expect(PaketFitur::query()->count())->toBe($jumlahSebelum)
            ->and(app(PemeriksaFiturTenant::class)->CekAktif($tenant->Id, 'persetujuan.jarak-jauh'))->toBeFalse();

        // Opt-in sadar memulihkan semua fitur berkas yang belum dimiliki paket.
        $this->artisan('katalog:lengkapi-fitur', ['--termasuk-lama' => true])->expectsOutputToContain('paket BISNIS + persetujuan.jarak-jauh')->assertSuccessful();
        expect(app(PemeriksaFiturTenant::class)->CekAktif($tenant->Id, 'persetujuan.jarak-jauh'))->toBeTrue();
    });
});
