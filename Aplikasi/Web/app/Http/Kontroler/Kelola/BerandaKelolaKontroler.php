<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use App\Domain\Karyawan\Kueri\AbsenSaya;
use App\Domain\Laporan\Kueri\DasborPemilik;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Kueri\SektorOutletTenant;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Kueri\TurProdukPengguna;
use App\Domain\Penjualan\Kueri\RingkasanInvoice;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beranda back-office. F-01 menambahkan checklist "Langkah Berikutnya" (kosong = bagian disembunyikan). F-14a:
 * dasbor pemilik (`Dasbor`, rentang lewat query `dari` & `sampai`, bawaan hari ini) hanya untuk pemegang izin `laporan.penjualan.lihat`, dibatasi outlet akses; pengguna tanpa
 * izin melihat beranda tanpa angka (`Dasbor` = null). Wizard panduan awal ada di `PanduanAwalKontroler`. D-23 C:
 * ringkasan Kotak Tindakan (`Tindakan`).
 */
final class BerandaKelolaKontroler extends DasarKelolaKontroler
{
    public function Beranda(Request $permintaan, AksesPengguna $akses, DasborPemilik $dasbor, TanggalBisnisOutlet $tanggal, KotakTindakan $kotak, KonteksTindakanPengguna $konteks, TurProdukPengguna $tur, RingkasanInvoice $penagihan, SektorOutletTenant $sektor, AbsenSaya $absen): Response|RedirectResponse
    {
        $idTenant = $this->IdTenant();
        $idPengguna = $this->Pelaku()->Id;
        $hakAkses = $akses->Ambil($idTenant, $idPengguna);

        // D-180: staf yang akunnya hanya untuk absen (tanpa izin back-office apa pun) langsung ke halaman Absen.
        if ($hakAkses !== null && ! $hakAkses['Pemilik'] && $hakAkses['Izin'] === [] && $absen->CekTersedia($idPengguna)) {
            return redirect()->route('kelola.absen');
        }

        return Inertia::render('Kelola/Beranda', [
            // PLT-12: tur yang sudah dilihat pengguna ini (Beranda menawarkan tur keliling dashboard sekali).
            'TurDilihat' => $tur->KodeSudahDilihat($idPengguna),
            // D-23 C: ringkasan Kotak Tindakan (D-24: termasuk checklist persiapan toko yang dulu di Beranda) (tanpa rincian; rincian & tandai di /kelola/tindakan).
            // Audit kinerja skala besar: Kotak Tindakan menjalankan puluhan COUNT, dan Beranda dibuka paling sering. Ringkasan
            // disimpan 60 detik per tenant & pengguna (izin dan cakupan outlet berbeda per pengguna); halaman
            // `/kelola/tindakan` tetap membaca langsung supaya "tandai sudah dicek" terlihat seketika.
            'Tindakan' => Cache::remember(
                "tindakan:beranda:{$idTenant}:{$idPengguna}",
                60,
                fn (): array => array_map(
                    fn (DataButirTindakan $b): array => [...$b->KeLarik(false), 'Rincian' => []],
                    $kotak->Ambil($konteks->Buat($idTenant, $idPengguna, $tanggal->Hitung(null))),
                ),
            ),
            // F-21: usaha yang menagih lewat invoice melihat ringkasan penagihannya di Beranda (null bila belum pernah ada invoice).
            'Penagihan' => $akses->CekIzin($idTenant, $idPengguna, IzinTenant::InvoiceKelola)
                ? $penagihan->Ambil($tanggal->Hitung(null), $this->IdOutletBoleh())
                : null,
            // D-167: usaha Perusahaan tidak punya penjualan kasir; Beranda-nya menampilkan penagihan, bukan omzet kasir kosong.
            'Dasbor' => $akses->CekIzin($idTenant, $idPengguna, IzinTenant::LaporanPenjualanLihat) && ! $sektor->CekHanyaPerusahaan()
                ? $dasbor->Ambil(
                    $tanggal->Hitung(null),
                    $this->IdOutletBoleh(),
                    $akses->CekIzin($idTenant, $idPengguna, IzinTenant::PersediaanLihat),
                    $this->UraiTanggal($permintaan->query('dari')),
                    $this->UraiTanggal($permintaan->query('sampai')),
                )
                : null,
        ]);
    }

    /** Tanggal `TTTT-BB-HH` dari query; apa pun yang bukan tanggal sah dianggap tidak diisi (bawaan: hari ini). */
    private function UraiTanggal(mixed $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) !== 1) {
            return null;
        }

        try {
            $tanggal = CarbonImmutable::parse($nilai)->startOfDay();
        } catch (InvalidFormatException) {
            return null; // tanggal mustahil, misal 2026-13-45
        }

        // Tanggal yang "meluber" (2026-02-31) tidak sama dengan masukan.
        return $tanggal->toDateString() === $nilai ? $tanggal : null;
    }
}
