<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Dukungan\Aksi\BeriAksesDukungan;
use App\Domain\Dukungan\Aksi\CabutAksesDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Kueri\RiwayatAksesDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Http\Permintaan\Kelola\BeriAksesDukunganPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan › Akses dukungan (PGL-16, P-09; `/kelola/pengaturan/akses-dukungan`, izin `dukungan.akses.kelola` khusus
 * Owner): Owner memberi tim Payoung izin masuk berbatas waktu, mencabutnya kapan saja, dan melihat jejak setiap sesi.
 */
final class AksesDukunganKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(RiwayatAksesDukungan $riwayat): Response
    {
        return Inertia::render('Kelola/Pengaturan/AksesDukungan', [
            'Riwayat' => $riwayat->Ambil(20),
            'PilihanTiket' => array_values(TiketDukungan::query()
                ->whereIn('Status', array_map(fn (StatusTiketDukungan $status): string => $status->value, StatusTiketDukungan::AmbilTerbuka()))
                ->orderByDesc('Id')
                ->limit(50)
                ->get(['Uuid', 'Nomor', 'Judul'])
                ->map(fn (TiketDukungan $tiket): array => ['Nilai' => $tiket->Uuid, 'Label' => "{$tiket->Nomor} | {$tiket->Judul}"])
                ->all()),
            'Aturan' => ['DurasiJam' => BeriAksesDukungan::DURASI_JAM],
        ]);
    }

    public function Beri(BeriAksesDukunganPermintaan $isian, BeriAksesDukungan $beri): RedirectResponse
    {
        $pelaku = $this->Pelaku();
        $uuidTiket = $isian->AmbilUuidTiket();
        $idTiket = null;

        if ($uuidTiket !== null) {
            // Tiket dicari lewat scope tenant: tiket tenant lain atau yang sudah ditutup dianggap tidak ada.
            $idTiket = TiketDukungan::query()->where('Uuid', $uuidTiket)->firstOrFail()->Id;
        }

        $akses = $beri->Jalankan(
            $pelaku->Id,
            $pelaku->Nama,
            $isian->AmbilDurasiJam(),
            $isian->AmbilCakupan(),
            $isian->AmbilDataPribadiTerbuka(),
            $idTiket,
            $isian->AmbilCatatan(),
        );

        return back()->with('Kilat', 'Izin akses dukungan diberikan sampai '.$akses->BerakhirPada->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H.i').' WIB. Anda bisa mencabutnya kapan saja.');
    }

    public function Cabut(string $uuidAkses, CabutAksesDukungan $cabut): RedirectResponse
    {
        $akses = AksesDukungan::query()->where('Uuid', $uuidAkses)->firstOrFail();
        $cabut->Jalankan($akses, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Izin akses dukungan dicabut. Sesi petugas yang berjalan berakhir seketika.');
    }

    public function Aktivitas(string $uuidSesi, RiwayatAksesDukungan $riwayat): JsonResponse
    {
        $aktivitas = $riwayat->AmbilAktivitas($uuidSesi);
        abort_if($aktivitas === null, 404);

        return response()->json(['Data' => $aktivitas]);
    }
}
