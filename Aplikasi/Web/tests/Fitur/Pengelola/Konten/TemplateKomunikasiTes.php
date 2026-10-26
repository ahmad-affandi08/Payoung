<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusBspTemplat;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Enum\TahapPengingatTagihan;
use App\Domain\Tenant\Layanan\PenyusunPesanKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;
use App\Domain\Tenant\Surel\PengingatTagihanLangganan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * PGL-07 (v6.03) Templat komunikasi platform ke Owner (P-06): draf → terbit berversi (BR-P06.1, BR-P06.4), variabel dikenal
 * & wajib, subjek satu baris, status BSP WhatsApp, izin, audit, penyusun pesan (terbit → Indonesia → bawaan kode) dan
 * sambungannya ke email pengingat tagihan.
 */

function MasukTemplatKomunikasi(PeranPengelolaBawaan $peran = PeranPengelolaBawaan::KontenLegal): void
{
    test()->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function AlamatTemplatUji(string $kanal = 'email', string $bahasa = 'id', string $kode = 'tagihan.pengingat.h-7'): string
{
    return BantuanPengelola::Url("/template-komunikasi/{$kode}/{$kanal}/{$bahasa}");
}

/** @return array<string, string> */
function IsiEmailUji(array $timpa = []): array
{
    return [
        'Subjek' => 'Tagihan {NomorTagihan} menunggu',
        'Judul' => 'Halo {NamaPemilik}',
        'Isi' => 'Tagihan paket {NamaPaket} sebesar {Total} jatuh tempo {JatuhTempo}.',
        ...$timpa,
    ];
}

function TerbitkanTemplatUji(string $kanal = 'email', string $bahasa = 'id', ?array $isi = null, string $kode = 'tagihan.pengingat.h-7'): TemplateKomunikasi
{
    $isi ??= $kanal === 'email' ? IsiEmailUji() : ['Isi' => 'Halo {NamaPemilik}, tagihan {NomorTagihan} {Total}. Bayar: {Tautan}'];
    test()->put(AlamatTemplatUji($kanal, $bahasa, $kode), $isi)->assertSessionHasNoErrors();
    $draf = TemplateKomunikasi::query()->where('KodePeristiwa', $kode)->where('Status', StatusTemplateKomunikasi::Draf->value)->orderByDesc('Id')->firstOrFail();
    test()->post(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();

    return $draf->refresh();
}

describe('konsol templat komunikasi', function (): void {
    it('daftar menampilkan semua peristiwa × kanal × bahasa dengan sumber teks bawaan sebelum ada versi terbit', function (): void {
        MasukTemplatKomunikasi();

        $this->get(BantuanPengelola::Url('/template-komunikasi'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Legal/TemplatKomunikasi/Daftar')
            ->has('Peristiwa', 5)
            ->where('Peristiwa.0.Kode', 'tagihan.pengingat.h-7')
            // Email, WhatsApp, dan Notifikasi aplikasi × Indonesia/Inggris.
            ->has('Peristiwa.0.Sel', 6)
            ->where('Peristiwa.4.Kode', 'tiket.balasan-tim')
            ->has('Peristiwa.4.Sel', 2)
            ->where('Peristiwa.0.Sel.0.Sumber', 'Bawaan')
            ->where('Izin.Kelola', true));
    });

    it('BR-P06.4: menyimpan draf email lalu menerbitkannya menjadi versi 1. Audit tercatat', function (): void {
        MasukTemplatKomunikasi();

        $terbit = TerbitkanTemplatUji();

        expect($terbit->Versi)->toBe(1)->and($terbit->Status)->toBe(StatusTemplateKomunikasi::Terbit)->and($terbit->DiterbitkanPada)->not->toBeNull()
            ->and($terbit->IdPenggunaPengelolaPenerbit)->not->toBeNull();
        expect(LogAuditPengelola::query()->whereIn('Aksi', ['komunikasi.templat.buat', 'komunikasi.templat.terbitkan'])->count())->toBe(2);

        $this->get(AlamatTemplatUji())->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Legal/TemplatKomunikasi/Ubah')
            ->where('Editor.Terbit.Versi', 1)
            ->where('Editor.Draf', null)
            ->has('Editor.Riwayat', 1)
            ->where('Editor.Variabel.0.Nama', 'NamaPemilik'));

        $this->get(BantuanPengelola::Url('/template-komunikasi'))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Peristiwa.0.Sel.0.Sumber', 'Templat')->where('Peristiwa.0.Sel.0.VersiTerbit', 1));
    });

    it('BR-P06.1: perubahan sesudah terbit menjadi versi baru. Versi terbit tidak bisa diubah atau dihapus', function (): void {
        MasukTemplatKomunikasi();
        $v1 = TerbitkanTemplatUji();

        $v2 = TerbitkanTemplatUji('email', 'id', IsiEmailUji(['Isi' => 'Versi dua untuk {NamaPemilik}.']));

        expect($v2->Versi)->toBe(2)->and(TemplateKomunikasi::query()->where('Status', StatusTemplateKomunikasi::Terbit->value)->count())->toBe(2);
        expect(fn () => $v1->forceFill(['Isi' => 'diubah diam-diam'])->save())->toThrow(LogicException::class);
        expect(fn () => $v1->delete())->toThrow(LogicException::class);
        $this->delete(BantuanPengelola::Url("/template-komunikasi/{$v1->Uuid}"))->assertSessionHasErrors();
        expect(TemplateKomunikasi::query()->whereKey($v1->Id)->exists())->toBeTrue();
    });

    it('BR-P06.4: satu draf per kombinasi. Menyimpan lagi memperbarui draf yang sama, bisa dihapus', function (): void {
        MasukTemplatKomunikasi();
        $this->put(AlamatTemplatUji(), IsiEmailUji())->assertSessionHasNoErrors();
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Isi' => 'Isi kedua {NamaPemilik}.']))->assertSessionHasNoErrors();

        $draf = TemplateKomunikasi::query()->sole();
        expect($draf->Isi)->toBe('Isi kedua {NamaPemilik}.')->and($draf->Versi)->toBe(1);

        $this->delete(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}"))->assertSessionHasNoErrors();
        expect(TemplateKomunikasi::query()->count())->toBe(0);
    });

    it('menolak variabel tak dikenal, subjek bercabang baris, isi kosong, dan variabel wajib WhatsApp {Tautan}', function (): void {
        MasukTemplatKomunikasi();

        $this->put(AlamatTemplatUji(), IsiEmailUji(['Isi' => 'Halo {NamaToko}']))->assertSessionHasErrors('Isi');
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Subjek' => "Tagihan\nBcc: x@example.com"]))->assertSessionHasErrors('Subjek');
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Judul' => '']))->assertSessionHasErrors('Judul');
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Isi' => '']))->assertSessionHasErrors('Isi');
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Subjek' => str_repeat('a', 151)]))->assertSessionHasErrors('Subjek');
        $this->put(AlamatTemplatUji('whatsapp'), ['Isi' => 'Halo {NamaPemilik}, bayar segera.'])->assertSessionHasErrors('Isi');
        $this->put(AlamatTemplatUji('whatsapp'), ['Isi' => str_repeat('x', 1501).' {Tautan}'])->assertSessionHasErrors('Isi');
        expect(TemplateKomunikasi::query()->count())->toBe(0);

        $this->put(AlamatTemplatUji('whatsapp'), ['Isi' => 'Halo {NamaPemilik}, bayar di {Tautan}'])->assertSessionHasNoErrors();
        expect(TemplateKomunikasi::query()->sole()->Subjek)->toBeNull();
    });

    it('kombinasi tak dikenal 404', function (): void {
        MasukTemplatKomunikasi();

        $this->get(AlamatTemplatUji('sms'))->assertNotFound();
        $this->get(AlamatTemplatUji('email', 'fr'))->assertNotFound();
        $this->get(AlamatTemplatUji('email', 'id', 'tagihan.tidak-ada'))->assertNotFound();
    });

    it('status BSP hanya untuk WhatsApp. Templat WhatsApp yang ditolak BSP tidak bisa diterbitkan', function (): void {
        MasukTemplatKomunikasi();
        $this->put(AlamatTemplatUji('whatsapp'), ['Isi' => 'Halo {NamaPemilik}, bayar di {Tautan}'])->assertSessionHasNoErrors();
        $draf = TemplateKomunikasi::query()->sole();

        $this->put(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/status-bsp"), ['StatusBsp' => 'Ditolak', 'CatatanBsp' => 'Kategori salah'])->assertSessionHasNoErrors();
        expect($draf->refresh()->StatusBsp)->toBe(StatusBspTemplat::Ditolak)->and($draf->CatatanBsp)->toBe('Kategori salah');
        $this->post(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/terbitkan"))->assertSessionHasErrors();
        expect($draf->refresh()->Status)->toBe(StatusTemplateKomunikasi::Draf);

        $this->put(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/status-bsp"), ['StatusBsp' => 'Disetujui'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/terbitkan"))->assertSessionHasNoErrors();
        $this->put(BantuanPengelola::Url("/template-komunikasi/{$draf->Uuid}/status-bsp"), ['StatusBsp' => 'MenungguPersetujuan'])->assertSessionHasNoErrors();
        expect($draf->refresh()->Status)->toBe(StatusTemplateKomunikasi::Terbit)->and($draf->StatusBsp)->toBe(StatusBspTemplat::MenungguPersetujuan);

        $email = TerbitkanTemplatUji();
        $this->put(BantuanPengelola::Url("/template-komunikasi/{$email->Uuid}/status-bsp"), ['StatusBsp' => 'Disetujui'])->assertSessionHasErrors();
    });

    it('izin: hanya peran dengan legal.kelola yang menulis; Dukungan hanya membaca', function (): void {
        MasukTemplatKomunikasi(PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url('/template-komunikasi'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        $this->put(AlamatTemplatUji(), IsiEmailUji())->assertForbidden();
        expect(TemplateKomunikasi::query()->count())->toBe(0);
    });

    it('perlu masuk konsol: tamu dialihkan', function (): void {
        $this->get(BantuanPengelola::Url('/template-komunikasi'))->assertRedirect();
    });
});

describe('penyusun pesan komunikasi', function (): void {
    /** @return array<string, string> */
    function VariabelPenyusunUji(): array
    {
        return PengingatTagihanLangganan::SusunVariabel(TahapPengingatTagihan::HMinus7, 'Rina', 'INV/2026/09/000001', 'Rp 249.000', 'Pro', '1 Oktober 2026', null, 'https://dashboard.payoung.id/kelola/langganan');
    }

    it('tanpa versi terbit: teks bawaan identik dengan teks sebelum fitur templat ada', function (): void {
        $penyusun = app(PenyusunPesanKomunikasi::class);
        $v = VariabelPenyusunUji();

        $email = $penyusun->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::Email, $v);
        $wa = $penyusun->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::WhatsApp, $v);
        [$subjek, $judul, $kalimat] = PengingatTagihanLangganan::AmbilTeks(TahapPengingatTagihan::HMinus7, 'INV/2026/09/000001', 'Pro', '1 Oktober 2026', null);

        expect($email->sumber)->toBe('Bawaan')->and($email->subjek)->toBe($subjek)->and($email->judul)->toBe($judul)->and($email->isi)->toBe($kalimat)
            ->and($wa->isi)->toBe("Halo Rina, {$kalimat}\nTagihan INV/2026/09/000001: Rp 249.000.\nBayar di https://dashboard.payoung.id/kelola/langganan");
    });

    it('semua tahap: bawaan email dan WhatsApp sama dengan teks lama', function (): void {
        $penyusun = app(PenyusunPesanKomunikasi::class);

        foreach (TahapPengingatTagihan::cases() as $tahap) {
            $v = PengingatTagihanLangganan::SusunVariabel($tahap, 'Rina', 'INV/1', 'Rp 1', 'Pro', '1 Oktober 2026', $tahap === TahapPengingatTagihan::HPlus3 ? '8 Oktober 2026' : null, 'https://x');
            [$subjek, $judul, $kalimat] = PengingatTagihanLangganan::AmbilTeks($tahap, 'INV/1', 'Pro', '1 Oktober 2026', $tahap === TahapPengingatTagihan::HPlus3 ? '8 Oktober 2026' : null);
            $email = $penyusun->Susun(PeristiwaKomunikasi::DariTahap($tahap), KanalKomunikasi::Email, $v);

            expect([$email->subjek, $email->judul, $email->isi])->toBe([$subjek, $judul, $kalimat]);
        }
    });

    it('memilih versi terbit tertinggi. Draf tidak dipakai', function (): void {
        MasukTemplatKomunikasi();
        TerbitkanTemplatUji();
        TerbitkanTemplatUji('email', 'id', IsiEmailUji(['Isi' => 'Versi dua {NamaPemilik}.']));
        $this->put(AlamatTemplatUji(), IsiEmailUji(['Isi' => 'Draf ketiga.']))->assertSessionHasNoErrors();

        $pesan = app(PenyusunPesanKomunikasi::class)->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::Email, VariabelPenyusunUji());

        expect($pesan->sumber)->toBe('Templat')->and($pesan->versi)->toBe(2)->and($pesan->isi)->toBe('Versi dua Rina.')->and($pesan->subjek)->toBe('Tagihan INV/2026/09/000001 menunggu');
    });

    it('bahasa Inggris tanpa versi terbit memakai bawaan Inggris, dengan versi terbit Indonesia saja tetap bawaan Inggris', function (): void {
        $penyusun = app(PenyusunPesanKomunikasi::class);
        $v = VariabelPenyusunUji();

        $en = $penyusun->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::WhatsApp, $v, BahasaKomunikasi::Inggris);

        expect($en->isi)->toContain('Hello Rina')->and($en->isi)->toContain('INV/2026/09/000001')->and($en->isi)->not->toContain('{');
    });

    it('bahasa Inggris tanpa terbit jatuh ke terbit Indonesia bila ada, lalu ke bawaan', function (): void {
        MasukTemplatKomunikasi();
        TerbitkanTemplatUji('whatsapp', 'id', ['Isi' => 'Halo {NamaPemilik} bayar {Tautan}']);

        $pesan = app(PenyusunPesanKomunikasi::class)->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::WhatsApp, VariabelPenyusunUji(), BahasaKomunikasi::Inggris);

        expect($pesan->sumber)->toBe('Templat')->and($pesan->isi)->toBe('Halo Rina bayar https://dashboard.payoung.id/kelola/langganan');
    });

    it('nilai variabel tidak dirender ulang dan variabel asing dibiarkan', function (): void {
        expect(PenyusunPesanKomunikasi::Render('A {X} {Y}', ['X' => '{Y}', 'Y' => 'z']))->toBe('A {Y} z')
            ->and(PenyusunPesanKomunikasi::Render('{Asing}', []))->toBe('{Asing}')
            ->and(PenyusunPesanKomunikasi::AmbilVariabelDipakai('{A} {B} {A}'))->toBe(['A', 'B']);
    });
});

