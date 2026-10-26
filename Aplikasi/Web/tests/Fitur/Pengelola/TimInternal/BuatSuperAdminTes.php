<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Pengelola\TimInternal\Model\PeranPengelola;

describe('P-01 langkah 1–2: Super Admin pertama & peran bawaan', function (): void {
    it('membuat Super Admin lewat perintah server beserta tujuh peran bawaan', function (): void {
        $this->artisan('pengelola:buat-super-admin', ['--nama' => 'Rina Pengelola', '--email' => 'Rina@Contoh.id'])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf dan angka)', 'rahasia-kuat-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-kuat-123')
            ->assertSuccessful();

        $pengguna = PenggunaPengelola::query()->where('Email', 'rina@contoh.id')->firstOrFail();

        expect($pengguna->PunyaPeran(PeranPengelolaBawaan::SuperAdmin))->toBeTrue()
            ->and($pengguna->PunyaIzin(IzinPengelola::TimAnggotaUndang))->toBeTrue()
            ->and($pengguna->CekDuaFaktorAktif())->toBeFalse()
            ->and(PeranPengelola::query()->orderBy('Id')->pluck('Kode')->all())
            ->toBe(array_map(fn (PeranPengelolaBawaan $peran) => $peran->value, PeranPengelolaBawaan::cases()));

        $log = LogAuditPengelola::query()->where('Aksi', 'tim.anggota.buat-super-admin')->sole();
        expect($log->IdPenggunaPengelola)->toBeNull()
            ->and($log->IdObjek)->toBe($pengguna->Id)
            ->and($log->NilaiBaru)->not->toHaveKey('KataSandi');
    });

    it('pengelola:siapkan-peran memberi izin baru ke peran bawaan yang sudah ada tanpa menggandakan', function (): void {
        $this->artisan('pengelola:buat-super-admin', ['--nama' => 'Rina Pengelola', '--email' => 'rina@contoh.id'])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf dan angka)', 'rahasia-kuat-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-kuat-123')
            ->assertSuccessful();

        $peran = PeranPengelola::query()->where('Kode', PeranPengelolaBawaan::SuperAdmin->value)->firstOrFail();
        $peran->Izin()->where('KunciIzin', IzinPengelola::KomunikasiKelola->value)->delete();
        $pengguna = PenggunaPengelola::query()->where('Email', 'rina@contoh.id')->firstOrFail();
        $pengguna->LupakanIzin();

        expect($pengguna->PunyaIzin(IzinPengelola::KomunikasiKelola))->toBeFalse();

        $this->artisan('pengelola:siapkan-peran')->assertSuccessful();
        $this->artisan('pengelola:siapkan-peran')->assertSuccessful();

        $pengguna->LupakanIzin();

        expect($pengguna->PunyaIzin(IzinPengelola::KomunikasiKelola))->toBeTrue()
            ->and($peran->Izin()->count())->toBe(count(IzinPengelola::cases()));
    });

    it('menolak kata sandi lemah atau konfirmasi yang berbeda', function (string $kataSandi, string $konfirmasi): void {
        $this->artisan('pengelola:buat-super-admin', ['--nama' => 'Rina', '--email' => 'rina@contoh.id'])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf dan angka)', $kataSandi)
            ->expectsQuestion('Ulangi kata sandi', $konfirmasi)
            ->assertFailed();

        expect(PenggunaPengelola::query()->count())->toBe(0);
    })->with([
        'terlalu pendek' => ['pendek123', 'pendek123'],
        'tanpa angka' => ['hanyahurufsaja', 'hanyahurufsaja'],
        'konfirmasi beda' => ['rahasia-kuat-123', 'rahasia-kuat-124'],
    ]);

    it('menolak email yang sudah terdaftar sebagai pengelola', function (): void {
        PenggunaPengelola::factory()->create(['Email' => 'rina@contoh.id']);

        $this->artisan('pengelola:buat-super-admin', ['--nama' => 'Rina', '--email' => 'rina@contoh.id'])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf dan angka)', 'rahasia-kuat-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-kuat-123')
            ->assertFailed();
    });

    it('menyiapkan peran bawaan secara idempoten lewat seeder', function (): void {
        $this->seed();
        $this->seed();

        expect(PeranPengelola::query()->count())->toBe(7)
            ->and(PeranPengelola::query()->where('Kode', 'Analis')->sole()->Izin()->pluck('KunciIzin')->all())->toEqualCanonicalizing(['referensi.lihat', 'katalog.lihat', 'template.lihat', 'legal.lihat', 'analitik.lihat']);
    });
});
