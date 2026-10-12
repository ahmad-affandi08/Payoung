<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Data\DataSesiDukunganAktif;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Layanan\KonteksAksesDukungan;
use App\Domain\Dukungan\Layanan\PencatatAktivitasDukungan;
use App\Domain\Dukungan\Layanan\PenjagaSesiAksesDukungan;
use App\Domain\Organisasi\Model\Pengguna;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi akses dukungan pada setiap permintaan back-office (PGL-16, P-09). Dipasang pada grup `auth:web` SEBELUM
 * perantara tenant lain. Tanpa penanda sesi di session, permintaan lewat tanpa diubah (pengguna biasa). Dengan penanda:
 *
 * 1. sesi, izin induk, petugas, dan identitas diperiksa ulang (`PenjagaSesiAksesDukungan`) — mencabut izin berlaku
 *    seketika; sesi yang tidak sah dikeluarkan ke halaman "Sesi berakhir";
 * 2. konteks akses dukungan, tenant aktif, dan penanda pelaku pengelola pada audit tenant dipasang;
 * 3. hanya jalur back-office yang terbuka; halaman kata sandi/PIN/kredensial ditolak (BR-P09.1); cakupan Baca saja
 *    menolak semua metode yang mengubah (BR-P09.2) dengan pesan "Izin akses hanya baca", tercatat di LogAudit tenant dan
 *    LogAuditPengelola;
 * 4. tanpa izin data pribadi dari Owner, data pribadi pelanggan pada respons disamarkan, dan halaman cetak serta unduhan
 *    ditolak (`PenyamarResponsDukungan`);
 * 5. setelah respons dikirim, jejak permintaan ditulis ke `AktivitasAksesDukungan` dan, untuk permintaan yang mengubah
 *    data atau ditolak, ke LogAuditPengelola.
 *
 * Perantara kewajiban akun (ganti kata sandi, persetujuan legal, 2FA, panduan awal, penangguhan) tidak berlaku pada
 * identitas teknis petugas; mereka memeriksa atribut permintaan `ATRIBUT`.
 */
final class PasangAksesDukungan
{
    public const KUNCI_SESI = 'Dukungan.IdSesi';

    public const ATRIBUT = 'akses_dukungan';

    private const ATRIBUT_DITOLAK = 'akses_dukungan_ditolak';

    public function __construct(
        private readonly KonteksTenant $konteksTenant,
        private readonly KonteksAksesDukungan $konteks,
        private readonly PenjagaSesiAksesDukungan $penjaga,
        private readonly PencatatAudit $audit,
        private readonly PencatatAktivitasDukungan $aktivitas,
        private readonly GerbangPengelola $gerbang,
        private readonly PenyamarResponsDukungan $penyamar,
    ) {}

    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        $idSesi = $permintaan->session()->get(self::KUNCI_SESI);
        $pengguna = Auth::guard('web')->user();
        $idTenant = $permintaan->session()->get(IdentifikasiTenantSesi::KUNCI_SESI);

        if (! is_int($idSesi)) {
            return $berikutnya($permintaan);
        }

        if (! $pengguna instanceof Pengguna || ! is_int($idTenant)) {
            return $this->Keluarkan($permintaan, AlasanAkhirSesiDukungan::Selesai);
        }

        $this->konteksTenant->Atur($idTenant);
        [$sesi, $alasan] = $this->penjaga->Periksa($idSesi, $pengguna->Id);

        if (! $sesi instanceof DataSesiDukunganAktif) {
            return $this->Keluarkan($permintaan, $alasan ?? AlasanAkhirSesiDukungan::Selesai);
        }

        $this->konteks->Atur($sesi);
        $this->audit->AturPelakuPengelola($sesi->idPenggunaPengelola);
        $permintaan->attributes->set(self::ATRIBUT, $sesi);

        $respons = $this->Teruskan($permintaan, $berikutnya, $sesi);

        // Jejak ditulis setelah respons terkirim, jadi tidak memperlambat halaman.
        app()->terminating(fn () => $this->CatatJejak($permintaan, $respons, $sesi));

