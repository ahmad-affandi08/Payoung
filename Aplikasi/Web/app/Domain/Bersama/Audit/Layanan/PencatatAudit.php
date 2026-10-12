<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Audit\Layanan;

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\KonteksTenant;
use Illuminate\Support\Str;

/**
 * Satu-satunya jalan menulis `LogAudit` tenant (aturan `LogAudit` §13.2, §25 no. 17).
 *
 * Terdaftar sebagai `scoped`: perantara back-office mengisi pelaku, IP, dan agen pengguna per request sehingga
 * Aksi domain tidak perlu mengenal objek Request. Tenant diambil dari `KonteksTenant` kecuali disebut eksplisit
 * (misal saat pendaftaran atau masuk, sebelum tenant aktif ditetapkan).
 */
final class PencatatAudit
{
    private ?int $idPengguna = null;

    private ?int $idPerangkat = null;

    /** PGL-16: pelaku pengelola bila permintaan ini berasal dari sesi akses dukungan (penanda di setiap baris audit). */
    private ?int $idPenggunaPengelola = null;

    private ?string $ip = null;

    private ?string $agenPengguna = null;

    public function __construct(private readonly KonteksTenant $konteks) {}

    public function AturKonteks(?int $idPengguna, ?string $ip, ?string $agenPengguna, ?int $idPerangkat = null): void
    {
        $this->idPengguna = $idPengguna;
        $this->ip = $ip;
        $this->agenPengguna = self::RingkasAgen($agenPengguna);
        $this->idPerangkat = $idPerangkat;
    }

    /**
     * Pengguna pelaku dari konteks audit (back-office lewat `SiapkanAuditTenant`, atau job yang mengatur konteks
     * pengguna pemicunya). Dipakai data yang mencatat pengubah sendiri, misal `RiwayatHarga.DiubahOleh` (F-03).
     */
    public function AmbilIdPengguna(): ?int
    {
        return $this->idPengguna;
    }

    /**
     * PGL-16: tandai semua audit permintaan ini sebagai dilakukan petugas pengelola lewat akses dukungan. Dipanggil
     * `PasangAksesDukungan` setelah sesi terbukti sah; `null` melepas tanda.
     */
    public function AturPelakuPengelola(?int $idPenggunaPengelola): void
    {
        $this->idPenggunaPengelola = $idPenggunaPengelola;
    }

    /** F-02b: perangkat pelaku diketahui setelah token/kode aktivasi diperiksa (API POS). */
    public function AturPerangkat(?int $idPerangkat): void
    {
        $this->idPerangkat = $idPerangkat;
    }

    /**
     * @param  array<string, mixed>|null  $nilaiLama
     * @param  array<string, mixed>|null  $nilaiBaru
     */
    public function Catat(
        string $peristiwa,
        ?ModelDasar $objek = null,
        ?array $nilaiLama = null,
        ?array $nilaiBaru = null,
        ?int $idTenant = null,
        ?int $idPengguna = null,
        ?int $idPenggunaPengelola = null,
    ): LogAudit {
        return $this->Tulis($peristiwa, $objek, $nilaiLama, $nilaiBaru, $idTenant ?? $this->konteks->Wajib(), $idPengguna ?? $this->idPengguna, $this->ip, $this->agenPengguna, $idPenggunaPengelola);
    }

    /**
     * Peristiwa akun di luar back-office (pendaftaran, masuk, keluar, pilih tenant) dicatat dalam satu baris dari
     * pemanggilnya, sebelum tenant aktif ditetapkan untuk request itu. Konteks pencatat tidak diubah, sehingga tidak
     * terbawa ke pencatatan berikutnya di proses yang sama. Tanpa tenant atau pengguna yang jelas (misal keluar
     * tanpa tenant aktif) tidak ada yang dicatat.
     *
     * @param  array<string, mixed>|null  $nilaiBaru
     */
    public function CatatSesi(string $peristiwa, mixed $idTenant, mixed $idPengguna, ?string $ip, ?string $agenPengguna, ?array $nilaiBaru = null): ?LogAudit
    {
        if (! is_int($idTenant) || ! is_int($idPengguna)) {
            return null;
        }

        return $this->Tulis($peristiwa, null, null, $nilaiBaru, $idTenant, $idPengguna, $ip, self::RingkasAgen($agenPengguna));
    }

    /**
     * @param  array<string, mixed>|null  $nilaiLama
     * @param  array<string, mixed>|null  $nilaiBaru
     */
    private function Tulis(
        string $peristiwa,
        ?ModelDasar $objek,
        ?array $nilaiLama,
        ?array $nilaiBaru,
        int $idTenant,
        ?int $idPengguna,
        ?string $ip,
        ?string $agenPengguna,
        ?int $idPenggunaPengelola = null,
    ): LogAudit {
        $idObjek = $objek?->getKey();

        return LogAudit::query()->create([
            'IdTenant' => $idTenant,
            'IdPengguna' => $idPengguna,
            'IdPerangkat' => $this->idPerangkat,
            'IdPenggunaPengelola' => $idPenggunaPengelola ?? $this->idPenggunaPengelola,
            'Peristiwa' => $peristiwa,
            'JenisObjek' => $objek === null ? null : class_basename($objek),
            'IdObjek' => is_int($idObjek) ? $idObjek : null,
            'NilaiLama' => $nilaiLama,
            'NilaiBaru' => $nilaiBaru,
            'Ip' => $ip,
            'AgenPengguna' => $agenPengguna,
        ]);
    }

    private static function RingkasAgen(?string $agenPengguna): ?string
    {
        return $agenPengguna === null ? null : Str::limit($agenPengguna, 490, '');
    }
}
