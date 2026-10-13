<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Dukungan;

use App\Domain\Pengelola\Dukungan\Aksi\BangunUlangRingkasanHarianDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\BangunUlangSaldoStokDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\CabutPerangkatDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\KirimUlangPesanWhatsappDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\KirimUlangVerifikasiEmailDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\LihatDaftarKirimUlangDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\LihatLogPerangkatDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\LihatStatusPerangkatDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\MintaLogPerangkatDukungan;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Dukungan\BangunUlangRingkasanHarianPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Alat bantu dukungan di konsol (PGL-16 bagian 2, P-09, BR-P09.3): baca status perangkat, log, dan daftar kirim ulang
 * (JSON untuk panel); ubah lewat POST yang kembali ke halaman tenant dengan hasil di dialog. Semua jalan lewat
 * `PelaksanaAlatDukungan` (izin akses yang berlaku, cakupan, audit, sebelum/sesudah).
 */
final class AlatDukunganKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Perangkat(Tenant $tenant, LihatStatusPerangkatDukungan $lihat): JsonResponse
    {
        return $this->Data($lihat->Jalankan($this->AmbilPelaku(), $tenant->Id));
    }

    public function DaftarKirimUlang(Tenant $tenant, LihatDaftarKirimUlangDukungan $lihat): JsonResponse
    {
        return $this->Data($lihat->Jalankan($this->AmbilPelaku(), $tenant->Id));
    }

    public function LogPerangkat(Tenant $tenant, string $perangkat, LihatLogPerangkatDukungan $lihat): JsonResponse
    {
        return $this->Data($lihat->Jalankan($this->AmbilPelaku(), $tenant->Id, $perangkat));
    }

    public function CabutPerangkat(Tenant $tenant, string $perangkat, CabutPerangkatDukungan $cabut): RedirectResponse
    {
        return $this->Kembali($cabut->Jalankan($this->AmbilPelaku(), $tenant->Id, $perangkat));
    }

    public function MintaLog(Tenant $tenant, string $perangkat, MintaLogPerangkatDukungan $minta): RedirectResponse
    {
        return $this->Kembali($minta->Jalankan($this->AmbilPelaku(), $tenant->Id, $perangkat));
    }

    public function BangunUlangSaldoStok(Tenant $tenant, BangunUlangSaldoStokDukungan $bangun): RedirectResponse
    {
        return $this->Kembali($bangun->Jalankan($this->AmbilPelaku(), $tenant->Id));
    }

    public function BangunUlangRingkasanHarian(Tenant $tenant, BangunUlangRingkasanHarianPermintaan $isian, BangunUlangRingkasanHarianDukungan $bangun): RedirectResponse
    {
        return $this->Kembali($bangun->Jalankan($this->AmbilPelaku(), $tenant->Id, $isian->AmbilDari(), $isian->AmbilSampai()));
    }

    public function KirimUlangPesan(Tenant $tenant, string $pesan, KirimUlangPesanWhatsappDukungan $kirim): RedirectResponse
    {
        return $this->Kembali($kirim->Jalankan($this->AmbilPelaku(), $tenant->Id, $pesan));
    }

    public function KirimUlangVerifikasiEmail(Tenant $tenant, string $pengguna, KirimUlangVerifikasiEmailDukungan $kirim): RedirectResponse
    {
        return $this->Kembali($kirim->Jalankan($this->AmbilPelaku(), $tenant->Id, $pengguna));
    }

    private function Data(HasilKerjaAlat $hasil): JsonResponse
    {
        return response()->json($hasil->data);
    }

    private function Kembali(HasilKerjaAlat $hasil): RedirectResponse
    {
        return back()->with('Kilat', $hasil->ringkasan);
    }
}
