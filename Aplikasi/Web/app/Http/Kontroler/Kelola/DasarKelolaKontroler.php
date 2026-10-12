<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Laporan\PembuatDefinisiLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Unduhan\Layanan\BatasEksporLaporan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kontroler;
use App\Http\Respons\PenyajiLaporan;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Bantuan bersama kontroler back-office F-02: pelaku yang masuk, tenant aktif, dan pencarian data lewat ID publik
 * (ULID) di dalam scope tenant. Data tenant lain atau outlet di luar akses pelaku diperlakukan sebagai tidak ada (404).
 */
abstract class DasarKelolaKontroler extends Kontroler
{
    protected function Pelaku(): Pengguna
    {
        $pengguna = Auth::guard('web')->user();
        abort_unless($pengguna instanceof Pengguna, 403);

        return $pengguna;
    }

    protected function IdTenant(): int
    {
        return app(KonteksTenant::class)->Wajib();
    }

    /**
     * Outlet yang boleh diakses pelaku. Null = semua outlet.
     *
     * @return list<int>|null
     */
    protected function IdOutletBoleh(): ?array
    {
        return app(AksesPengguna::class)->AmbilIdOutlet($this->IdTenant(), $this->Pelaku()->Id);
    }

    protected function CariOutlet(string $uuid): Outlet
    {
        $outlet = Outlet::query()->where('Uuid', $uuid)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($outlet->Id, $boleh, true), 404);

        return $outlet;
    }

    /**
     * Audit kemudahan pakai #13 ("Simpan & posting"): setelah draf tersimpan, bila formulir mengirim `Lanjutkan`,
     * langkah berikutnya (posting/kirim/ajukan) langsung dijalankan lewat aksi kontroler yang sama dengan tombol di
     * halaman detail. Galat bisnis langkah itu tidak membatalkan draf: pengguna dibawa ke detail draf dengan pesannya.
     *
     * @param  callable(): RedirectResponse  $lanjut
     * @param  array<string, string>  $parameter
     */
    protected function LanjutkanSetelahSimpan(Request $permintaan, RedirectResponse $hasilSimpan, callable $lanjut, string $ruteDetail, array $parameter): RedirectResponse
    {
        if (! $permintaan->boolean('Lanjutkan')) {
            return $hasilSimpan;
        }

        try {
            return $lanjut();
        } catch (PelanggaranAturanBisnis $galat) {
            return redirect()->route($ruteDetail, $parameter)
                ->with('Kilat', 'Draf disimpan.')
                ->withErrors(['Umum' => 'Belum diproses: '.$galat->getMessage()]);
        }
    }

    /**
     * Menyajikan laporan sebagai Excel, CSV, atau halaman cetak sesuai `?format=` dengan kop yang sama di semua laporan
     * (D-43): judul, nama usaha & logo, cakupan outlet, saringan, ringkasan, tabel, dan jejak waktu.
     *
     * @param  list<KolomLaporan>  $kolom
     * @param  iterable<list<string|int|DateTimeInterface|null>>|Closure(): iterable<list<string|int|DateTimeInterface|null>>  $baris
     * @param  list<array{0: string, 1: string}>  $saringan
     * @param  list<ItemRingkasan>|null  $ringkasan  null = jumlah kolom `jumlahkan` dan jumlah baris
     */
    protected function SajikanLaporan(
        Request $permintaan,
        string $judul,
        string $namaBerkas,
        array $kolom,
        iterable|Closure $baris,
        array $saringan = [],
        ?array $ringkasan = null,
        string $cakupan = 'Semua Outlet',
        ?DateTimeInterface $dataTerakhir = null,
    ): SymfonyResponse {
        // Generator hanya bisa dibaca sekali, sedangkan laporan membaca baris lebih dari sekali (ringkasan, lalu tulis).
        $isi = $baris instanceof \Generator ? iterator_to_array($baris, false) : $baris;
        $sumber = $isi instanceof Closure ? $isi : static fn (): iterable => $isi;
        $definisi = app(PembuatDefinisiLaporan::class)->Buat(
            idTenant: $this->IdTenant(),
            judul: $judul,
            namaBerkas: $namaBerkas,
            cakupan: $cakupan,
            saringan: $saringan,
            ringkasan: $ringkasan,
            kolom: $kolom,
            baris: $sumber,
            dataTerakhir: $dataTerakhir,
        );

        return PenyajiLaporan::Sajikan($permintaan, $definisi);
    }

    /**
     * Semua baris tabel server sesuai saringan & cari yang sedang aktif, dibaca halaman demi halaman lewat kueri daftar
     * yang sama dengan layar (angka ekspor = angka di layar). Dibatasi `$maks` baris supaya ekspor tetap wajar; tanpa `$maks`
     * batasnya 5.000 baris, atau jauh lebih besar saat ekspor disiapkan di latar belakang (D-124 Pusat Unduhan).
     *
     * @param  Closure(DataPermintaanTabel): array{Data: list<array<string, mixed>>, Meta: array{JumlahHalaman: int}}  $ambil
     * @return list<array<string, mixed>>
     */
    protected function AmbilSemuaBarisTabel(DataPermintaanTabel $tabel, Closure $ambil, ?int $maks = null): array
    {
        $maks ??= app(BatasEksporLaporan::class)->Maks();
        $hasil = [];
        $ukuran = max(DataPermintaanTabel::UKURAN_HALAMAN);

        for ($halaman = 1; ; $halaman++) {
            $isi = $ambil(new DataPermintaanTabel($tabel->cari, $tabel->urut, $halaman, $ukuran, $tabel->saring));
            array_push($hasil, ...$isi['Data']);

            if ($halaman >= $isi['Meta']['JumlahHalaman'] || count($hasil) >= $maks) {
                return array_slice($hasil, 0, $maks);
            }
        }
    }

    /** "01 Oktober 2026 - 04 Oktober 2026" untuk blok saringan kop laporan. */
    protected function LabelPeriode(CarbonInterface $dari, CarbonInterface $sampai): string
    {
        $format = static fn (CarbonInterface $t): string => $t->copy()->locale('id')->translatedFormat('d F Y');

        return $format($dari).' - '.$format($sampai);
    }

    /** Cakupan outlet di kop laporan: nama outlet yang dipilih, atau "Semua Outlet" / "Outlet yang Anda kelola". */
    protected function LabelCakupan(string $namaOutletDipilih = ''): string
    {
        if ($namaOutletDipilih !== '') {
            return $namaOutletDipilih;
        }

        return $this->IdOutletBoleh() === null ? 'Semua Outlet' : 'Outlet yang Anda kelola';
    }
}
