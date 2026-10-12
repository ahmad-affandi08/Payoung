<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Layanan\PembuatTokenDukungan;
use App\Domain\Dukungan\Model\PenggunaDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use Illuminate\Support\Facades\DB;

/**
 * Menukar token sekali pakai dari konsol dengan sesi back-office tenant (PGL-16): konsol dan back-office berbeda domain
 * sehingga cookie-nya terpisah. Token berumur `DETIK_UMUR_TOKEN`, disimpan sebagai hash, dan hanya bisa dipakai sekali
 * (penandaan memakai UPDATE bersyarat, jadi dua tab yang menukar bersamaan hanya satu yang berhasil). Sesi lain petugas
 * yang sama di tenant ini berakhir ("digantikan"). Semua kegagalan memberi pesan yang sama supaya token tidak bisa ditebak.
 * Konteks tenant sudah ditetapkan pemanggil dari alamat tukar.
 */
final class TukarTokenAksesDukungan
{
    /** Batas umur satu sesi sejak ditukar, juga dibatasi sisa umur izin induknya. */
    public const JAM_UMUR_SESI = 4;

    public const PESAN_GAGAL = 'Tautan akses dukungan tidak berlaku atau sudah dipakai. Minta tautan baru dari konsol.';

    public function __construct(
        private readonly AkhiriSesiAksesDukungan $akhiri,
        private readonly PencatatAudit $audit,
        private readonly GerbangPengelola $gerbang,
    ) {}

    /**
     * @return array{Sesi: SesiAksesDukungan, IdPengguna: int}
     *
     * @throws PelanggaranAturanBisnis TokenTidakSah
     */
    public function Jalankan(string $token, ?string $ip, ?string $agenPengguna): array
    {
        $gagal = new PelanggaranAturanBisnis('TokenTidakSah', self::PESAN_GAGAL, statusHttp: 403);
        $hash = PembuatTokenDukungan::Hash($token);

        return DB::transaction(function () use ($hash, $ip, $agenPengguna, $gagal): array {
            $sesi = SesiAksesDukungan::query()->with('Akses')->where('HashToken', $hash)->lockForUpdate()->first();

            if (! $sesi instanceof SesiAksesDukungan || $sesi->TokenDipakaiPada !== null || $sesi->DiakhiriPada !== null || $sesi->TokenKedaluwarsaPada->lte(now())) {
                throw $gagal;
            }

            $akses = $sesi->Akses;

            if (! $akses->CekAktif() || ! $this->gerbang->CekPetugasAktif($sesi->IdPenggunaPengelola)) {
                throw $gagal;
            }

            $idPengguna = PenggunaDukungan::query()->where('IdPenggunaPengelola', $sesi->IdPenggunaPengelola)->value('IdPengguna');

            if (! is_int($idPengguna)) {
                throw $gagal;
            }

            // Sesi petugas yang sama di tenant ini yang masih berjalan digantikan sesi baru ini.
            $sebelumnya = SesiAksesDukungan::query()
                ->where('IdPenggunaPengelola', $sesi->IdPenggunaPengelola)
                ->whereNull('DiakhiriPada')
                ->whereNotNull('TokenDipakaiPada')
                ->whereKeyNot($sesi->Id)
                ->get();

            foreach ($sebelumnya as $lama) {
                $this->akhiri->Jalankan($lama, AlasanAkhirSesiDukungan::Digantikan);
            }

            $batas = now()->addHours(self::JAM_UMUR_SESI);
            $sesi->TokenDipakaiPada = now();
            $sesi->MulaiPada = now();
            $sesi->BerakhirPada = $akses->BerakhirPada->lt($batas) ? $akses->BerakhirPada : $batas;
            $sesi->Ip = $ip;
            $sesi->AgenPengguna = $agenPengguna === null ? null : mb_substr($agenPengguna, 0, 500);
            $sesi->save();

            $this->audit->Catat('dukungan.sesi.mulai', $sesi, nilaiBaru: [
                'Petugas' => $sesi->NamaPetugas,
                'Jenis' => $akses->Jenis->value,
                'Cakupan' => $akses->Cakupan->value,
                'BerakhirPada' => $sesi->BerakhirPada->toIso8601String(),
            ], idPengguna: $idPengguna, idPenggunaPengelola: $sesi->IdPenggunaPengelola);
            $this->gerbang->CatatJejak('dukungan.sesi.mulai', $sesi->IdPenggunaPengelola, $sesi->IdTenant, [
                'UuidSesi' => $sesi->Uuid,
                'Jenis' => $akses->Jenis->value,
                'Cakupan' => $akses->Cakupan->value,
            ], ip: $ip);

            return ['Sesi' => $sesi, 'IdPengguna' => $idPengguna];
        });
    }
}
