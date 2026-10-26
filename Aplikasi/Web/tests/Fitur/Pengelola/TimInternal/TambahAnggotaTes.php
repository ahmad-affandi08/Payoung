<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Pengelola\TimInternal\Model\UndanganPengelola;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * D-22: Super Admin menambah anggota tim langsung dengan kata sandi awal (tanpa email aktif). Anggota wajib mengganti
 * kata sandi saat pertama masuk, sebelum aktivasi 2FA dan menu lain.
 */

describe('D-22 tambah anggota tim langsung', function (): void {
    it('Super Admin menambah anggota: tanpa email terkirim, peran terpasang, wajib ganti kata sandi, tercatat di audit', function (): void {
        Mail::fake();
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        UndanganPengelola::query()->create([
            'Email' => 'budi@contoh.id',
            'HashToken' => hash('sha256', 'lama'),
            'KodePeran' => ['Dukungan'],
            'IdPenggunaPengelolaPengundang' => $superAdmin->Id,
            'BerlakuSampai' => now()->addDay(),
        ]);

        $this->actingAs($superAdmin, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/tim-internal'), [
                'Nama' => 'Budi Santoso',
                'Email' => 'Budi@Contoh.id',
                'KataSandi' => 'SandiAwal12345',
                'KodePeran' => ['Dukungan', 'Keuangan'],
            ])
            ->assertSessionHasNoErrors();

        $anggota = PenggunaPengelola::query()->where('Email', 'budi@contoh.id')->sole();
        expect($anggota->WajibGantiKataSandi)->toBeTrue()
            ->and(Hash::check('SandiAwal12345', $anggota->KataSandi))->toBeTrue()
            ->and($anggota->AmbilKodePeran())->toEqualCanonicalizing(['Dukungan', 'Keuangan'])
            ->and(UndanganPengelola::query()->sole()->DibatalkanPada)->not->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tim.anggota.tambah')->exists())->toBeTrue();
        Mail::assertNothingSent();
    });

    it('menolak email terdaftar, kata sandi lemah, dan peran kosong. Pengguna tanpa izin ditolak', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $url = BantuanPengelola::Url('/tim-internal');
        $this->actingAs($superAdmin, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->post($url, ['Nama' => 'X', 'Email' => $superAdmin->Email, 'KataSandi' => 'SandiAwal12345', 'KodePeran' => ['Dukungan']])
            ->assertSessionHasErrors('Email');
        $this->post($url, ['Nama' => 'X', 'Email' => 'x@contoh.id', 'KataSandi' => 'pendek1', 'KodePeran' => ['Dukungan']])
            ->assertSessionHasErrors('KataSandi');
        $this->post($url, ['Nama' => 'X', 'Email' => 'x@contoh.id', 'KataSandi' => 'SandiAwal12345', 'KodePeran' => []])
            ->assertSessionHasErrors('KodePeran');

        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $this->actingAs($dukungan, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post($url, ['Nama' => 'X', 'Email' => 'y@contoh.id', 'KataSandi' => 'SandiAwal12345', 'KodePeran' => ['Dukungan']])
            ->assertForbidden();
    });

    it('anggota dengan kata sandi awal diarahkan ke ganti kata sandi. Setelah diganti lanjut aktivasi 2FA', function (): void {
        $anggota = PenggunaPengelola::factory()->DenganPeran(PeranPengelolaBawaan::Dukungan)->createOne([
            'KataSandi' => 'SandiAwal12345',
            'WajibGantiKataSandi' => true,
        ]);
        $this->actingAs($anggota, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->get(BantuanPengelola::Url('/'))->assertRedirect(route('pengelola.kata-sandi.ganti'));
        $this->get(BantuanPengelola::Url('/dua-faktor/aktifkan'))->assertRedirect(route('pengelola.kata-sandi.ganti'));
        $this->get(BantuanPengelola::Url('/ganti-kata-sandi'))->assertOk();

        $this->post(BantuanPengelola::Url('/ganti-kata-sandi'), ['KataSandiLama' => 'salah', 'KataSandi' => 'SandiBaru67890', 'KonfirmasiKataSandi' => 'SandiBaru67890'])
            ->assertSessionHasErrors('KataSandiLama');
        $this->post(BantuanPengelola::Url('/ganti-kata-sandi'), ['KataSandiLama' => 'SandiAwal12345', 'KataSandi' => 'SandiAwal12345', 'KonfirmasiKataSandi' => 'SandiAwal12345'])
            ->assertSessionHasErrors('KataSandi');

        $this->post(BantuanPengelola::Url('/ganti-kata-sandi'), ['KataSandiLama' => 'SandiAwal12345', 'KataSandi' => 'SandiBaru67890', 'KonfirmasiKataSandi' => 'SandiBaru67890'])
            ->assertRedirect(route('pengelola.dua-faktor.aktifkan'));

        $anggota->refresh();
        expect($anggota->WajibGantiKataSandi)->toBeFalse()
            ->and(Hash::check('SandiBaru67890', $anggota->KataSandi))->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tim.anggota.ganti-kata-sandi')->exists())->toBeTrue();
    });
});
