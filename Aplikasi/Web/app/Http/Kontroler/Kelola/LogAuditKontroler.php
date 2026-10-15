<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Audit\Kueri\DaftarLogAudit;
use App\Domain\Bersama\Audit\Layanan\KamusPeristiwaAudit;
use App\Domain\Bersama\Audit\Layanan\PenerjemahPerubahanAudit;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Log audit tenant, hanya baca (aturan `LogAudit` §13.2, §25 no. 17).
 */
final class LogAuditKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarLogAudit $daftar, DaftarAnggota $anggota): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarLogAudit::KOLOM_URUT, '-DibuatPada', DaftarLogAudit::KOLOM_SARING);
        /** @param list<LogAudit> $log */
        $petakan = function (array $log) use ($anggota): array {
            $idPelaku = array_values(array_unique(array_filter(array_map(fn (LogAudit $l) => $l->IdPengguna, $log))));
            $namaPelaku = $anggota->AmbilNamaPengguna($this->IdTenant(), $idPelaku);

            return array_values(array_map(fn (LogAudit $l): array => [
                'Id' => $l->Id,
                'Peristiwa' => $l->Peristiwa,
                'Kalimat' => KamusPeristiwaAudit::Label($l->Peristiwa),
                // Petugas Payoung lewat akses dukungan dan perangkat kasir ditulis apa adanya, bukan "Sistem"/"Pengguna lain".
                'Pelaku' => match (true) {
                    $l->IdPenggunaPengelola !== null => 'Tim dukungan Payoung',
                    $l->IdPengguna !== null => $namaPelaku[$l->IdPengguna] ?? 'Pengguna lain',
                    $l->IdPerangkat !== null => 'Perangkat kasir',
                    default => 'Sistem',
                },
                'JenisObjek' => $l->JenisObjek,
                'IdObjek' => $l->IdObjek,
                'Objek' => KamusPeristiwaAudit::LabelObjek($l->JenisObjek, $l->IdObjek),
                'Perubahan' => PenerjemahPerubahanAudit::Terjemahkan($l->NilaiLama, $l->NilaiBaru),
                'NilaiLama' => $l->NilaiLama,
                'NilaiBaru' => $l->NilaiBaru,
                'Ip' => $l->Ip,
                'DibuatPada' => $l->DibuatPada->toIso8601String(),
            ], $log));
        };

        return ResponsTabel::Kirim($permintaan, 'Kelola/LogAudit/Daftar', 'Log', fn (): array => $daftar->AmbilTabel($tabel, $petakan));
    }
}
