<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Dukungan;

use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\AturKehadiranObrolan;
use App\Domain\Pengelola\Dukungan\Aksi\BalasTiketDukunganPengelola;
use App\Domain\Pengelola\Dukungan\Aksi\TugaskanTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\UbahStatusTiketDukungan;
use App\Domain\Pengelola\Dukungan\Kueri\AntreanObrolan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inbox obrolan langsung petugas dukungan di Platform Pengelola (PLT-12, D-163). Obrolan adalah tiket berkanal `Obrolan`,
 * jadi balasan, penugasan, dan penyelesaian memakai aksi tiket yang sama (audit, SLA, status). Halaman memanggil endpoint
 * JSON di sini berkala (polling) selagi inbox terbuka.
 */
final class ObrolanDukunganKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Tampilkan(AntreanObrolan $antrean): Response
    {
        return Inertia::render('Pengelola/Dukungan/Obrolan', [
            'Awal' => $antrean->Ambil($this->AmbilPelaku()),
            'BolehTangani' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::DukunganTiketTangani),
            'SegarkanDetik' => (int) config('dukungan.Obrolan.SegarkanDetik'),
            'MaksPanjangPesan' => (int) config('dukungan.Obrolan.MaksPanjangPesan'),
        ]);
    }

    public function Daftar(AntreanObrolan $antrean): JsonResponse
    {
        return response()->json($antrean->Ambil($this->AmbilPelaku()));
    }

    public function Pesan(string $tiketDukungan, Request $permintaan, AntreanObrolan $antrean): JsonResponse
    {
        $setelah = $permintaan->query('setelah');

        return response()->json($antrean->AmbilPesan($tiketDukungan, is_string($setelah) ? $setelah : null));
    }

    public function Kirim(string $tiketDukungan, Request $permintaan, BalasTiketDukunganPengelola $balas, AntreanObrolan $antrean): JsonResponse
    {
        $isi = str_replace("\r\n", "\n", trim((string) $permintaan->validate([
            'Isi' => ['required', 'string', 'min:1', 'max:'.(int) config('dukungan.Obrolan.MaksPanjangPesan')],
        ])['Isi']));
        $balas->Jalankan($this->AmbilPelaku(), $tiketDukungan, $isi, false);

        return response()->json($antrean->AmbilPesan($tiketDukungan, null), 201);
    }

    public function Ambil(string $tiketDukungan, TugaskanTiketDukungan $tugaskan, AntreanObrolan $antrean): JsonResponse
    {
        $pelaku = $this->AmbilPelaku();
        $tugaskan->Jalankan($pelaku, $tiketDukungan, $pelaku);

        return response()->json($antrean->AmbilPesan($tiketDukungan, null));
    }

    public function Selesaikan(string $tiketDukungan, UbahStatusTiketDukungan $ubah, AntreanObrolan $antrean): JsonResponse
    {
        $ubah->Jalankan($this->AmbilPelaku(), $tiketDukungan, StatusTiketDukungan::Selesai, 'Obrolan diselesaikan petugas.');

        return response()->json($antrean->AmbilPesan($tiketDukungan, null));
    }

    public function Kehadiran(Request $permintaan, AturKehadiranObrolan $atur): JsonResponse
    {
        $pelaku = $this->AmbilPelaku();
        $nilai = $permintaan->validate(['Siap' => ['nullable', 'boolean']]);

        return response()->json(array_key_exists('Siap', $nilai) && $nilai['Siap'] !== null
            ? $atur->Jalankan($pelaku, (bool) $nilai['Siap'])
            : $atur->Detak($pelaku));
    }
}
