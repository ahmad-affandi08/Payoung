<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\BatalkanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\SetujuiPenyesuaianStok;
use App\Domain\Persediaan\Aksi\SimpanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\TolakPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Kueri\DaftarPenyesuaianStok;
use App\Domain\Persediaan\Kueri\DetailPenyesuaianStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use App\Http\Permintaan\Kelola\Persediaan\AlasanDokumenPersediaanPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanPenyesuaianStokPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi penyesuaian stok F-05b (routes/PersediaanDokumen.php): daftar, form draf, detail, ajukan (posting
 * langsung di bawah batas, atau menunggu persetujuan), setujui/tolak (izin `persediaan.penyesuaian.setujui`, orang
 * lain), batal draf. Lokasi di luar outlet pelaku = 404.
 */
final class PenyesuaianStokKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.penyesuaian.detail';

    public function Daftar(Request $permintaan, DaftarPenyesuaianStok $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPenyesuaianStok::KOLOM_URUT, DaftarPenyesuaianStok::URUT_BAWAAN, DaftarPenyesuaianStok::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Penyesuaian/Daftar', 'Penyesuaian', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            'OpsiStatus' => array_map(fn (StatusPenyesuaianStok $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPenyesuaianStok::cases()),
            'OpsiAlasan' => self::OpsiAlasan(),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Buat(): Response
    {
        return $this->RenderForm('Buat', null);
    }

    public function Simpan(SimpanPenyesuaianStokPermintaan $permintaan, SimpanPenyesuaianStok $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $dokumen = $simpan->Jalankan($permintaan->AmbilData($gudang->id), null);
        $hasil = redirect()->route(self::ALAMAT, ['penyesuaianStok' => $dokumen->Uuid])
            ->with('Kilat', 'Draf penyesuaian disimpan. Periksa lagi, lalu ajukan supaya stok dan jurnal tercatat.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($dokumen->Uuid, app(AjukanPenyesuaianStok::class)), self::ALAMAT, ['penyesuaianStok' => $dokumen->Uuid]);
    }

    public function Detail(string $penyesuaianStok, DetailPenyesuaianStok $detail, PengaturanPersediaanTenant $pengaturan): Response
    {
        $p = $this->CariPenyesuaian($penyesuaianStok);
        $izin = $this->AmbilIzinDokumen();
        $pelaku = $this->Pelaku()->Id;
        $draf = $p->Status === StatusPenyesuaianStok::Draf;
        $menunggu = $p->Status === StatusPenyesuaianStok::MenungguPersetujuan;

        return Inertia::render('Kelola/Persediaan/Penyesuaian/Detail', [
            ...$detail->Ambil($p),
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $draf,
                'Ajukan' => $izin['Kelola'] && $draf,
                'Batalkan' => $izin['Kelola'] && $draf,
                'Setujui' => $izin['Setujui'] && $menunggu && $pelaku !== $p->DibuatOleh && $pelaku !== $p->DiajukanOleh,
                'Tolak' => $izin['Setujui'] && $menunggu,
            ],
            'Izin' => $izin,
            'BatasPersetujuan' => $pengaturan->Ambil()->batasPersetujuanPenyesuaian->KeString(),
        ]);
    }

    public function Ubah(string $penyesuaianStok, DetailPenyesuaianStok $detail): Response|RedirectResponse
    {
        $p = $this->CariPenyesuaian($penyesuaianStok);

        if ($p->Status !== StatusPenyesuaianStok::Draf) {
            return redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->withErrors(['Umum' => "Penyesuaian berstatus {$p->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', $detail->AmbilForm($p));
    }

    public function Perbarui(SimpanPenyesuaianStokPermintaan $permintaan, string $penyesuaianStok, SimpanPenyesuaianStok $simpan): RedirectResponse
    {
        $p = $this->CariPenyesuaian($penyesuaianStok);
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $p = $simpan->Jalankan($permintaan->AmbilData($gudang->id), $p);
        $hasil = redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->with('Kilat', 'Draf penyesuaian disimpan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Ajukan($p->Uuid, app(AjukanPenyesuaianStok::class)), self::ALAMAT, ['penyesuaianStok' => $p->Uuid]);
    }

    public function Ajukan(string $penyesuaianStok, AjukanPenyesuaianStok $ajukan): RedirectResponse
    {
        $p = $ajukan->Jalankan($this->CariPenyesuaian($penyesuaianStok), $this->Pelaku()->Id);
        $pesan = $p->Status === StatusPenyesuaianStok::Diposting
            ? "Penyesuaian {$p->Nomor} dicatat. Stok dan jurnal sudah dicatat."
            : 'Nilai penyesuaian di atas batas. Penyesuaian menunggu persetujuan pengguna lain yang berwenang.';

        return redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->with('Kilat', $pesan);
    }

    public function Setujui(string $penyesuaianStok, SetujuiPenyesuaianStok $setujui): RedirectResponse
    {
        $p = $setujui->Jalankan($this->CariPenyesuaian($penyesuaianStok), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->with('Kilat', "Penyesuaian {$p->Nomor} disetujui dan dicatat.");
    }

    public function Tolak(AlasanDokumenPersediaanPermintaan $permintaan, string $penyesuaianStok, TolakPenyesuaianStok $tolak): RedirectResponse
    {
        $p = $tolak->Jalankan($this->CariPenyesuaian($penyesuaianStok), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->with('Kilat', 'Penyesuaian ditolak dan dikembalikan ke draf.');
    }

    public function Batalkan(string $penyesuaianStok, BatalkanPenyesuaianStok $batalkan): RedirectResponse
    {
        $p = $batalkan->Jalankan($this->CariPenyesuaian($penyesuaianStok), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['penyesuaianStok' => $p->Uuid])->with('Kilat', 'Draf penyesuaian dibatalkan. Stok tidak berubah.');
    }

    private function CariPenyesuaian(string $uuid): PenyesuaianStok
    {
        $p = PenyesuaianStok::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless($this->CekBolehOutlet($p->IdOutlet), 404);

        return $p;
    }

    /**
     * @return list<array{Nilai: string, Label: string, BolehMasuk: bool, WajibKeterangan: bool}>
     */
    private static function OpsiAlasan(): array
    {
        return array_map(fn (AlasanPenyesuaian $a): array => ['Nilai' => $a->value, 'Label' => $a->AmbilLabel(), 'BolehMasuk' => $a->CekBolehMasuk(), 'WajibKeterangan' => $a->CekWajibKeterangan()], AlasanPenyesuaian::cases());
    }

    /**
     * @param  array<string, mixed>|null  $penyesuaian
     */
    private function RenderForm(string $mode, ?array $penyesuaian): Response
    {
        return Inertia::render('Kelola/Persediaan/Penyesuaian/Form', [
            'Mode' => $mode,
            'Penyesuaian' => $penyesuaian,
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(),
            'OpsiAlasan' => self::OpsiAlasan(),
            'HariIni' => $this->HariIni(),
            'BatasBaris' => (int) config('persediaan.Dokumen.MaksimalBaris', 500),
            'WajibKedaluwarsaBatch' => (bool) config('persediaan.StokAwal.WajibKedaluwarsaBatch', true),
        ]);
    }
}
