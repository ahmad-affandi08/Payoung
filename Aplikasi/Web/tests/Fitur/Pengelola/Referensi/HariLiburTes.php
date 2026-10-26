<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pengelola\Referensi\Aksi\SimpanDrafHariLibur;
use App\Domain\Pengelola\Referensi\Data\DataHariLibur;
use App\Domain\Pengelola\Referensi\Model\PersetujuanDataMaster;
use App\Domain\Pengelola\Referensi\Surel\PengingatHariLibur;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Referensi\Enum\JenisHariLibur;
use App\Domain\Referensi\Kueri\HariLiburTerbit;
use App\Domain\Referensi\Model\HariLibur;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

function MasukSebagaiPengelola(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function BuatDrafLibur(TestCase $tes, PenggunaPengelola $pengaju, string $tanggal, string $nama, ?string $dasar = 'SKB 3 Menteri 2026'): void
{
    MasukSebagaiPengelola($tes, $pengaju)
        ->post(BantuanPengelola::Url('/referensi/hari-libur'), ['Tanggal' => $tanggal, 'Nama' => $nama, 'Jenis' => 'Nasional', 'NomorDasarHukum' => $dasar])
        ->assertSessionHasNoErrors();
}

beforeEach(function (): void {
    // Waktu dibekukan agar tanggal di test tidak kedaluwarsa seiring waktu (BR tanggal berlaku tidak boleh lewat).
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
});

describe('Hari libur (P-02, BR-P02.2)', function (): void {
    it('draf satu tahun diajukan lalu terbit dengan 1 penyetuju selain pengaju', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru 2027 Masehi');
        BuatDrafLibur($this, $konten, '2027-08-17', 'Hari Kemerdekaan RI');

        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasNoErrors();
        MasukSebagaiPengelola($this, $keuangan)
            ->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])
            ->assertSessionHasNoErrors();

        expect(HariLibur::query()->where('Status', StatusDataMaster::Terbit->value)->count())->toBe(2)
            ->and(array_map(fn (HariLibur $hari) => $hari->Nama, app(HariLiburTerbit::class)->AmbilTahun(2027)))
            ->toBe(['Tahun Baru 2027 Masehi', 'Hari Kemerdekaan RI']);
    });

    it('D-34: Super Admin tidak terikat tinjau-meninjau: ajuan Super Admin langsung terbit', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        BuatDrafLibur($this, $superAdmin, '2027-01-01', 'Tahun Baru');
        MasukSebagaiPengelola($this, $superAdmin)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', '1 hari libur tahun 2027 terbit.');

        expect(HariLibur::query()->sole()->Status)->toBe(StatusDataMaster::Terbit)
            ->and(PersetujuanDataMaster::query()->sole()->IdPenggunaPengelola)->toBe($superAdmin->Id);
    });

    it('penolakan mengembalikan semua ke draf. Hari libur terbit tidak bisa diubah atau dihapus', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru');
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));

        MasukSebagaiPengelola($this, $keuangan)
            ->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Tolak', 'Catatan' => 'Tanggal cuti bersama belum ada'])
            ->assertSessionHasNoErrors();
        $hari = HariLibur::query()->sole();
        expect($hari->Status)->toBe(StatusDataMaster::Draf);

        $this->travel(1)->minutes();
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));
        MasukSebagaiPengelola($this, $keuangan)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])
            ->assertSessionHasNoErrors();

        MasukSebagaiPengelola($this, $konten)
            ->put(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}"), ['Tanggal' => '2027-01-02', 'Nama' => 'Ubah', 'Jenis' => 'Nasional'])
            ->assertSessionHasErrors('Umum');
        $this->delete(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}"))->assertSessionHasErrors('Umum');

        expect(fn () => $hari->refresh()->update(['Nama' => 'X']))->toThrow(LogicException::class)
            ->and($hari->refresh()->Tanggal->toDateString())->toBe('2027-01-01');
    });

    it('pengajuan wajib dasar hukum dan menolak tanggal ganda', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru', null);

        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url('/referensi/hari-libur'), ['Tanggal' => '2027-01-01', 'Nama' => 'Ganda', 'Jenis' => 'Nasional'])
            ->assertSessionHasErrors('Tanggal');
    });

    it('izin: Keuangan tidak bisa membuat draf, Konten & Legal tidak bisa meninjau', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $kontenLain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);

        MasukSebagaiPengelola($this, $keuangan)
            ->post(BantuanPengelola::Url('/referensi/hari-libur'), ['Tanggal' => '2027-01-01', 'Nama' => 'X', 'Jenis' => 'Nasional'])
            ->assertForbidden();
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru');
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));
        MasukSebagaiPengelola($this, $kontenLain)
            ->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])
            ->assertForbidden();
    });
    it('BR-P02.2: tidak bisa mengajukan lagi selama pengajuan tahun yang sama belum ditinjau (pengaju kedua tidak bisa menyetujui dirinya)', function (): void {
        $pengajuA = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $pengajuB = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        BuatDrafLibur($this, $pengajuA, '2027-01-01', 'Tahun Baru');
        MasukSebagaiPengelola($this, $pengajuA)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasNoErrors();

        BuatDrafLibur($this, $pengajuB, '2027-08-17', 'Hari Kemerdekaan');
        MasukSebagaiPengelola($this, $pengajuB)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasErrors('Umum');
        expect(HariLibur::query()->where('Nama', 'Hari Kemerdekaan')->sole()->Status)->toBe(StatusDataMaster::Draf);

        // B boleh meninjau ajuan A, tetapi draf B tidak ikut terbit.
        $this->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])->assertSessionHasNoErrors();
        expect(HariLibur::query()->where('Nama', 'Hari Kemerdekaan')->sole()->Status)->toBe(StatusDataMaster::Draf);
    });

    it('ubah/hapus memakai data terbaru: model basi tidak bisa mengubah hari libur yang sudah terbit', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru');
        $basi = HariLibur::query()->sole();
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));
        MasukSebagaiPengelola($this, $keuangan)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju']);

        $aksi = app(SimpanDrafHariLibur::class);
        $data = new DataHariLibur(CarbonImmutable::parse('2027-01-02'), 'Ubah', JenisHariLibur::Nasional, 'SKB');

        expect(fn () => $aksi->Jalankan($konten, $data, $basi))->toThrow(PelanggaranAturanBisnis::class)
            ->and(fn () => $aksi->Hapus($konten, $basi))->toThrow(PelanggaranAturanBisnis::class)
            ->and(HariLibur::query()->sole()->Status)->toBe(StatusDataMaster::Terbit);
    });

    it('audit ubah draf menyimpan nilai lama dan baru', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru');
        $hari = HariLibur::query()->sole();

        MasukSebagaiPengelola($this, $konten)
            ->put(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}"), ['Tanggal' => '2027-01-01', 'Nama' => 'Tahun Baru Masehi', 'Jenis' => 'Nasional'])
            ->assertSessionHasNoErrors();

        $log = LogAuditPengelola::query()->where('Aksi', 'referensi.hari-libur.ubah-draf')->sole();
        expect($log->NilaiLama['Nama'] ?? null)->toBe('Tahun Baru')->and($log->NilaiBaru['Nama'] ?? null)->toBe('Tahun Baru Masehi');
    });

    it('memilih tahun lewat saring[Tahun]', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        BuatDrafLibur($this, $konten, '2028-01-01', 'Tahun Baru 2028');

        $this->get(BantuanPengelola::Url('/referensi/hari-libur?saring[Tahun]=2028'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Tahun', 2028)->has('HariLibur', 1));
    });
    it('BR-P02.6: pembatalan hari libur terbit lewat pengajuan dan tinjauan anggota lain', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $konten, '2027-12-26', 'Cuti bersama Natal');
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));
        MasukSebagaiPengelola($this, $keuangan)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju']);
        $hari = HariLibur::query()->sole();

        MasukSebagaiPengelola($this, $konten)
            ->post(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}/pembatalan"), ['Alasan' => 'SKB perubahan 2027'])
            ->assertSessionHasNoErrors();
        expect($hari->refresh()->Status)->toBe(StatusDataMaster::Terbit)
            ->and(app(HariLiburTerbit::class)->AmbilTahun(2027))->toHaveCount(1);

        // Pengaju non-Super Admin tidak meninjau pembatalannya sendiri; Konten & Legal memang tidak ber-izin setujui.
        $this->post(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}/pembatalan/tinjau"), ['Keputusan' => 'Setuju'])
            ->assertForbidden();
        MasukSebagaiPengelola($this, $keuangan)
            ->post(BantuanPengelola::Url("/referensi/hari-libur/{$hari->Uuid}/pembatalan/tinjau"), ['Keputusan' => 'Setuju'])
            ->assertSessionHasNoErrors();

        expect($hari->refresh()->Status)->toBe(StatusDataMaster::Dibatalkan)
            ->and($hari->DibatalkanPada)->not->toBeNull()
            ->and(app(HariLiburTerbit::class)->AmbilTahun(2027))->toBe([]);
        expect(fn () => $hari->refresh()->update(['Nama' => 'X']))->toThrow(LogicException::class);

        // Penggeseran: tanggal yang dibatalkan boleh diisi hari libur baru.
        BuatDrafLibur($this, $konten, '2027-12-26', 'Cuti bersama Natal (pengganti)');
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'referensi.hari-libur.batal', 'IdObjek' => $hari->Id]);
    });

    it('BR-P02.6: pembatalan yang ditolak membuat hari libur tetap berlaku. Draf tidak bisa diajukan pembatalan', function (): void {
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $konten, '2027-01-01', 'Tahun Baru');
        $draf = HariLibur::query()->sole();
        MasukSebagaiPengelola($this, $konten)
            ->post(BantuanPengelola::Url("/referensi/hari-libur/{$draf->Uuid}/pembatalan"), ['Alasan' => 'Coba'])
            ->assertSessionHasErrors('Umum');

        $this->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'));
        MasukSebagaiPengelola($this, $keuangan)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju']);
        MasukSebagaiPengelola($this, $konten)->post(BantuanPengelola::Url("/referensi/hari-libur/{$draf->Uuid}/pembatalan"), ['Alasan' => 'Salah input']);
        MasukSebagaiPengelola($this, $keuangan)
            ->post(BantuanPengelola::Url("/referensi/hari-libur/{$draf->Uuid}/pembatalan/tinjau"), ['Keputusan' => 'Tolak', 'Catatan' => 'Tanggal sudah benar'])
            ->assertSessionHasNoErrors();

        $hari = $draf->refresh();
        expect($hari->Status)->toBe(StatusDataMaster::Terbit)
            ->and($hari->CekPembatalanMenunggu())->toBeFalse();
    });

    it('BR-P02.2: penyusun draf hari libur tidak boleh meninjau walau yang mengajukan orang lain', function (): void {
        $pengaju = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $penyusun = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        BuatDrafLibur($this, $pengaju, '2027-01-01', 'Tahun Baru');
        // Peninjau non-Super Admin yang ikut menyusun draf (misal lewat peran khusus) tetap tidak boleh meninjau.
        HariLibur::query()->sole()->forceFill(['DaftarIdPenyusun' => [$pengaju->Id, $penyusun->Id]])->save();
        MasukSebagaiPengelola($this, $pengaju)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasNoErrors();

        MasukSebagaiPengelola($this, $penyusun)
            ->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])
            ->assertSessionHasErrors('Umum');
        expect(HariLibur::query()->sole()->Status)->toBe(StatusDataMaster::MenungguTinjauan);
    });

    it('D-34: Super Admin boleh meninjau ajuan yang ia ikut susun, dan satu persetujuannya cukup', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pengaju = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        BuatDrafLibur($this, $superAdmin, '2027-01-01', 'Tahun Baru');
        MasukSebagaiPengelola($this, $pengaju)->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/ajukan'))->assertSessionHasNoErrors();

        MasukSebagaiPengelola($this, $superAdmin)
            ->post(BantuanPengelola::Url('/referensi/hari-libur/tahun/2027/tinjau'), ['Keputusan' => 'Setuju'])
            ->assertSessionHasNoErrors();
        expect(HariLibur::query()->sole()->Status)->toBe(StatusDataMaster::Terbit);
    });
});

