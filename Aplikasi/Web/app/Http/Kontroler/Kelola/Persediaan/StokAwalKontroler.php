<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\KesiapanPeranAkun;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Aksi\AjukanPostingStokAwal;
use App\Domain\Persediaan\Aksi\BatalkanStokAwal;
use App\Domain\Persediaan\Aksi\BuangStokAwal;
use App\Domain\Persediaan\Aksi\SimpanStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Kebijakan\StokAwalKebijakan;
use App\Domain\Persediaan\Kueri\DaftarStokAwal;
use App\Domain\Persediaan\Kueri\DetailStokAwal;
use App\Domain\Persediaan\Model\StokAwal;
use App\Http\Permintaan\Kelola\Persediaan\BatalkanStokAwalPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanStokAwalPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi dokumen stok awal F-05a (DesainF05a D, routes/Persediaan.php): daftar, form buat/ubah draf,
 * detail, buang draf, posting (langsung atau antrean), pembatalan, dan status posting (JSON, dipantau selama
 * Memproses). Dokumen dicari lewat Uuid di dalam scope tenant; dokumen tenant lain atau lokasi stok di luar outlet
 * pelaku = 404. Izin rute dijaga `WajibIzinTenant`; prop `Tindakan` hanya untuk tampilan.
 */
final class StokAwalKontroler extends DasarPersediaanKontroler
{
    public function Daftar(Request $permintaan, DaftarStokAwal $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarStokAwal::KOLOM_URUT, DaftarStokAwal::URUT_BAWAAN, DaftarStokAwal::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/StokAwal/Daftar', 'StokAwal', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudang(false),
            'OpsiStatus' => array_map(fn (StatusStokAwal $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusStokAwal::cases()),
            'Izin' => $this->AmbilIzinPersediaan(),
            'KesiapanAkun' => $this->AmbilKesiapanAkun([PeranAkun::PersediaanBarangDagang, PeranAkun::PersediaanBahanBaku, PeranAkun::EkuitasSaldoAwal]),
        ]);
    }

    public function Buat(): Response
    {
        return $this->RenderForm('Buat', null, [PeranAkun::PersediaanBarangDagang, PeranAkun::PersediaanBahanBaku, PeranAkun::EkuitasSaldoAwal]);
    }

    public function Simpan(SimpanStokAwalPermintaan $permintaan, SimpanStokAwal $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $stokAwal = $simpan->Jalankan($permintaan->AmbilData($gudang->id), null);
        $hasil = redirect()->route('kelola.persediaan.stok-awal.detail', ['stokAwal' => $stokAwal->Uuid])
            ->with('Kilat', 'Draf stok awal disimpan. Periksa lagi, lalu catat supaya stok dan jurnal tercatat.');

        return $this->LanjutkanPosting($permintaan, $hasil, $stokAwal->Uuid);
    }

    public function Detail(string $stokAwal, DetailStokAwal $detail): Response
    {
        $dokumen = $this->CariStokAwal($stokAwal);
        $kelola = $this->CekIzin(IzinTenant::PersediaanKelola);
        $posting = $this->CekIzin(IzinTenant::PersediaanStokAwalPosting);
        $draf = $dokumen->Status === StatusStokAwal::Draf;

        return Inertia::render('Kelola/Persediaan/StokAwal/Detail', [
            ...$detail->Ambil($dokumen),
            'Tindakan' => [
                'Ubah' => $draf && $kelola,
                'Buang' => $draf && $kelola,
                'Posting' => $draf && $posting,
                'Batalkan' => $dokumen->Status === StatusStokAwal::Diposting && $posting,
            ],
            'Izin' => $this->AmbilIzinPersediaan(),
            'KesiapanAkun' => $this->AmbilKesiapanAkun($detail->AmbilPeranAkun($dokumen), $dokumen->IdOutlet),
            'BatasPostingLangsung' => (int) config('persediaan.StokAwal.BatasPostingLangsung', 300),
        ]);
    }

    public function Ubah(string $stokAwal, DetailStokAwal $detail): Response|RedirectResponse
    {
        $dokumen = $this->CariStokAwal($stokAwal);

        if ($dokumen->Status !== StatusStokAwal::Draf) {
            return redirect()->route('kelola.persediaan.stok-awal.detail', ['stokAwal' => $dokumen->Uuid])
                ->withErrors(['Umum' => "Stok awal berstatus {$dokumen->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', $detail->AmbilForm($dokumen), $detail->AmbilPeranAkun($dokumen));
    }

    public function Perbarui(SimpanStokAwalPermintaan $permintaan, string $stokAwal, SimpanStokAwal $simpan): RedirectResponse
    {
        $dokumen = $this->CariStokAwal($stokAwal);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $dokumen = $simpan->Jalankan($permintaan->AmbilData($gudang->id), $dokumen);
        $hasil = redirect()->route('kelola.persediaan.stok-awal.detail', ['stokAwal' => $dokumen->Uuid])
            ->with('Kilat', 'Draf stok awal disimpan.');

        return $this->LanjutkanPosting($permintaan, $hasil, $dokumen->Uuid);
    }

    public function Buang(string $stokAwal, BuangStokAwal $buang): RedirectResponse
    {
        $buang->Jalankan($this->CariStokAwal($stokAwal));

        return redirect()->route('kelola.persediaan.stok-awal.daftar')
            ->with('Kilat', 'Draf stok awal dibuang. Stok dan jurnal tidak berubah.');
    }

    /** Audit kemudahan pakai #13: "Simpan & catat" hanya bagi pemegang izin posting stok awal (sama dengan rutenya). */
    private function LanjutkanPosting(Request $permintaan, RedirectResponse $hasil, string $uuid): RedirectResponse
    {
        if ($permintaan->boolean('Lanjutkan') && ! $this->CekIzin(IzinTenant::PersediaanStokAwalPosting)) {
            return $hasil->withErrors(['Umum' => 'Draf disimpan. Anda belum punya izin mencatat stok awal; minta pemegang izin mencatatnya.']);
        }

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Posting($uuid, app(AjukanPostingStokAwal::class)), 'kelola.persediaan.stok-awal.detail', ['stokAwal' => $uuid]);
    }

    public function Posting(string $stokAwal, AjukanPostingStokAwal $ajukan): RedirectResponse
    {
        $dokumen = $this->CariStokAwal($stokAwal);
        $status = $ajukan->Jalankan($dokumen, $this->Pelaku()->Id);
        $dokumen->refresh();

        $pesan = $status === StatusStokAwal::Diposting
            ? "Stok awal {$dokumen->Nomor} dicatat. Stok dan jurnal saldo awal sudah tercatat."
            : 'Stok awal sedang dicatat di latar belakang. Halaman ini diperbarui otomatis saat selesai.';

        return redirect()->route('kelola.persediaan.stok-awal.detail', ['stokAwal' => $dokumen->Uuid])->with('Kilat', $pesan);
    }

    public function Batalkan(BatalkanStokAwalPermintaan $permintaan, string $stokAwal, BatalkanStokAwal $batalkan): RedirectResponse
    {
        $dokumen = $batalkan->Jalankan($this->CariStokAwal($stokAwal), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route('kelola.persediaan.stok-awal.detail', ['stokAwal' => $dokumen->Uuid])
            ->with('Kilat', "Stok awal {$dokumen->Nomor} dibatalkan. Stok dan jurnalnya sudah dibalik.");
    }

    public function Status(string $stokAwal): JsonResponse
    {
        $dokumen = $this->CariStokAwal($stokAwal);

        return response()->json([
            'Status' => $dokumen->Status->value,
            'LabelStatus' => $dokumen->Status->AmbilLabel(),
            'Nomor' => $dokumen->Nomor,
            'PesanGalat' => $dokumen->PesanGalat,
        ]);
    }

    /** Dokumen tenant aktif lewat Uuid; tidak ada atau lokasi stok di luar outlet pelaku = 404. */
    private function CariStokAwal(string $uuid): StokAwal
    {
        $dokumen = StokAwal::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless(app(StokAwalKebijakan::class)->CekBolehAkses($dokumen, $this->IdOutletBoleh()), 404);

        return $dokumen;
    }

    /**
     * @param  array<string, mixed>|null  $stokAwal
     * @param  list<PeranAkun>  $peran
     */
    private function RenderForm(string $mode, ?array $stokAwal, array $peran): Response
    {
        return Inertia::render('Kelola/Persediaan/StokAwal/Form', [
            'Mode' => $mode,
            'StokAwal' => $stokAwal,
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'HariIni' => app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d'),
            'BatasBaris' => (int) config('persediaan.StokAwal.MaksimalBaris', 2000),
            'MaksimalNomorSeriPerBaris' => (int) config('persediaan.StokAwal.MaksimalNomorSeriPerBaris', 1000),
            'WajibKedaluwarsaBatch' => (bool) config('persediaan.StokAwal.WajibKedaluwarsaBatch', true),
            'KesiapanAkun' => $this->AmbilKesiapanAkun($peran),
        ]);
    }

    /**
     * @param  list<PeranAkun>  $peran
     * @return array{Siap: bool, PeranBelumDipetakan: list<array{Kunci: string, Label: string}>}
     */
    private function AmbilKesiapanAkun(array $peran, ?int $idOutlet = null): array
    {
        return app(KesiapanPeranAkun::class)->Periksa($peran, $idOutlet);
    }
}
