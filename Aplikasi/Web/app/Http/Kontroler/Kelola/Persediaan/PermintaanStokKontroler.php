<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Persediaan\Aksi\AjukanPermintaanStok;
use App\Domain\Persediaan\Aksi\BatalkanPermintaanStok;
use App\Domain\Persediaan\Aksi\TolakPermintaanStok;
use App\Domain\Persediaan\Aksi\TutupPermintaanStok;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Kueri\DaftarPermintaanStok;
use App\Domain\Persediaan\Kueri\DetailPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Http\Permintaan\Kelola\Persediaan\AjukanPermintaanStokPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\AlasanDokumenPersediaanPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi permintaan stok F-05b (D-105, routes/PersediaanDokumen.php): outlet yang butuh barang mengajukan
 * permintaan ke lokasi pengirim (mis. gudang utama); pengirim menjawabnya dengan transfer biasa (tautan "Penuhi" ke
 * form transfer) atau menolaknya. Permintaan terlihat bila outlet asal atau tujuannya boleh diakses (selain itu 404).
 * Mengajukan/membatalkan butuh akses outlet tujuan (yang meminta), menolak butuh akses outlet asal (yang diminta),
 * menutup sisa boleh salah satunya (403 bila tidak). Izin rute: lihat `persediaan.lihat`, lainnya `persediaan.kelola`.
 */
final class PermintaanStokKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.permintaan.detail';

    public function Daftar(Request $permintaan, DaftarPermintaanStok $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPermintaanStok::KOLOM_URUT, DaftarPermintaanStok::URUT_BAWAAN, DaftarPermintaanStok::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Permintaan/Daftar', 'Permintaan', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            'OpsiStatus' => array_map(fn (StatusPermintaanStok $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPermintaanStok::cases()),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Buat(): Response
    {
        $saya = $this->AmbilOpsiGudangDokumen();

        return Inertia::render('Kelola/Persediaan/Permintaan/Form', [
            'OpsiGudangTujuan' => $saya,
            'OpsiGudangAsal' => $this->AmbilOpsiGudangDokumen(semuaOutlet: true),
            'UuidGudangTujuanAwal' => count($saya) === 1 ? $saya[0]['Uuid'] : '',
            'HariIni' => $this->HariIni(),
            'BatasBaris' => (int) config('persediaan.Dokumen.MaksimalBaris', 500),
        ]);
    }

    public function Simpan(AjukanPermintaanStokPermintaan $permintaan, AjukanPermintaanStok $ajukan): RedirectResponse
    {
        $tujuan = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudangTujuan'));
        $asal = $this->CariGudangTenant((string) $permintaan->validated('UuidGudangAsal'));
        $p = $ajukan->Jalankan($permintaan->AmbilData($asal->id, $tujuan->id));

        return redirect()->route(self::ALAMAT, ['permintaanStok' => $p->Uuid])
            ->with('Kilat', "Permintaan {$p->Nomor} diajukan. Pengirim akan melihatnya di daftar permintaan dan mengirim barangnya lewat transfer.");
    }

    public function Detail(string $permintaanStok, DetailPermintaanStok $detail): Response
    {
        $p = $this->CariPermintaan($permintaanStok);
        $kelola = $this->AmbilIzinDokumen()['Kelola'];
        $asal = $this->CekBolehOutlet($p->IdOutletAsal);
        $tujuan = $this->CekBolehOutlet($p->IdOutletTujuan);

        return Inertia::render('Kelola/Persediaan/Permintaan/Detail', [
            ...$detail->Ambil($p),
            'Tindakan' => [
                'Penuhi' => $kelola && $asal && $p->Status->CekTerbuka(),
                'Tolak' => $kelola && $asal && $p->Status === StatusPermintaanStok::Diajukan,
                'Batalkan' => $kelola && $tujuan && $p->Status === StatusPermintaanStok::Diajukan,
                'Tutup' => $kelola && ($asal || $tujuan) && $p->Status === StatusPermintaanStok::DiprosesSebagian,
            ],
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Batalkan(AlasanDokumenPersediaanPermintaan $permintaan, string $permintaanStok, BatalkanPermintaanStok $batalkan): RedirectResponse
    {
        $p = $batalkan->Jalankan($this->CariPermintaan($permintaanStok, tujuan: true), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['permintaanStok' => $p->Uuid])->with('Kilat', "Permintaan {$p->Nomor} dibatalkan.");
    }

    public function Tolak(AlasanDokumenPersediaanPermintaan $permintaan, string $permintaanStok, TolakPermintaanStok $tolak): RedirectResponse
    {
        $p = $tolak->Jalankan($this->CariPermintaan($permintaanStok, asal: true), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['permintaanStok' => $p->Uuid])->with('Kilat', "Permintaan {$p->Nomor} ditolak. Peminta akan melihat alasannya.");
    }

    public function Tutup(AlasanDokumenPersediaanPermintaan $permintaan, string $permintaanStok, TutupPermintaanStok $tutup): RedirectResponse
    {
        $p = $this->CariPermintaan($permintaanStok);
        abort_unless($this->CekBolehOutlet($p->IdOutletAsal) || $this->CekBolehOutlet($p->IdOutletTujuan), 403, 'Hanya pengguna di outlet pengirim atau peminta yang bisa menutup permintaan ini.');
        $p = $tutup->Jalankan($p, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['permintaanStok' => $p->Uuid])->with('Kilat', "Permintaan {$p->Nomor} ditutup. Sisa barang tidak akan dikirim.");
    }

    private function CariPermintaan(string $uuid, bool $asal = false, bool $tujuan = false): PermintaanStok
    {
        $p = PermintaanStok::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless($this->CekBolehOutlet($p->IdOutletAsal) || $this->CekBolehOutlet($p->IdOutletTujuan), 404);
        abort_if($asal && ! $this->CekBolehOutlet($p->IdOutletAsal), 403, 'Hanya pengguna di outlet yang diminta mengirim yang bisa menolak permintaan ini.');
        abort_if($tujuan && ! $this->CekBolehOutlet($p->IdOutletTujuan), 403, 'Hanya pengguna di outlet yang meminta yang bisa membatalkan permintaan ini.');

        return $p;
    }
}
