<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Aksi\AjukanKoreksiAbsensi;
use App\Domain\Karyawan\Aksi\AturTautanAbsen;
use App\Domain\Karyawan\Aksi\CatatAbsensiWeb;
use App\Domain\Karyawan\Aksi\DaftarkanWajahKaryawan;
use App\Domain\Karyawan\Kueri\CekKecocokanWajah;
use App\Domain\Karyawan\Kueri\StatusAbsensiWeb;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Absensi\PembacaKirimanAbsensi;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Absen lewat tautan pribadi `/{slugTenant}/absen/{token}` (F-18 bagian 4, D-37, D-198): jalan masuk kedua ke halaman Absen,
 * tanpa login, untuk staf yang tidak punya akun dashboard. Pengelola membuat tautan per karyawan dan membagikannya (WhatsApp).
 * Setelah tautan terbukti sah, semuanya sama dengan halaman berlogin `/kelola/absen`: wajah didaftarkan sekali lalu tiap
 * absen mencocokkan wajah + lokasi, lewat aksi dan aturan yang sama. Tautan tak dikenal, dicabut, karyawan nonaktif, atau
 * yang tidak lagi ikut absensi = 404 yang sama. Isi kiriman baru divalidasi setelah tautan terbukti sah.
 */
final class AbsenTautanKontroler extends Kontroler
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PencatatAudit $audit,
        private readonly PembacaKirimanAbsensi $pembaca,
    ) {}

    public function Tampilkan(string $slugTenant, string $tokenAbsen, StatusAbsensiWeb $status): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, fn (Karyawan $karyawan, int $idTenant): SymfonyResponse => Inertia::render('Publik/Absensi', [
            'NamaToko' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
            'NamaKaryawan' => $karyawan->Nama,
            'AlamatDasar' => url("/{$slugTenant}/absen/{$tokenAbsen}"),
            'AlamatModelWajah' => asset('model-wajah'),
            'Berlogin' => false,
            'BisaDashboard' => false,
            ...$status->Ambil($karyawan),
        ])->toResponse(request()));
    }

    public function DaftarWajah(Request $permintaan, string $slugTenant, string $tokenAbsen, DaftarkanWajahKaryawan $daftar): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, function (Karyawan $karyawan) use ($permintaan, $daftar): JsonResponse {
            $valid = $this->pembaca->BacaPendaftaranWajah($permintaan);
            $wajah = $daftar->Jalankan($karyawan, $valid['SidikWajah'], $valid['Foto'], $valid['Persetujuan']);

            return response()->json(['Status' => $wajah->Status->value, 'Pesan' => 'Wajah terdaftar. Anda sudah bisa absen.'], 201);
        });
    }

    /** Umpan balik langsung saat kamera menyala (D-186): hanya cocok atau tidak. */
    public function CekWajah(Request $permintaan, string $slugTenant, string $tokenAbsen, CekKecocokanWajah $cek): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, fn (Karyawan $karyawan): JsonResponse => response()->json([
            'Cocok' => $cek->Jalankan($karyawan, $this->pembaca->BacaSidikCekWajah($permintaan)),
        ]));
    }

    public function Masuk(Request $permintaan, string $slugTenant, string $tokenAbsen, CatatAbsensiWeb $catat): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, function (Karyawan $karyawan) use ($permintaan, $catat): JsonResponse {
            $absensi = $catat->Masuk($karyawan, $this->pembaca->BacaDataAbsen($permintaan));

            return response()->json(['Uuid' => $absensi->Uuid, 'MasukPada' => $absensi->MasukPada->toIso8601String(), 'JarakMeter' => $absensi->JarakMasukMeter, 'PerluDicek' => $absensi->PerluDicek]);
        });
    }

    public function Keluar(Request $permintaan, string $slugTenant, string $tokenAbsen, CatatAbsensiWeb $catat): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, function (Karyawan $karyawan) use ($permintaan, $catat): JsonResponse {
            $absensi = $catat->Keluar($karyawan, $this->pembaca->BacaDataAbsen($permintaan));

            return response()->json(['Uuid' => $absensi->Uuid, 'KeluarPada' => $absensi->KeluarPada?->toIso8601String(), 'JarakMeter' => $absensi->JarakKeluarMeter, 'PerluDicek' => $absensi->PerluDicek]);
        });
    }

    public function MintaKoreksi(Request $permintaan, string $slugTenant, string $tokenAbsen, AjukanKoreksiAbsensi $ajukan): SymfonyResponse
    {
        return $this->DenganKaryawan($slugTenant, $tokenAbsen, function (Karyawan $karyawan) use ($permintaan, $ajukan): JsonResponse {
            $valid = $this->pembaca->BacaPermintaanKoreksi($permintaan);
            $baris = $ajukan->Jalankan($karyawan, $valid['Jenis'], $valid['Alasan']);

            return response()->json(['Uuid' => $baris->Uuid, 'Status' => $baris->Status->value], 201);
        });
    }

    /** @param Closure(Karyawan, int): SymfonyResponse $kerja */
    private function DenganKaryawan(string $slugTenant, string $tokenAbsen, Closure $kerja): SymfonyResponse
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);
        abort_if($idTenant === null || strlen($tokenAbsen) !== AturTautanAbsen::PANJANG_TOKEN, 404);
        $this->konteks->Atur($idTenant);
        // Halaman publik tanpa perantara audit: jejak percobaan (di luar radius, wajah tidak cocok) tetap ber-IP.
        $this->audit->AturKonteks(null, request()->ip(), request()->userAgent());

        try {
            $karyawan = Karyawan::query()->wajibAbsen()->where('HashTokenAbsen', AturTautanAbsen::Hash($tokenAbsen))->first();
            abort_if(! $karyawan instanceof Karyawan, 404);

            return $kerja($karyawan, $idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