describe('sambungan ke email pengingat tagihan', function (): void {
    it('email memakai versi terbit dari konsol, tanpa versi terbit tampilan tidak berubah', function (): void {
        $tanpa = new PengingatTagihanLangganan(TahapPengingatTagihan::HMinus7, 'Rina', 'INV/1', 'Rp 249.000', 'Pro', '1 Oktober 2026', null);
        expect($tanpa->subject)->toBe('Tagihan INV/1 sudah terbit');

        MasukTemplatKomunikasi();
        TerbitkanTemplatUji();
        $dengan = new PengingatTagihanLangganan(TahapPengingatTagihan::HMinus7, 'Rina', 'INV/1', 'Rp 249.000', 'Pro', '1 Oktober 2026', null);

        expect($dengan->subject)->toBe('Tagihan INV/1 menunggu');
        $html = $dengan->render();
        expect($html)->toContain('Halo Rina')->and($html)->toContain('Tagihan paket Pro sebesar Rp 249.000 jatuh tempo 1 Oktober 2026.');
    });
});

describe('PGL-07: kanal Notifikasi aplikasi dan peristiwa tiket', function (): void {
    it('draf notifikasi: judul satu baris maks. 65, isi maks. 200, variabel dikenal. Diterbitkan lalu dipakai penyusun', function (): void {
        MasukTemplatKomunikasi();
        $alamat = AlamatTemplatUji('notifikasi');

        $this->put($alamat, ['Isi' => 'Tagihan {NomorTagihan} jatuh tempo {JatuhTempo}.'])->assertSessionHasErrors('Judul');
        $this->put($alamat, ['Judul' => str_repeat('a', 66), 'Isi' => 'Halo'])->assertSessionHasErrors('Judul');
        $this->put($alamat, ['Judul' => "Dua\nbaris", 'Isi' => 'Halo'])->assertSessionHasErrors('Judul');
        $this->put($alamat, ['Judul' => 'Tagihan {NomorTagihan}', 'Isi' => str_repeat('a', 201)])->assertSessionHasErrors('Isi');
        $this->put($alamat, ['Judul' => 'Tagihan {Asing}', 'Isi' => 'Halo'])->assertSessionHasErrors('Judul');
        expect(TemplateKomunikasi::query()->where('Kanal', 'Notifikasi')->count())->toBe(0);

        TerbitkanTemplatUji('notifikasi', 'id', ['Judul' => 'Tagihan {NomorTagihan} segera jatuh tempo', 'Isi' => 'Bayar {Total} paling lambat {JatuhTempo} untuk paket {NamaPaket}.']);

        $pesan = app(PenyusunPesanKomunikasi::class)->Susun(PeristiwaKomunikasi::TagihanHMinus7, KanalKomunikasi::Notifikasi, [
            'NomorTagihan' => 'INV/1', 'Total' => 'Rp 149.000', 'JatuhTempo' => '1 Oktober 2026', 'NamaPaket' => 'Pro',
        ]);
        expect($pesan->sumber)->toBe('Templat')
            ->and($pesan->subjek)->toBeNull()
            ->and($pesan->judul)->toBe('Tagihan INV/1 segera jatuh tempo')
            ->and($pesan->isi)->toBe('Bayar Rp 149.000 paling lambat 1 Oktober 2026 untuk paket Pro.');
    });

    it('tanpa versi terbit memakai teks bawaan notifikasi (Indonesia dan Inggris) yang muat di batas push', function (): void {
        $penyusun = app(PenyusunPesanKomunikasi::class);

        foreach (PeristiwaKomunikasi::cases() as $peristiwa) {
            foreach (BahasaKomunikasi::cases() as $bahasa) {
                $bawaan = $peristiwa->AmbilBawaan(KanalKomunikasi::Notifikasi, $bahasa);

                expect($bawaan['Subjek'])->toBeNull()
                    ->and(mb_strlen((string) $bawaan['Judul']))->toBeLessThanOrEqual(65)
                    ->and(mb_strlen($bawaan['Isi']))->toBeLessThanOrEqual(200 + 40, 'Bawaan memuat variabel. Teks terisi harus tetap pendek.');
                $dikenal = array_column($peristiwa->AmbilVariabel(), 'Nama');
                expect(array_diff(PenyusunPesanKomunikasi::AmbilVariabelDipakai($bawaan['Judul'].' '.$bawaan['Isi']), $dikenal))->toBe([]);
            }
        }

        $pesan = $penyusun->Susun(PeristiwaKomunikasi::TiketBalasanTim, KanalKomunikasi::Notifikasi, ['NamaPetugas' => 'Dewi', 'NomorTiket' => 'TKT-2026-000123', 'JudulTiket' => 'Printer rusak']);
        expect($pesan->sumber)->toBe('Bawaan')
            ->and($pesan->judul)->toBe('Dukungan membalas tiket Anda')
            ->and($pesan->isi)->toBe('Dewi membalas tiket TKT-2026-000123: Printer rusak.');
    });

    it('peristiwa tiket hanya punya kanal Notifikasi: email dan WhatsApp 404 dan ditolak aksi', function (): void {
        MasukTemplatKomunikasi();

        $this->get(AlamatTemplatUji('notifikasi', 'id', 'tiket.balasan-tim'))->assertOk();
        $this->get(AlamatTemplatUji('email', 'id', 'tiket.balasan-tim'))->assertNotFound();
        $this->put(AlamatTemplatUji('whatsapp', 'id', 'tiket.balasan-tim'), ['Isi' => 'x {Tautan}'])->assertNotFound();
        $this->put(AlamatTemplatUji('notifikasi', 'id', 'tiket.balasan-tim'), ['Judul' => 'Balasan {NomorTiket}', 'Isi' => 'Dari {NamaPetugas}.'])->assertSessionHasNoErrors();
        $this->put(AlamatTemplatUji('notifikasi', 'id', 'tiket.balasan-tim'), ['Judul' => 'Total {Total}', 'Isi' => 'Dari {NamaPetugas}.'])->assertSessionHasErrors('Judul');
    });

    it('editor memuat batas notifikasi dan variabel peristiwa', function (): void {
        MasukTemplatKomunikasi();

        $this->get(AlamatTemplatUji('notifikasi', 'en'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Legal/TemplatKomunikasi/Ubah')
            ->where('Editor.Kanal.Nilai', 'Notifikasi')
            ->where('Editor.Batas.Judul', 65)
            ->where('Editor.Batas.Isi', 200)
            ->where('Editor.Bawaan.Subjek', null)
            ->where('Editor.Bawaan.Judul', 'Invoice {NomorTagihan} has been issued'));
    });
});
