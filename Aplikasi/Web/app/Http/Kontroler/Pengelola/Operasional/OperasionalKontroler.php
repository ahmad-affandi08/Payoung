<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Operasional;

use App\Domain\Pengelola\Operasional\Aksi\AturNomorWaAlertOperasional;
use App\Domain\Pengelola\Operasional\Aksi\BuangTugasGagal;
use App\Domain\Pengelola\Operasional\Aksi\CatatHasilBackup;
use App\Domain\Pengelola\Operasional\Aksi\CobaUlangTugasGagal;
use App\Domain\Pengelola\Operasional\Kueri\DasborOperasional;
use App\Domain\Pengelola\Operasional\Kueri\TugasGagal;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Operasional\AturNomorWaAlertPermintaan;
use App\Http\Permintaan\Pengelola\Operasional\CatatBackupPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dasbor operasional dasar (P-11, §19.3: Teknis & Super Admin).
 */
final class OperasionalKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Dasbor(DasborOperasional $dasbor): Response
    {
        return Inertia::render('Pengelola/Operasional/Dasbor', ['Dasbor' => $dasbor->Ambil()]);
    }

    public function TampilkanTugasGagal(string $idTugas, TugasGagal $tugasGagal): Response
    {
        $detail = $tugasGagal->AmbilDetail($idTugas);
        abort_if($detail === null, 404);

        return Inertia::render('Pengelola/Operasional/TugasGagal', ['Tugas' => $detail]);
    }

    public function CobaUlangTugasGagal(string $idTugas, CobaUlangTugasGagal $cobaUlang): RedirectResponse
    {
        $cobaUlang->Jalankan($this->AmbilPelaku(), $idTugas);

        return redirect()->route('pengelola.operasional.dasbor')->with('Kilat', 'Job dikembalikan ke antrean.');
    }

    public function BuangTugasGagal(string $idTugas, Request $permintaan, BuangTugasGagal $buang): RedirectResponse
    {
        $alasan = $permintaan->validate(['Alasan' => ['required', 'string', 'max:500']])['Alasan'];
        $buang->Jalankan($this->AmbilPelaku(), $idTugas, trim((string) $alasan));

        return redirect()->route('pengelola.operasional.dasbor')->with('Kilat', 'Job gagal dibuang.');
    }

    public function CatatBackup(CatatBackupPermintaan $permintaan, CatatHasilBackup $catat): RedirectResponse
    {
        $catatan = $catat->Jalankan($permintaan->AmbilData(), $this->AmbilPelaku());

        return back()->with('Kilat', "{$catatan->Jenis->AmbilLabel()} {$catatan->Hasil->value} tercatat.");
    }

    public function AturNomorWaAlert(AturNomorWaAlertPermintaan $permintaan, AturNomorWaAlertOperasional $atur): RedirectResponse
    {
        $nomor = $atur->Jalankan($this->AmbilPelaku(), $permintaan->AmbilNomor());

        return back()->with('Kilat', $nomor === [] ? 'Alert WhatsApp dimatikan. Alert tetap dikirim lewat email.' : count($nomor).' nomor penerima alert WhatsApp disimpan.');
    }
}
