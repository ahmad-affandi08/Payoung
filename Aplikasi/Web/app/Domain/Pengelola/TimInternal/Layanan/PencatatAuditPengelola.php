<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TimInternal\Layanan;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;

/**
 * Satu-satunya jalan menulis `LogAuditPengelola` (BR-P01.3).
 *
 * Terdaftar sebagai `scoped`: perantara `CatatAuditPengelola` mengisi pelaku & IP per request,
 * sehingga Aksi domain tidak perlu mengenal objek Request.
 */
final class PencatatAuditPengelola
{
    private ?int $idPelaku = null;

    private ?string $ip = null;

    public function AturKonteks(?int $idPelaku, ?string $ip): void
    {
        $this->idPelaku = $idPelaku;
        $this->ip = $ip;
    }

    /**
     * @param  array<string, mixed>|null  $nilaiLama
     * @param  array<string, mixed>|null  $nilaiBaru
     */
    public function Catat(
        string $aksi,
        ?ModelDasar $objek = null,
        ?array $nilaiLama = null,
        ?array $nilaiBaru = null,
        ?string $alasan = null,
        ?int $idPelaku = null,
        ?int $idTenant = null,
        ?string $ip = null,
    ): LogAuditPengelola {
        $idObjek = $objek?->getKey();

        return LogAuditPengelola::query()->create([
            'IdPenggunaPengelola' => $idPelaku ?? $this->idPelaku,
            'Aksi' => $aksi,
            'JenisObjek' => $objek === null ? null : class_basename($objek),
            'IdObjek' => is_int($idObjek) ? $idObjek : null,
            'IdTenant' => $idTenant,
            'NilaiLama' => $nilaiLama,
            'NilaiBaru' => $nilaiBaru,
            'Alasan' => $alasan,
            'Ip' => $ip ?? $this->ip,
        ]);
    }

    // P-07 Siklus hidup tenant: riwayat tindakan pengelola pada satu tenant (BR-P07.3). Dibaca lewat kelas ini karena
    // `LogAuditPengelola` hanya boleh disentuh di sini dan di halaman log audit (test arsitektur Pengelola).

    /**
     * Tindakan terbaru pada satu tenant, tanpa log akses baca (`$kecualiAksi`).
     *
     * @param  list<string>  $kecualiAksi
     * @return list<array{Id: int, Aksi: string, Pelaku: string, NilaiLama: array<string, mixed>|null, NilaiBaru: array<string, mixed>|null, Alasan: string|null, DibuatPada: string}>
     */
    public function AmbilRiwayatTenant(int $idTenant, int $batas, array $kecualiAksi = []): array
    {
        return array_values(LogAuditPengelola::query()
            ->with('Pelaku:Id,Nama')
            ->where('IdTenant', $idTenant)
            ->when($kecualiAksi !== [], fn ($kueri) => $kueri->whereNotIn('Aksi', $kecualiAksi))
            ->orderByDesc('Id')
            ->limit($batas)
            ->get()
            ->map(fn (LogAuditPengelola $log): array => [
                'Id' => $log->Id,
                'Aksi' => $log->Aksi,
                'Pelaku' => $log->Pelaku->Nama ?? 'Sistem',
                'NilaiLama' => $log->NilaiLama,
                'NilaiBaru' => $log->NilaiBaru,
                'Alasan' => $log->Alasan,
                'DibuatPada' => $log->DibuatPada->toIso8601String(),
            ])
            ->all());
    }
}
