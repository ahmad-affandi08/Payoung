<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Integrasi\Layanan\PenyediaTindakanWhatsapp;
use App\Domain\Integrasi\Model\PemakaianPesanWa;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\MeterKuotaWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PengirimWhatsappBerkuota;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Kueri\KesiapanNotifikasiPesananOnline;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as FabrikHttp;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-108: kuota pesan WhatsApp lewat nomor Payoung dihitung per tenant per bulan (WIB) dan ditegakkan. Kuota paket
 * dan add-on masuk lewat batas efektif yang sama dengan batas lain; override pengelola dipakai di test supaya tidak
 * bergantung pada isi paket bawaan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8001']])]);
});

/**
 * @return array{Tenant: object}
 */
function SiapkanTenantKuota(?int $kuota, bool $tanpaBatas = false): array
{
    $t = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    if (! $tanpaBatas) {
        OverrideTenant::query()->create([
            'IdTenant' => $t['Tenant']->Id,
            'Jenis' => JenisOverride::Batas,
            'Kunci' => 'KuotaPesanWaBulanan',
            'Nilai' => $kuota,
            'BerakhirPada' => now()->addDays(60),
            'Alasan' => 'Uji kuota WhatsApp',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
    }

    return $t;
}

function KirimSatu(string $nomor = '081234567890'): object
{
    $pengirim = app(PembuatPengirimWhatsapp::class)->AmbilAktif();

    return $pengirim->Kirim(new PesanWhatsapp(PesanWhatsapp::RapikanNomor($nomor), 'Halo dari toko'));
}

describe('D-108 kuota pesan WhatsApp nomor Payoung', function (): void {
    it('menghitung tiap pesan terkirim dan menolak yang melewati kuota tanpa menghubungi penyedia', function (): void {
        $t = SiapkanTenantKuota(2);

        expect(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->toBeInstanceOf(PengirimWhatsappBerkuota::class)
            ->and(KirimSatu()->berhasil)->toBeTrue()
            ->and(KirimSatu()->berhasil)->toBeTrue();

        $ketiga = KirimSatu();

        expect($ketiga->berhasil)->toBeFalse()
            ->and($ketiga->pesan)->toContain('Kuota pesan WhatsApp bulan ini habis')
            ->and(PemakaianPesanWa::query()->sole()->Jumlah)->toBe(2)
            ->and(app(MeterKuotaWhatsapp::class)->AmbilRingkasan($t['Tenant']->Id))->toBe(['Periode' => '2026-10', 'Terpakai' => 2, 'Batas' => 2, 'Sisa' => 0]);
        Http::assertSentCount(2);
    });

    it('pesan yang gagal di penyedia mengembalikan kuotanya', function (): void {
        SiapkanTenantKuota(1);
        Http::swap(new FabrikHttp);
        Http::fake(['api.fonnte.com/send' => Http::sequence()->push(['status' => false, 'reason' => 'device disconnected'])->push(['status' => true, 'id' => ['8002']])]);

        expect(KirimSatu()->berhasil)->toBeFalse()
            ->and(PemakaianPesanWa::query()->sole()->Jumlah)->toBe(0)
            ->and(KirimSatu()->berhasil)->toBeTrue()
            ->and(PemakaianPesanWa::query()->sole()->Jumlah)->toBe(1);
    });

    it('kuota 0 menutup nomor Payoung; tanpa batas (null) tetap menghitung tetapi tidak menolak', function (): void {
        SiapkanTenantKuota(0);
        expect(KirimSatu()->berhasil)->toBeFalse();
        Http::assertNothingSent();

        $t = SiapkanTenantKuota(null);
        expect(KirimSatu()->berhasil)->toBeTrue()
            ->and(KirimSatu()->berhasil)->toBeTrue()
            ->and(app(MeterKuotaWhatsapp::class)->AmbilRingkasan($t['Tenant']->Id)['Sisa'])->toBeNull();
    });

    it('kuota baru tiap bulan kalender WIB, dan bulan lalu tidak dihitung', function (): void {
        $t = SiapkanTenantKuota(1);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30', 'Asia/Jakarta'));
        expect(KirimSatu()->berhasil)->toBeTrue()
            ->and(KirimSatu()->berhasil)->toBeFalse();

        // 01.00 WIB 1 November = masih 31 Oktober UTC: bulan kuota mengikuti WIB.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 01:00', 'Asia/Jakarta'));
        expect(KirimSatu()->berhasil)->toBeTrue()
            ->and(PemakaianPesanWa::query()->orderBy('Periode')->pluck('Jumlah', 'Periode')->all())->toBe(['2026-10' => 1, '2026-11' => 1])
            ->and(app(MeterKuotaWhatsapp::class)->AmbilRingkasan($t['Tenant']->Id)['Periode'])->toBe('2026-11');
    });

    it('tanpa konteks tenant (pesan Payoung ke pemilik) memakai nomor Payoung tanpa meteran', function (): void {
        $t = SiapkanTenantKuota(0);
        $pembuat = app(PembuatPengirimWhatsapp::class);

        expect($pembuat->AmbilAktifPlatform())->not->toBeInstanceOf(PengirimWhatsappBerkuota::class)
            ->and($pembuat->AmbilAktifPlatform()->Kirim(new PesanWhatsapp('6281234567890', 'Tagihan langganan'))->berhasil)->toBeTrue()
            ->and(PemakaianPesanWa::query()->count())->toBe(0)
            ->and($t['Tenant']->Id)->toBeInt();
    });

    it('isolasi tenant: pemakaian satu tenant tidak mengurangi kuota tenant lain', function (): void {
        SiapkanTenantKuota(1);
        expect(KirimSatu()->berhasil)->toBeTrue();

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        OverrideTenant::query()->create([
            'IdTenant' => $b['Tenant']->Id, 'Jenis' => JenisOverride::Batas, 'Kunci' => 'KuotaPesanWaBulanan', 'Nilai' => 1,
            'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji', 'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);

        expect(KirimSatu()->berhasil)->toBeTrue()
            ->and(PemakaianPesanWa::query()->sole()->Jumlah)->toBe(1);
    });

    it('Kotak Tindakan: muncul saat sisa ≤ 20% (Perhatian) dan saat habis (Penting); diam bila aman, tanpa batas, atau WhatsApp sendiri aktif', function (): void {
        $t = SiapkanTenantKuota(10);
        $konteks = new DataKonteksTindakan($t['Tenant']->Id, 1, true, [], null, CarbonImmutable::today());
        $penyedia = app(PenyediaTindakanWhatsapp::class);
        $kirim = function (int $jumlah): void {
            foreach (range(1, $jumlah) as $_) {
                KirimSatu();
            }
        };

        expect($penyedia->Kumpulkan($konteks))->toBe([]);

        $kirim(8);
        $butir = $penyedia->Kumpulkan($konteks);
        expect($butir)->toHaveCount(1)
            ->and($butir[0]->kunci)->toBe('whatsapp.kuota')
            ->and($butir[0]->tingkat)->toBe(TingkatTindakan::Perhatian)
            ->and($butir[0]->keterangan)->toContain('Sisa 2 dari 10');

        $kirim(2);
        expect($penyedia->Kumpulkan($konteks)[0]->tingkat)->toBe(TingkatTindakan::Penting);

        $tanpaIzin = new DataKonteksTindakan($t['Tenant']->Id, 1, false, [], null, CarbonImmutable::today());
        expect($penyedia->Kumpulkan($tanpaIzin))->toBe([]);

        $baris = WhatsappTenant::query()->create([
            'Penyedia' => PenyediaIntegrasi::Fonnte, 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tokenSendiri-1234'],
            'PetunjukKredensial' => ['Token' => '••••1234'], 'StatusUji' => 'Berhasil', 'Aktif' => true, 'IdTenant' => $t['Tenant']->Id,
        ]);
        expect($penyedia->Kumpulkan($konteks))->toBe([]);
        $baris->delete();
    });
});

describe('F-17 kesiapan kabar pesanan toko online lewat WhatsApp', function (): void {
    it('memberi tahu pemilik bila WhatsApp belum tersambung, kuota nol, kuota habis, dan siap bila ada sisa atau nomor sendiri', function (): void {
        $siap = fn (int $idTenant): array => app(KesiapanNotifikasiPesananOnline::class)->Ambil($idTenant);

        $t = SiapkanTenantKuota(0);
        $nol = $siap($t['Tenant']->Id);
        expect($nol['Siap'])->toBeFalse()->and($nol['Alasan'])->toContain('tidak menyertakan kuota')->and($nol['GagalBulanIni'])->toBe(0);

        $baris = WhatsappTenant::query()->create([
            'Penyedia' => PenyediaIntegrasi::Fonnte, 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tokenSendiri-1234'],
            'PetunjukKredensial' => ['Token' => '••••1234'], 'StatusUji' => 'Berhasil', 'Aktif' => true, 'IdTenant' => $t['Tenant']->Id,
        ]);
        expect($siap($t['Tenant']->Id)['Siap'])->toBeTrue();
        $baris->delete();

        config(['integrasi.Whatsapp' => ['Penyedia' => null]]);
        $belum = $siap($t['Tenant']->Id);
        expect($belum['Siap'])->toBeFalse()->and($belum['Alasan'])->toContain('belum tersambung');
    });

    it('kuota terpakai habis menghasilkan peringatan kuota habis; masih ada sisa dianggap siap', function (): void {
        $t = SiapkanTenantKuota(2);
        $pengirim = app(PembuatPengirimWhatsapp::class)->AmbilAktif();
        $siap = fn (): array => app(KesiapanNotifikasiPesananOnline::class)->Ambil($t['Tenant']->Id);

        expect($siap()['Siap'])->toBeTrue();

        $pengirim->Kirim(new PesanWhatsapp('6281234567890', 'Satu'));
        $pengirim->Kirim(new PesanWhatsapp('6281234567890', 'Dua'));
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $habis = $siap();
        expect($habis['Siap'])->toBeFalse()->and($habis['Alasan'])->toContain('sudah habis');
    });
});
