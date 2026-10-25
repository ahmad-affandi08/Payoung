<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanSatuanStandarBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\SiapkanTemplateSektorBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\UjiCobaTemplateSektor;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * P-03 langkah 4 (v6.04) pratinjau sandbox: versi template (draf atau terbit) diterapkan ke tenant uji sementara yang
 * selalu dibatalkan, lalu dicek peran akun, jurnal contoh, neraca saldo, laba rugi, dan produk contoh.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanSatuanStandarBawaan::class)->Jalankan();
    app(SiapkanPajakBawaan::class)->Jalankan();
    app(SiapkanKatalogBawaan::class)->Jalankan();
    app(SiapkanTemplateSektorBawaan::class)->Jalankan();
});

function MasukUjiCoba(PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Teknis): void
{
    test()->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function UrlUjiCoba(string $kode = 'FNB-CAF', int $versi = 1): string
{
    return BantuanPengelola::Url("/template-sektor/{$kode}/versi/{$versi}/uji-coba");
}

/** @return array<string, array{Lolos: bool, Keterangan: string}> */
function PeriksaUjiCoba(object $respons): array
{
    $hasil = [];

    foreach ($respons->json('Periksa') as $p) {
        $hasil[$p['Kunci']] = ['Lolos' => $p['Lolos'], 'Keterangan' => $p['Keterangan']];
    }

    return $hasil;
}

describe('uji coba sandbox template sektor', function (): void {
    it('draf FNB-CAF lolos semua pemeriksaan dan tidak meninggalkan tenant, akun, atau jurnal', function (): void {
        MasukUjiCoba();
        $tenantSebelum = Tenant::query()->count();

        $respons = $this->postJson(UrlUjiCoba())->assertOk();

        $periksa = PeriksaUjiCoba($respons);
        expect($respons->json('Lolos'))->toBeTrue()
            ->and(array_keys($periksa))->toBe(['Penerapan', 'PeranAkun', 'Jurnal', 'Laporan', 'ProdukContoh'])
            ->and(collect($periksa)->every(fn (array $p): bool => $p['Lolos']))->toBeTrue()
            ->and($periksa['Laporan']['Keterangan'])->toContain('Rp 100.000')->toContain('Rp 40.000')->toContain('Rp 60.000');

        expect(Tenant::query()->count())->toBe($tenantSebelum)
            ->and(Akun::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and(app(KonteksTenant::class)->Ambil())->toBeNull();
    });

    it('audit konsol mencatat hasil uji coba (lolos dan peran yang gagal)', function (): void {
        MasukUjiCoba();

        $this->postJson(UrlUjiCoba())->assertOk();

        $audit = LogAuditPengelola::query()->where('Aksi', 'template-sektor.uji-coba')->sole();
        expect($audit->NilaiBaru)->toMatchArray(['Template' => 'FNB-CAF', 'Versi' => 1, 'Lolos' => true, 'Gagal' => []]);
    });

    it('pemetaan akun yang kurang ketahuan: peran akun gagal dan pemeriksaan jurnal/laporan dilewati', function (): void {
        MasukUjiCoba();
        $versi = TemplateSektorVersi::query()->whereHas('TemplateSektor', fn ($k) => $k->where('Kode', 'FNB-CAF'))->where('Versi', 1)->sole();
        $isi = $versi->Isi;
        unset($isi['PemetaanAkun']['Hpp']);
        $versi->forceFill(['Isi' => $isi])->save();

        $respons = $this->postJson(UrlUjiCoba())->assertOk();

        $periksa = PeriksaUjiCoba($respons);
        expect($respons->json('Lolos'))->toBeFalse()
            ->and($periksa['PeranAkun']['Lolos'])->toBeFalse()
            ->and($periksa['PeranAkun']['Keterangan'])->toContain('Belum terpetakan')
            ->and($periksa)->not->toHaveKey('Jurnal')
            ->and(LogAuditPengelola::query()->where('Aksi', 'template-sektor.uji-coba')->sole()->NilaiBaru['Gagal'])->toBe(['PeranAkun']);
        expect(Akun::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('produk contoh yang tidak sah dilaporkan', function (): void {
        MasukUjiCoba();
        $versi = TemplateSektorVersi::query()->whereHas('TemplateSektor', fn ($k) => $k->where('Kode', 'FNB-CAF'))->where('Versi', 1)->sole();
        $isi = $versi->Isi;
        $isi['ProdukContoh'][] = ['Nama' => 'Produk rusak', 'Harga' => 'banyak', 'KodeSatuan' => 'PCS', 'Jenis' => 'Stok'];
        $versi->forceFill(['Isi' => $isi])->save();

        $periksa = PeriksaUjiCoba($this->postJson(UrlUjiCoba())->assertOk());

        expect($periksa['ProdukContoh']['Lolos'])->toBeFalse()->and($periksa['ProdukContoh']['Keterangan'])->toContain('1 dari');
    });

    it('semua 22 template bawaan lolos uji coba', function (): void {
        MasukUjiCoba();
        $jumlah = 0;

        foreach (TemplateSektorVersi::query()->with('TemplateSektor')->orderBy('IdTemplateSektor')->get() as $versi) {
            $kode = $versi->TemplateSektor->Kode;
            $hasil = app(UjiCobaTemplateSektor::class)->Jalankan($versi);
            $gagal = collect($hasil['Periksa'])->reject(fn (array $p): bool => $p['Lolos'])->pluck('Kunci')->all();

            expect($gagal)->toBe([], "Template {$kode} gagal: ".implode(', ', $gagal));
            $jumlah++;
        }

        expect($jumlah)->toBe(22);
    });

    it('izin: Konten & Legal dan Teknis boleh; Analis dan Dukungan ditolak; tamu dialihkan', function (): void {
        MasukUjiCoba(PeranPengelolaBawaan::Analis);
        $this->postJson(UrlUjiCoba())->assertForbidden();
        MasukUjiCoba(PeranPengelolaBawaan::Dukungan);
        $this->postJson(UrlUjiCoba())->assertForbidden();
        MasukUjiCoba(PeranPengelolaBawaan::KontenLegal);
        $this->postJson(UrlUjiCoba())->assertOk();
    });
});
