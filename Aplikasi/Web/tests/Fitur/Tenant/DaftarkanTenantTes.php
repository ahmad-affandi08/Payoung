<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Tenant\TenantBelumDitetapkan;
use App\Domain\Organisasi\Galat\IdentitasSudahTerdaftar;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Surel\UpayaPendaftaranAkunTerdaftar;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\DokumenLegal;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PersetujuanDokumenLegal;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('Pendaftaran tenant (F-00)', function (): void {
    it('membentuk tenant, Owner, langganan Trial 14 hari, persetujuan legal, Outlet Utama, dan gudang bawaan', function (): void {
        $hasil = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        $tenant = $hasil['Tenant'];

        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        expect($tenant->Slug)->toBe('kopi-nusantara')
            ->and($hasil['Pengguna']->EmailDiverifikasiPada)->toBeNull()
            ->and(TenantPengguna::query()->where('IdTenant', $tenant->Id)->sole()->Pemilik)->toBeTrue()
            ->and($langganan->Status)->toBe(StatusLangganan::Trial)
            ->and($langganan->Paket->Kode)->toBe('PRO')
            ->and($langganan->TrialBerakhirPada?->equalTo(now()->addDays(14)))->toBeTrue()
            ->and(PersetujuanDokumenLegal::query()->where('IdTenant', $tenant->Id)->pluck('IdDokumenLegal')->sort()->values()->all())
            ->toBe(DokumenLegal::query()->orderBy('Id')->pluck('Id')->all())
            ->and(PersetujuanDokumenLegal::query()->first()?->Ip)->toBe('203.0.113.9');

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect(Outlet::query()->sole()->Nama)->toBe('Kopi Nusantara')
            ->and(Gudang::query()->sole()->IdOutlet)->toBe(Outlet::query()->sole()->Id);
    });

    it('BR-00.2: slug unik dari nama usaha dan tidak memakai kata rute sistem', function (): void {
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('dua@contoh.id', '081200000002'));
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('tiga@contoh.id', '081200000003', namaUsaha: 'Kelola'));

        expect(Tenant::query()->orderBy('Id')->pluck('Slug')->all())->toBe(['kopi-nusantara', 'kopi-nusantara-2', 'kelola-usaha']);
    });

    it('BR-00.1: email dan nomor WhatsApp unik; pendaftaran gagal tidak meninggalkan data', function (): void {
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());

        expect(fn () => app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data(noHp: '081299999999')))
            ->toThrow(PelanggaranAturanBisnis::class, IdentitasSudahTerdaftar::PESAN_UMUM)
            ->and(fn () => app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('lain@contoh.id')))
            ->toThrow(PelanggaranAturanBisnis::class, IdentitasSudahTerdaftar::PESAN_UMUM)
            ->and(Tenant::query()->count())->toBe(1)
            ->and(Pengguna::query()->count())->toBe(1);
    });

    it('§25 no. 18: email & nomor yang sudah dipakai ditolak dengan pesan yang sama, dan pemilik akun diberi tahu lewat email', function (): void {
        Mail::fake();
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data());
        app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'));

        $pesan = [];
        foreach ([
            'email saja' => BantuanPendaftaran::Data(noHp: '081299999999'),
            'nomor saja' => BantuanPendaftaran::Data('baru@contoh.id'),
            'email Rina + nomor Budi' => BantuanPendaftaran::Data('RINA@kopinusantara.id', '081200000077'),
        ] as $kasus => $data) {
            try {
                app(DaftarkanTenant::class)->Jalankan($data);
            } catch (PelanggaranAturanBisnis $galat) {
                $pesan[$kasus] = [$galat->kode, $galat->bidang, $galat->getMessage()];
            }
        }

        // Tiga penyebab berbeda, satu jawaban yang sama: pendaftar tidak bisa menebak data mana yang terdaftar.
        expect(array_unique(array_map('serialize', $pesan)))->toHaveCount(1)
            ->and(array_values($pesan)[0])->toBe(['BR-00.1', 'Email', IdentitasSudahTerdaftar::PESAN_UMUM])
            ->and(Tenant::query()->count())->toBe(2);

        // Rina diberi tahu sekali saja dalam satu jam walau namanya dipakai tiga kali; Budi sekali (nomornya).
        Mail::assertQueued(UpayaPendaftaranAkunTerdaftar::class, 2);
        Mail::assertQueued(UpayaPendaftaranAkunTerdaftar::class, fn (UpayaPendaftaranAkunTerdaftar $surel) => $surel->hasTo('rina@kopinusantara.id') && $surel->identitas === ['email']);
        Mail::assertQueued(UpayaPendaftaranAkunTerdaftar::class, fn (UpayaPendaftaranAkunTerdaftar $surel) => $surel->hasTo('budi@toko.id') && $surel->identitas === ['nomor WhatsApp']);
    });

    it('BR-00.6: paket pilihan dipakai bila aktif; GRATIS langsung berstatus Gratis; negosiasi memakai paket bawaan', function (): void {
        $starter = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data(kodePaket: 'STARTER'))['Tenant'];
        $gratis = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('b@contoh.id', '081200000010', 'GRATIS'))['Tenant'];
        $enterprise = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('c@contoh.id', '081200000011', 'ENTERPRISE'))['Tenant'];

        expect($starter->Langganan?->Paket->Kode)->toBe('STARTER')
            ->and($gratis->Langganan?->Status)->toBe(StatusLangganan::Gratis)
            ->and($gratis->Langganan?->TrialBerakhirPada)->toBeNull()
            ->and($enterprise->Langganan?->Paket->Kode)->toBe('PRO');
    });

    it('BR-P06.2: pendaftaran ditolak bila S&K atau Kebijakan Privasi belum berlaku', function (): void {
        DokumenLegal::query()->where('Jenis', 'KebijakanPrivasi')->delete();

        expect(fn () => app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data()))->toThrow(PelanggaranAturanBisnis::class)
            ->and(Tenant::query()->count())->toBe(0);
    });

    it('isolasi tenant: data organisasi tenant A tidak terlihat dari konteks tenant B; tanpa konteks gagal tertutup', function (): void {
        $a = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Tenant'];
        $b = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', namaUsaha: 'Toko Budi'))['Tenant'];
        $konteks = app(KonteksTenant::class);

        $konteks->Atur($a->Id);
        $outletA = Outlet::query()->sole();
        $gudangA = Gudang::query()->sole();
        $merekA = Merek::query()->sole();

        $konteks->Atur($b->Id);
        expect(Outlet::query()->whereKey($outletA->Id)->exists())->toBeFalse()
            ->and(Outlet::query()->where('Uuid', $outletA->Uuid)->exists())->toBeFalse()
            ->and(Gudang::query()->whereKey($gudangA->Id)->exists())->toBeFalse()
            ->and(Merek::query()->whereKey($merekA->Id)->exists())->toBeFalse()
            ->and(Outlet::query()->where('Id', $outletA->Id)->update(['Nama' => 'Diretas']))->toBe(0)
            ->and(Outlet::query()->sole()->IdTenant)->toBe($b->Id);

        $konteks->Kosongkan();
        expect(fn () => Outlet::query()->count())->toThrow(TenantBelumDitetapkan::class)
            ->and(fn () => Gudang::query()->count())->toThrow(TenantBelumDitetapkan::class)
            ->and(fn () => Merek::query()->count())->toThrow(TenantBelumDitetapkan::class);
    });

    it('BR-00.7: tabel transisi status langganan', function (StatusLangganan $asal, StatusLangganan $tujuan, bool $sah): void {
        expect($asal->BisaBerubahKe($tujuan))->toBe($sah);
    })->with(function (): array {
        // P-07 (BR-P07.4, BR-P07.5) menambah tangguhkan manual dari Trial/Aktif/Gratis dan pemulihan ke Trial/Tertunggak.
        // BR-P04.4 (D-117) menambah Aktif → Gratis untuk turun paket mandiri saat periode berbayar berakhir.
        // P-07 (D-119) menambah Berhenti dari Trial/Tertunggak/Gratis untuk penutupan akun lewat penghapusan data (UU PDP).
        $sah = [
            'Trial' => ['Aktif', 'Gratis', 'Ditangguhkan', 'Berhenti'],
            'Aktif' => ['Tertunggak', 'Berhenti', 'Ditangguhkan', 'Gratis'],
            'Tertunggak' => ['Aktif', 'Ditangguhkan', 'Berhenti'],
            'Ditangguhkan' => ['Aktif', 'Gratis', 'Berhenti', 'Trial', 'Tertunggak'],
            'Gratis' => ['Aktif', 'Ditangguhkan', 'Berhenti'],
            'Berhenti' => [],
        ];
        $kasus = [];

        foreach (StatusLangganan::cases() as $asal) {
            foreach (StatusLangganan::cases() as $tujuan) {
                $kasus["{$asal->value} -> {$tujuan->value}"] = [$asal, $tujuan, in_array($tujuan->value, $sah[$asal->value], true)];
            }
        }

        return $kasus;
    });

    it('BR-00.7: transisi status langganan di luar state machine ditolak', function (): void {
        $tenant = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data())['Tenant'];
        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();

        // Trial → Ditangguhkan sah sejak P-07 (tangguhkan manual); Trial → Tertunggak tetap tidak sah.
        expect(fn () => $langganan->update(['Status' => StatusLangganan::Tertunggak]))->toThrow(LogicException::class);
        $langganan->update(['Status' => StatusLangganan::Gratis]);
        expect($langganan->refresh()->Status)->toBe(StatusLangganan::Gratis);
    });
});