        return $respons;
    }

    private function Teruskan(Request $permintaan, Closure $berikutnya, DataSesiDukunganAktif $sesi): Response
    {
        // "Keluar" biasa tidak menutup catatan sesi; petugas diarahkan ke tombol akhiri sesi (307 menjaga metode POST).
        if ($permintaan->routeIs('keluar')) {
            return redirect()->route('dukungan.akhiri', [], 307);
        }

        if (! AturanAksesDukungan::CekJalurDiizinkan($permintaan)) {
            return $this->Tolak($permintaan, $sesi, 'JalurTidakDiizinkan', AturanAksesDukungan::PESAN_DILARANG);
        }

        if (AturanAksesDukungan::CekRuteDilarang($permintaan)) {
            return $this->Tolak($permintaan, $sesi, 'HalamanDilarang', AturanAksesDukungan::PESAN_DILARANG);
        }

        if ($sesi->cakupan === CakupanAksesDukungan::BacaSaja && ! AturanAksesDukungan::CekMetodeBaca($permintaan) && ! $permintaan->routeIs('dukungan.akhiri')) {
            return $this->Tolak($permintaan, $sesi, 'HanyaBaca', AturanAksesDukungan::PESAN_HANYA_BACA);
        }

        $respons = $berikutnya($permintaan);

        // BR-P09.1: data pribadi pelanggan tersamar kecuali Owner membukanya; cetak dan unduhan ikut tertutup.
        if (! $sesi->dataPribadiTerbuka) {
            $aman = $this->penyamar->Terapkan($permintaan, $respons);

            return $aman ?? $this->Tolak($permintaan, $sesi, 'CetakUnduhanDitolak', PenyamarResponsDukungan::PESAN_DITOLAK);
        }

        return $respons;
    }

    private function CatatJejak(Request $permintaan, Response $respons, DataSesiDukunganAktif $sesi): void
    {
        $ditolak = $permintaan->attributes->get(self::ATRIBUT_DITOLAK);
        $mengubah = ! AturanAksesDukungan::CekMetodeBaca($permintaan);
        // Polling JSON (misal data tabel, notifikasi) tidak dicatat satu per satu; halaman yang dibuka, unduhan,
        // dan semua yang mengubah atau ditolak dicatat.
        $pollingJson = AturanAksesDukungan::CekMetodeBaca($permintaan) && $permintaan->expectsJson() && ! $permintaan->header('X-Inertia');

        if ($pollingJson && $ditolak === null) {
            return;
        }

        $alasan = is_string($ditolak) ? $ditolak : null;
        $namaRute = $permintaan->route()?->getName();
        $this->aktivitas->Catat($sesi, $permintaan->method(), $namaRute, '/'.ltrim($permintaan->path(), '/'), $respons->getStatusCode(), $alasan);

        if ($mengubah || $alasan !== null) {
            $this->gerbang->CatatJejak($alasan === null ? 'dukungan.aksi' : 'dukungan.aksi.ditolak', $sesi->idPenggunaPengelola, $sesi->idTenant, [
                'Metode' => $permintaan->method(),
                'Rute' => $namaRute,
                'Status' => $respons->getStatusCode(),
                'Alasan' => $alasan,
            ], ip: $permintaan->ip());
        }
    }

    private function Tolak(Request $permintaan, DataSesiDukunganAktif $sesi, string $kode, string $pesan): Response
    {
        $permintaan->attributes->set(self::ATRIBUT_DITOLAK, $kode.': '.$pesan);
        $this->audit->Catat('dukungan.aksi.ditolak', nilaiBaru: [
            'Kode' => $kode,
            'Metode' => $permintaan->method(),
            'Rute' => $permintaan->route()?->getName(),
        ], idPengguna: $sesi->idPengguna, idPenggunaPengelola: $sesi->idPenggunaPengelola);

        if ($permintaan->expectsJson() && ! $permintaan->header('X-Inertia')) {
            return response()->json(['Galat' => ['Kode' => $kode, 'Pesan' => $pesan]], 403);
        }

        // Permintaan ubah (form) kembali ke halaman asal dengan pesan; halaman terlarang dialihkan ke beranda.
        $tujuan = AturanAksesDukungan::CekMetodeBaca($permintaan) ? redirect()->route('kelola.beranda') : back(fallback: route('kelola.beranda'));

        return $tujuan->withErrors(['Umum' => $pesan]);
    }

    private function Keluarkan(Request $permintaan, AlasanAkhirSesiDukungan $alasan): Response
    {
        Auth::guard('web')->logout();
        $permintaan->session()->invalidate();
        $permintaan->session()->regenerateToken();

        return redirect()->route('dukungan.berakhir', ['alasan' => $alasan->value]);
    }
}
