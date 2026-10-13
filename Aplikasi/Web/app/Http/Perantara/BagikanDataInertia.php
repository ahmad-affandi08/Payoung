<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Layanan\KonteksAksesDukungan;
use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Organisasi\Kueri\PusatNotifikasiWeb;
use App\Domain\Organisasi\Kueri\SektorOutletTenant;
use App\Domain\Organisasi\Layanan\PenentuWajibDuaFaktor;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Tenant\Enum\PlatformPengumuman;
use App\Domain\Tenant\Kueri\PenawaranFiturTenant;
use App\Domain\Tenant\Kueri\PengumumanBerlaku;
use App\Domain\Tenant\Kueri\PersetujuanLegalTertunda;
use App\Domain\Tenant\Kueri\RingkasanLanggananTenant;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use App\Domain\Tenant\Model\DokumenLegal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * Perantara Inertia: menentukan view root dan data yang dibagikan ke semua halaman (PRD §13.5).
 */
final class BagikanDataInertia extends Middleware
{
    protected $rootView = 'Aplikasi';

    public function __construct(
        private readonly RingkasanTenant $ringkasanTenant,
        private readonly KeanggotaanPengguna $keanggotaan,
        // BR-P06.5: banner pengumuman versi materiil dokumen legal.
        private readonly PemilikTenant $pemilikTenant,
        private readonly PersetujuanLegalTertunda $persetujuanLegal,
        // F-00: banner status langganan Tertunggak/Ditangguhkan.
        private readonly RingkasanLanggananTenant $ringkasanLangganan,
        // PGL-16: banner merah selama sesi akses dukungan.
        private readonly KonteksAksesDukungan $dukungan,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $pengguna = $request->user('web');
        $idTenant = $request->session()->get(IdentifikasiTenantSesi::KUNCI_SESI);

        return [
            ...parent::share($request),
            'NamaAplikasi' => config('app.name'),
            // D-35: edisi Lisensi menyembunyikan pendaftaran, langganan, dan tiket bantuan di antarmuka.
            'Edisi' => EdisiAplikasi::AmbilBerjalan()->value,
            // D-20: logo di halaman masuk/daftar menuju situs pemasaran (bisa host lain).
            'UrlPemasaran' => ArahkanDomainAplikasi::BuatUrlPemasaran('/'),
            // `always`: ikut di setiap respons (juga muat ulang sebagian), supaya pesan lama tidak menempel dan dialog hasil
            // tidak muncul lagi setiap polling.
            'Kilat' => Inertia::always(fn () => $request->session()->get('Kilat')),
            'Pengguna' => fn () => $pengguna instanceof Pengguna ? [
                'Uuid' => $pengguna->Uuid,
                'Nama' => $pengguna->Nama,
                'Email' => $pengguna->Email,
                // BR-00.5: banner pengingat selama email belum terverifikasi.
                'EmailTerverifikasi' => $pengguna->EmailDiverifikasiPada !== null,
            ] : null,
            // Nama & status langganan tenant aktif hanya bila pengguna masih anggotanya (F-00: banner Tertunggak/Ditangguhkan).
            'TenantAktif' => function () use ($pengguna, $idTenant): ?array {
                $anggota = $pengguna instanceof Pengguna && is_int($idTenant)
                    && ($this->dukungan->CekPengguna($idTenant, $pengguna->Id) || $this->keanggotaan->CekAnggota($pengguna->Id, $idTenant));
                $tenant = $anggota && is_int($idTenant) ? ($this->ringkasanTenant->Ambil([$idTenant])[0] ?? null) : null;

                if ($tenant === null) {
                    return null;
                }

                $langganan = $this->ringkasanLangganan->Ambil($tenant['Id']);
                $pathLogo = $tenant['PathLogo'] ?? null;
                $tautanLogo = $pathLogo !== null
                    ? route('kelola.pengaturan.profil-usaha.logo', ['v' => substr(hash('sha256', (string) $pathLogo), 0, 12)])
                    : null;

                return [
                    'Id' => $tenant['Id'],
                    'KodePelanggan' => sprintf('#%07d', $tenant['Id']),
                    'Nama' => $tenant['Nama'],
                    'Slug' => $tenant['Slug'],
                    'TautanLogo' => $tautanLogo,
                    'NamaPaket' => $langganan['NamaPaket'] ?? 'Dasar',
                    'KodePaket' => $langganan['KodePaket'] ?? '',
                    'StatusLangganan' => $langganan === null ? null : $langganan['Status']->value,
                    'PeriodeSelesai' => $langganan === null ? null : $langganan['PeriodeSelesai']?->toIso8601ZuluString(),
                    'BatasTenggangPada' => $langganan === null ? null : $langganan['BatasTenggangPada']?->toIso8601ZuluString(),
                    'TagihanTertunda' => $langganan['TagihanTertunda'] ?? null,
                ];
            },
            // PGL-16: banner merah "Anda mengakses akun … sebagai Dukungan" selama sesi akses dukungan; null untuk pengguna biasa.
            'AksesDukungan' => function (): ?array {
                $sesi = $this->dukungan->Ambil();

                return $sesi === null ? null : [
                    'NamaPetugas' => $sesi->namaPetugas,
                    'Cakupan' => $sesi->cakupan->value,
                    'LabelCakupan' => $sesi->cakupan->AmbilLabel(),
                    'Darurat' => $sesi->jenis === JenisAksesDukungan::Darurat,
                    'DataPribadiTerbuka' => $sesi->dataPribadiTerbuka,
                    'BerakhirPada' => $sesi->berakhirPada->toIso8601ZuluString(),
                ];
            },
            // BR-P06.5: banner di back-office selama masa pengumuman versi materiil, hanya untuk Owner tenant aktif.
            // D-35: edisi Lisensi tanpa dokumen legal platform (pembeli sendiri penyelenggaranya).
            'PengumumanLegal' => function () use ($pengguna, $idTenant): array {
                if (EdisiAplikasi::CekLisensi() || ! $pengguna instanceof Pengguna || ! is_int($idTenant) || ! $this->pemilikTenant->CekPemilik($pengguna->Id, $idTenant)) {
                    return [];
                }

                return array_map(fn (DokumenLegal $dokumen): array => [
                    'Label' => $dokumen->Jenis->AmbilLabel(),
                    'Versi' => $dokumen->Versi,
                    'BerlakuMulai' => $dokumen->BerlakuMulai->toDateString(),
                    'Tautan' => route('legal.tampil', ['jenis' => Str::kebab($dokumen->Jenis->value), 'versi' => $dokumen->Versi]),
                ], $this->persetujuanLegal->AmbilPengumuman(now()));
            },
            // D-38: 2FA wajib (paket Bisnis) ditunda selama trial; pemegang peran wajib melihat banner pengingat.
            'PengingatDuaFaktor' => function () use ($pengguna): bool {
                $idTenant = app(KonteksTenant::class)->Ambil();

                if (! $pengguna instanceof Pengguna || $idTenant === null || $pengguna->CekDuaFaktorAktif() || session(SesiAutentikasiTenant::MASUK_GOOGLE) === true) {
                    return false;
                }

                $penentu = app(PenentuWajibDuaFaktor::class);

                return $penentu->CekWajib($pengguna->Id, $idTenant) && $penentu->CekDitunda($idTenant);
            },
            // F-02: hak akses di tenant aktif untuk menu & tombol (hanya UX; server tetap penentu lewat WajibIzinTenant).
            'Akses' => function () use ($pengguna): ?array {
                $idTenant = app(KonteksTenant::class)->Ambil();
                $akses = $pengguna instanceof Pengguna && $idTenant !== null ? app(AksesPengguna::class)->Ambil($idTenant, $pengguna->Id) : null;

                // D-35: izin yang halamannya tidak ada di edisi Lisensi (langganan & tiket bantuan Payoung) dimatikan
                // juga untuk Pemilik, supaya menu, Pengaturan, dan Ctrl+K tidak menautkan ke halaman 404.
                $nonaktif = EdisiAplikasi::CekLisensi()
                    ? [IzinTenant::LanggananKelola->value, IzinTenant::BantuanTiketLihat->value, IzinTenant::BantuanTiketKelola->value, IzinTenant::DukunganAksesKelola->value]
                    : [];

                return $akses === null ? null : ['Pemilik' => $akses['Pemilik'], 'Izin' => array_values(array_diff($akses['Izin'], $nonaktif)), 'IzinNonaktif' => $nonaktif];
            },
            // D-23: fitur di luar paket tetap tampil di menu; klik = dialog naik paket / add-on (hanya UX).
            'FiturPaket' => function () use ($pengguna): ?array {
                $idTenant = app(KonteksTenant::class)->Ambil();

                return $pengguna instanceof Pengguna && $idTenant !== null ? app(PenawaranFiturTenant::class)->Ambil($idTenant) : null;
            },
            // P-10 PGL-19: pengumuman & banner pemeliharaan platform untuk paket & sektor tenant aktif.
            'PengumumanPlatform' => function () use ($pengguna): array {
                $idTenant = app(KonteksTenant::class)->Ambil();

                if (! $pengguna instanceof Pengguna || $idTenant === null) {
                    return [];
                }

                $kodePaket = $this->ringkasanLangganan->Ambil($idTenant)['KodePaket'] ?? null;

                return app(PengumumanBerlaku::class)->AmbilUntuk(PlatformPengumuman::Web, $kodePaket, app(SektorOutletTenant::class)->AmbilKode(), null);
            },
            // F31 (PLT-08): jumlah notifikasi belum dibaca untuk lencana lonceng di kepala back-office.
            'NotifikasiBelumDibaca' => function () use ($pengguna): int {
                return $pengguna instanceof Pengguna && app(KonteksTenant::class)->Ambil() !== null
                    ? app(PusatNotifikasiWeb::class)->HitungBelumDibaca($pengguna->Id)
                    : 0;
            },
            // Audit kemudahan pakai #33 (D-38): menu modul khusus sektor (grosir, laundry, reservasi, apotek, …) hanya
            // tampil bila ada outlet bersektor itu; kosong = sektor belum diketahui, semua menu tampil.
            'SektorOutlet' => function () use ($pengguna): array {
                return $pengguna instanceof Pengguna && app(KonteksTenant::class)->Ambil() !== null
                    ? app(SektorOutletTenant::class)->AmbilKode()
                    : [];
            },
        ];
    }
}
