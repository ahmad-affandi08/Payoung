<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Dukungan\Aksi\KirimPesanObrolan;
use App\Domain\Dukungan\Aksi\SelesaikanTiketDukungan;
use App\Domain\Dukungan\Kueri\ObrolanTenant;
use App\Domain\Dukungan\Layanan\StatusLayananObrolan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Kelola\KirimPesanObrolanPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Obrolan langsung dengan tim dukungan dari lembar Bantuan (PLT-12, D-163). Obrolan adalah tiket berkanal `Obrolan`:
 * halaman memanggil endpoint JSON ini berkala (polling) selagi lembar terbuka. Semua data lewat MilikTenant dan hanya
 * obrolan milik pengguna yang sedang masuk. Melihat memakai izin lihat tiket, mengirim/mengakhiri izin kelola tiket.
 */
final class ObrolanKontroler extends Kontroler
{
    public function Keadaan(Request $permintaan, ObrolanTenant $kueri, StatusLayananObrolan $layanan): JsonResponse
    {
        $pengguna = $this->AmbilPengguna();

        return response()->json([
            'Layanan' => $layanan->Ambil(),
            'Obrolan' => $kueri->AmbilKeadaan($kueri->CariAktif($pengguna->Id), $permintaan->query('setelah') === null ? null : (string) $permintaan->query('setelah')),
        ]);
    }

    public function Kirim(KirimPesanObrolanPermintaan $permintaan, KirimPesanObrolan $kirim, ObrolanTenant $kueri, StatusLayananObrolan $layanan): JsonResponse
    {
        $pengguna = $this->AmbilPengguna();
        $hasil = $kirim->Jalankan($pengguna->Id, $pengguna->Nama, $permintaan->AmbilIsi(), $permintaan->AmbilKonteks());

        return response()->json([
            'Layanan' => $layanan->Ambil(),
            'Obrolan' => $kueri->AmbilKeadaan($hasil['Tiket'], null),
            'Baru' => $hasil['Baru'],
        ], $hasil['Baru'] ? 201 : 200);
    }

    public function Akhiri(ObrolanTenant $kueri, SelesaikanTiketDukungan $selesaikan, StatusLayananObrolan $layanan): JsonResponse
    {
        $pengguna = $this->AmbilPengguna();
        $aktif = $kueri->CariAktif($pengguna->Id);
        abort_if($aktif === null, 404);
        $selesaikan->Jalankan($pengguna->Nama, $aktif);

        return response()->json(['Layanan' => $layanan->Ambil(), 'Obrolan' => null]);
    }

    private function AmbilPengguna(): Pengguna
    {
        $pengguna = Auth::guard('web')->user();
        abort_unless($pengguna instanceof Pengguna, 403);

        return $pengguna;
    }
}
