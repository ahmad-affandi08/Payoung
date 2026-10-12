<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Kasir\Aksi\TutupShiftPaksa;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Pos\V1\KasirTerbukaPermintaan;
use App\Http\Permintaan\Pos\V1\TutupPaksaShiftPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift perangkat yang masih terbuka di server tetapi tidak dikenal aplikasi (data lokal hilang, aplikasi dipasang
 * ulang, atau shift lama tidak pernah tertutup). Server menolak `Shift.Buka` baru dengan `ShiftSudahTerbuka` sampai shift
 * itu ditutup, dan semua penjualan/kas dari shift baru ikut tertahan.
 *
 * - `GET /api/pos/v1/shift/terbuka`: shift aktif milik perangkat ini di server (untuk ditampilkan sebelum ditutup).
 * - `GET /api/pos/v1/shift/kasir-terbuka?UuidPengguna=`: BR-06.1 per kasir. Shift (bukan bersama) milik kasir itu yang
 *   masih terbuka di perangkat LAIN pada outlet perangkat pemanggil. Dipakai aplikasi sebelum membuka shift (online) agar
 *   kasir tidak membuka shift ganda; offline aplikasi tetap membuka shift dan server menandainya `PerluTinjauan`.
 * - `POST /api/pos/v1/shift/{uuidShift}/tutup-paksa`: supervisor (PIN diperiksa di perangkat) menutup shift itu dari
 *   aplikasi; sama dengan "Tutup paksa shift" di back-office (`TutupShiftPaksa`): alasan wajib, ditandai perlu tinjauan,
 *   tercatat di audit. Penyetuju wajib anggota outlet ber-izin `shift.selisih.setujui`; hanya shift perangkat ini.
 */
final class ShiftKontroler extends Kontroler
{
    public function Terbuka(Request $permintaan, AnggotaOutlet $anggota): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $shift = Shift::query()
            ->where('IdPerangkat', $perangkat->Id)
            ->whereIn('Status', ['Terbuka', 'DibukaUlang'])
            ->orderBy('DibukaPada')
            ->get();
        $nama = $anggota->AmbilNama(array_values(array_map(fn (Shift $s): int => $s->DibukaOleh, $shift->all())));

        return response()->json(['Shift' => $shift->map(fn (Shift $s): array => [
            'Uuid' => $s->Uuid,
            'Status' => $s->Status->value,
            'DibukaPada' => $s->DibukaPada->utc()->toIso8601ZuluString(),
            'NamaKasir' => $nama[$s->DibukaOleh]['Nama'] ?? '',
            'KasAwal' => $s->KasAwal,
        ])->values()->all()]);
    }

    public function KasirTerbuka(KasirTerbukaPermintaan $permintaan, AnggotaOutlet $anggota): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $kasir = $anggota->Cari($perangkat->IdTenant, $permintaan->string('UuidPengguna')->toString(), $perangkat->IdOutlet);

        if ($kasir === null) {
            return response()->json(['Shift' => []]);
        }

        $shift = Shift::query()
            ->where('IdOutlet', $perangkat->IdOutlet)
            ->where('DibukaOleh', $kasir->id)
            ->where('Bersama', false)
            ->where('IdPerangkat', '!=', $perangkat->Id)
            ->whereIn('Status', ['Terbuka', 'DibukaUlang'])
            ->orderBy('DibukaPada')
            ->get();
        $namaPerangkat = Perangkat::query()->whereIn('Id', $shift->pluck('IdPerangkat')->all())->pluck('Nama', 'Id');

        return response()->json(['Shift' => $shift->map(fn (Shift $s): array => [
            'Uuid' => $s->Uuid,
            'DibukaPada' => $s->DibukaPada->utc()->toIso8601ZuluString(),
            'NamaPerangkat' => (string) ($namaPerangkat[$s->IdPerangkat] ?? ''),
        ])->values()->all()]);
    }

    public function TutupPaksa(TutupPaksaShiftPermintaan $permintaan, string $uuidShift, AnggotaOutlet $anggota, TutupShiftPaksa $tutup): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $penyetuju = $anggota->Cari($perangkat->IdTenant, $permintaan->string('UuidPenyetuju')->toString(), $perangkat->IdOutlet);

        if ($penyetuju === null || ! $penyetuju->CekIzin(IzinTenant::ShiftSelisihSetujui->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Penyetuju tidak punya izin menutup paksa shift di outlet ini.', 'UuidPenyetuju', 403);
        }

        $milikPerangkat = Shift::query()->where('Uuid', $uuidShift)->where('IdPerangkat', $perangkat->Id)->exists();

        if (! $milikPerangkat) {
            throw new PelanggaranAturanBisnis('ShiftTidakDikenal', 'Shift ini bukan milik perangkat ini.', 'UuidShift', 404);
        }

        $alasan = trim($permintaan->string('Alasan')->toString()).' (diminta dari perangkat kasir, disetujui '.$penyetuju->nama.')';
        $hasil = $tutup->Jalankan($uuidShift, $penyetuju->id, $alasan, null, [$perangkat->IdOutlet]);

        return response()->json(['Uuid' => $hasil->Uuid, 'Status' => $hasil->Status->value]);
    }
}
