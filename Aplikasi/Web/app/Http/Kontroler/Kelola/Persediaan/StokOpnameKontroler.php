<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Kueri\PohonKategori;
use App\Domain\Persediaan\Aksi\AjukanTinjauanStokOpname;
use App\Domain\Persediaan\Aksi\BatalkanStokOpname;
use App\Domain\Persediaan\Aksi\KembalikanStokOpname;
use App\Domain\Persediaan\Aksi\MulaiStokOpname;
use App\Domain\Persediaan\Aksi\SetujuiStokOpname;
use App\Domain\Persediaan\Aksi\SimpanHitungStokOpname;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Kueri\DaftarRakGudang;
use App\Domain\Persediaan\Kueri\DaftarStokOpname;
use App\Domain\Persediaan\Kueri\DetailStokOpname;
use App\Domain\Persediaan\Model\StokOpname;
use App\Http\Permintaan\Kelola\Persediaan\AlasanDokumenPersediaanPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\MulaiStokOpnamePermintaan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanHitungStokOpnamePermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi stok opname F-05b (routes/PersediaanDokumen.php): daftar + mulai, detail/lembar hitung (simpan
 * berkali-kali, pindai barcode lewat isian), ajukan tinjauan, kembalikan ke hitung ulang, setujui, batal. Opname di
 * lokasi di luar outlet pelaku = 404. Hitung buta: jumlah sistem tidak dikirim selama opname berlangsung
 * (`DetailStokOpname`). Izin: lihat `persediaan.lihat`; mulai/hitung/ajukan/batal `persediaan.kelola`;
 * kembalikan/setujui `persediaan.penyesuaian.setujui`.
 */
final class StokOpnameKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.opname.detail';

    public function Daftar(Request $permintaan, DaftarStokOpname $daftar, PohonKategori $kategori, DaftarRakGudang $rak): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarStokOpname::KOLOM_URUT, DaftarStokOpname::URUT_BAWAAN, DaftarStokOpname::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Opname/Daftar', 'Opname', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            'OpsiKategori' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama'], 'Jalur' => $k['Jalur']], $kategori->AmbilOpsi()),
            'OpsiRak' => $rak->AmbilOpsiAktif($this->IdOutletBoleh()),
            'OpsiStatus' => array_map(fn (StatusStokOpname $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusStokOpname::cases()),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Mulai(MulaiStokOpnamePermintaan $permintaan, MulaiStokOpname $mulai): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $kategori = $permintaan->validated('UuidKategori');
        $opname = $mulai->Jalankan(
            $gudang->id,
            is_string($kategori) ? $kategori : null,
            $permintaan->boolean('HitungButa'),
            is_string($permintaan->validated('Catatan')) ? (string) $permintaan->validated('Catatan') : null,
            $this->Pelaku()->Id,
            is_string($permintaan->validated('Uuid')) ? (string) $permintaan->validated('Uuid') : null,
            is_string($permintaan->validated('UuidRak')) ? (string) $permintaan->validated('UuidRak') : null,
        );

        return redirect()->route(self::ALAMAT, ['stokOpname' => $opname->Uuid])
            ->with('Kilat', "Stok opname {$opname->Nomor} dimulai. Transaksi tetap berjalan; selisih dihitung dari saldo saat ini.");
    }

    public function Detail(string $stokOpname, DetailStokOpname $detail): Response
    {
        $o = $this->CariOpname($stokOpname);
        $izin = $this->AmbilIzinDokumen();

        return Inertia::render('Kelola/Persediaan/Opname/Detail', [
            ...$detail->Ambil($o),
            'Tindakan' => [
                'Hitung' => $izin['Kelola'] && $o->Status === StatusStokOpname::Berlangsung,
                'Ajukan' => $izin['Kelola'] && $o->Status === StatusStokOpname::Berlangsung,
                'Kembalikan' => $izin['Setujui'] && $o->Status === StatusStokOpname::Ditinjau,
                'Setujui' => $izin['Setujui'] && $o->Status === StatusStokOpname::Ditinjau,
                'Batalkan' => $izin['Kelola'] && $o->Status->CekAktif(),
            ],
            'Izin' => $izin,
        ]);
    }

    public function SimpanHitung(SimpanHitungStokOpnamePermintaan $permintaan, string $stokOpname, SimpanHitungStokOpname $simpan): RedirectResponse
    {
        $o = $simpan->Jalankan($this->CariOpname($stokOpname), $permintaan->AmbilHitung(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['stokOpname' => $o->Uuid])->with('Kilat', "Lembar hitung disimpan ({$o->JumlahDihitung} dari {$o->JumlahBaris} baris sudah dihitung).");
    }

    public function Ajukan(string $stokOpname, AjukanTinjauanStokOpname $ajukan): RedirectResponse
    {
        $o = $ajukan->Jalankan($this->CariOpname($stokOpname), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['stokOpname' => $o->Uuid])->with('Kilat', 'Hitung selesai. Opname menunggu tinjauan dan persetujuan.');
    }

    public function Kembalikan(AlasanDokumenPersediaanPermintaan $permintaan, string $stokOpname, KembalikanStokOpname $kembalikan): RedirectResponse
    {
        $o = $kembalikan->Jalankan($this->CariOpname($stokOpname), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['stokOpname' => $o->Uuid])->with('Kilat', 'Opname dikembalikan untuk dihitung ulang.');
    }

    public function Setujui(string $stokOpname, SetujuiStokOpname $setujui): RedirectResponse
    {
        $o = $setujui->Jalankan($this->CariOpname($stokOpname), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['stokOpname' => $o->Uuid])->with('Kilat', "Stok opname {$o->Nomor} disetujui. Stok dan jurnal selisih sudah dicatat.");
    }

    public function Batalkan(AlasanDokumenPersediaanPermintaan $permintaan, string $stokOpname, BatalkanStokOpname $batalkan): RedirectResponse
    {
        $o = $batalkan->Jalankan($this->CariOpname($stokOpname), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['stokOpname' => $o->Uuid])->with('Kilat', 'Stok opname dibatalkan. Stok tidak berubah.');
    }

    private function CariOpname(string $uuid): StokOpname
    {
        $o = StokOpname::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless($this->CekBolehOutlet($o->IdOutlet), 404);

        return $o;
    }
}
