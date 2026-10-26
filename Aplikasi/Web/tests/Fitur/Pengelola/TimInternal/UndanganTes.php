<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Pengelola\TimInternal\Model\UndanganPengelola;
use App\Domain\Pengelola\TimInternal\Surel\UndanganTimInternal;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/**
 * Mengundang lewat HTTP sebagai Super Admin lalu mengembalikan path tautan undangan dari email.
 */
function UndangLewatHttp(TestCase $tes, PenggunaPengelola $superAdmin, string $email, array $kodePeran): string
{
    Mail::fake();

    $tes->actingAs($superAdmin, 'pengelola')
        ->withSession(BantuanPengelola::SesiTerverifikasi())
        ->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => $email, 'KodePeran' => $kodePeran])
        ->assertSessionHasNoErrors();

    $tautan = null;
    Mail::assertSent(UndanganTimInternal::class, function (UndanganTimInternal $surel) use ($email, &$tautan): bool {
        $tautan = $surel->Tautan();

        return $surel->hasTo(strtolower($email));
    });

    $tes->post(BantuanPengelola::Url('/keluar'));

    return (string) parse_url((string) $tautan, PHP_URL_PATH);
}

describe('Undangan anggota tim (P-01 langkah 3–5)', function (): void {
    it('Super Admin mengundang lewat email. Token hanya tersimpan sebagai hash', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);

        $path = UndangLewatHttp($this, $superAdmin, 'Budi@Contoh.id', ['Dukungan', 'Keuangan']);
        $token = basename($path);

        $undangan = UndanganPengelola::query()->sole();
        expect($undangan->Email)->toBe('budi@contoh.id')
            ->and($undangan->HashToken)->toBe(hash('sha256', $token))
            ->and($undangan->HashToken)->not->toBe($token)
            ->and($undangan->BerlakuSampai->diffInHours(now(), true))->toEqualWithDelta(48, 0.01);

        $this->assertDatabaseHas('LogAuditPengelola', [
            'Aksi' => 'tim.anggota.undang',
            'IdPenggunaPengelola' => $superAdmin->Id,
            'JenisObjek' => 'UndanganPengelola',
        ]);
    });

    it('anggota menerima undangan, mendapat peran, lalu wajib mengaktifkan 2FA', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $path = UndangLewatHttp($this, $superAdmin, 'budi@contoh.id', ['Dukungan', 'Keuangan']);

        $this->get(BantuanPengelola::Url($path))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Undangan/Terima')
                ->where('Berlaku', true)
                ->where('Email', 'budi@contoh.id'));

        $this->post(BantuanPengelola::Url($path), [
            'Nama' => 'Budi Santoso',
            'KataSandi' => 'rahasia-kuat-123',
            'KonfirmasiKataSandi' => 'rahasia-kuat-123',
        ])->assertRedirect(route('pengelola.dua-faktor.aktifkan'));

        $anggota = PenggunaPengelola::query()->where('Email', 'budi@contoh.id')->sole();
        $log = LogAuditPengelola::query()->where('Aksi', 'tim.anggota.terima-undangan')->sole();
        expect(json_encode($log->NilaiBaru))->not->toContain('rahasia-kuat-123')
            ->and($log->NilaiBaru)->not->toHaveKey('KataSandi');
        expect($anggota->AmbilKodePeran())->toEqualCanonicalizing(['Dukungan', 'Keuangan'])
            ->and($anggota->CekDuaFaktorAktif())->toBeFalse();
        $this->assertAuthenticatedAs($anggota, 'pengelola');
        $this->get(BantuanPengelola::Url('/'))->assertRedirect(route('pengelola.dua-faktor.aktifkan'));
    });

    it('undangan hanya bisa dipakai sekali', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $path = UndangLewatHttp($this, $superAdmin, 'budi@contoh.id', ['Analis']);
        $data = ['Nama' => 'Budi', 'KataSandi' => 'rahasia-kuat-123', 'KonfirmasiKataSandi' => 'rahasia-kuat-123'];

        $this->post(BantuanPengelola::Url($path), $data);
        $this->post(BantuanPengelola::Url('/keluar'));

        $this->get(BantuanPengelola::Url($path))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Berlaku', false));
        $this->post(BantuanPengelola::Url($path), [...$data, 'Nama' => 'Penyusup'])->assertSessionHasErrors('Umum');
        expect(PenggunaPengelola::query()->where('Email', 'budi@contoh.id')->count())->toBe(1);
    });

    it('undangan kedaluwarsa setelah 48 jam', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $path = UndangLewatHttp($this, $superAdmin, 'budi@contoh.id', ['Analis']);

        $this->travel(48)->hours();
        $this->travel(1)->minutes();

        $this->get(BantuanPengelola::Url($path))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Berlaku', false));
        $this->post(BantuanPengelola::Url($path), [
            'Nama' => 'Budi',
            'KataSandi' => 'rahasia-kuat-123',
            'KonfirmasiKataSandi' => 'rahasia-kuat-123',
        ])->assertSessionHasErrors('Umum');
        $this->assertDatabaseMissing('PenggunaPengelola', ['Email' => 'budi@contoh.id']);
    });

    it('undangan baru membatalkan undangan lama untuk email yang sama', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pathLama = UndangLewatHttp($this, $superAdmin, 'budi@contoh.id', ['Analis']);
        UndangLewatHttp($this, $superAdmin, 'budi@contoh.id', ['Teknis']);

        $this->get(BantuanPengelola::Url($pathLama))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Berlaku', false));
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'tim.anggota.undangan-batal']);
    });

    it('menolak email yang sudah menjadi anggota tim', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        Mail::fake();

        $this->actingAs($superAdmin, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => $superAdmin->Email, 'KodePeran' => ['Analis']])
            ->assertSessionHasErrors('Email');

        Mail::assertNothingSent();
    });

    it('menolak peran yang tidak dikenal dan undangan tanpa peran', function (array $kodePeran): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        Mail::fake();

        $this->actingAs($superAdmin, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => 'baru@contoh.id', 'KodePeran' => $kodePeran])
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('UndanganPengelola', 0);
    })->with([
        'kosong' => [[]],
        'tidak dikenal' => [['Pemilik']],
    ]);

    it('peran selain Super Admin tidak bisa mengundang', function (PeranPengelolaBawaan $peran): void {
        $anggota = BantuanPengelola::BuatAnggota($peran);
        Mail::fake();

        $this->actingAs($anggota, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/tim-internal/undangan'), ['Email' => 'baru@contoh.id', 'KodePeran' => ['Analis']])
            ->assertForbidden();

        $this->get(BantuanPengelola::Url('/tim-internal'))->assertForbidden();
        Mail::assertNothingSent();
    })->with(array_values(array_filter(
        PeranPengelolaBawaan::cases(),
        fn (PeranPengelolaBawaan $peran) => $peran !== PeranPengelolaBawaan::SuperAdmin,
    )));
});
