<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pengelola\Tenant\Aksi\SelesaikanTugasTenant;
use App\Domain\Pengelola\Tenant\Enum\JenisTugasTenant;
use App\Domain\Pengelola\Tenant\Kueri\DaftarTugasTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Tugas otomatis dari skor kesehatan tenant (P-07 PGL-09, `/tugas-tenant`): lihat `tenant.lihat`; tandai sudah ditangani
 * `tenant.catatan.tulis` (sama dengan menulis catatan internal tenant).
 */
final class KesehatanTenantKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(Request $permintaan, DaftarTugasTenant $kueri): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTugasTenant::KOLOM_URUT, '-DibuatPada', DaftarTugasTenant::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Tenant/Tugas', 'Tugas', fn (): array => $kueri->Ambil($tabel), fn (): array => [
            'Sebaran' => $kueri->AmbilSebaran(),
            'PilihanJenis' => array_map(fn (JenisTugasTenant $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel()], JenisTugasTenant::cases()),
        ]);
    }

    public function Selesaikan(TugasTenant $tugas, Request $permintaan, SelesaikanTugasTenant $selesaikan): RedirectResponse
    {
        $valid = $permintaan->validate(['Catatan' => ['required', 'string', 'max:500']], ['Catatan.*' => 'Tulis catatan: siapa yang menghubungi dan hasilnya.']);
        $selesaikan->Jalankan($this->AmbilPelaku(), $tugas, (string) $valid['Catatan']);

        return back()->with('Kilat', 'Tugas ditandai selesai.');
    }
}