describe('Pengingat hari libur (BR-P02.4)', function (): void {
    it('tidak mengirim pengingat sebelum 1 November', function (): void {
        Mail::fake();
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $this->travelTo(Carbon::parse('2026-10-31 09:00', 'Asia/Jakarta'));

        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('mengingatkan Konten & Legal mulai 1 November dan menandai terlambat setelah 1 Desember', function (): void {
        Mail::fake();
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);

        $this->travelTo(Carbon::parse('2026-11-01 08:00', 'Asia/Jakarta'));
        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();
        Mail::assertSent(PengingatHariLibur::class, fn (PengingatHariLibur $surel) => $surel->hasTo($konten->Email) && $surel->tahun === 2027 && ! $surel->terlambat);
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-12-02 08:00', 'Asia/Jakarta'));
        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();
        Mail::assertSent(PengingatHariLibur::class, fn (PengingatHariLibur $surel) => $surel->terlambat);
    });

    it('berhenti mengingatkan setelah hari libur tahun berikutnya terbit', function (): void {
        Mail::fake();
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        HariLibur::query()->create(['Tanggal' => '2027-01-01', 'Nama' => 'Tahun Baru', 'Jenis' => JenisHariLibur::Nasional, 'Status' => StatusDataMaster::Terbit]);
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'Asia/Jakarta'));

        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('mengirim ke Super Admin bila belum ada anggota Konten & Legal, dan terjadwal setiap hari', function (): void {
        Mail::fake();
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $this->travelTo(Carbon::parse('2026-11-03 08:00', 'Asia/Jakarta'));

        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();

        Mail::assertSent(PengingatHariLibur::class, fn (PengingatHariLibur $surel) => $surel->hasTo($superAdmin->Email));
        $this->artisan('schedule:list')->expectsOutputToContain('pengelola:ingatkan-hari-libur')->assertSuccessful();
    });
    it('1 Desember masih tepat waktu, 2 Desember terlambat', function (): void {
        Mail::fake();
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);

        $this->travelTo(Carbon::parse('2026-12-01 08:00', 'Asia/Jakarta'));
        $this->artisan('pengelola:ingatkan-hari-libur')->assertSuccessful();

        Mail::assertSent(PengingatHariLibur::class, fn (PengingatHariLibur $surel) => ! $surel->terlambat);
        Mail::assertNotSent(PengingatHariLibur::class, fn (PengingatHariLibur $surel) => $surel->terlambat);
    });
});
