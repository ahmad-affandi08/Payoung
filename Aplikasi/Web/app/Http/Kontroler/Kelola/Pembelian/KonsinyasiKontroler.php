<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\BatalkanPembayaranKonsinyasi;
use App\Domain\Pembelian\Aksi\CatatDokumenKonsinyasi;
use App\Domain\Pembelian\Aksi\SimpanPembayaranKonsinyasi;
use App\Domain\Pembelian\Data\DataBarisKonsinyasi;
use App\Domain\Pembelian\Data\DataDokumenKonsinyasi;
use App\Domain\Pembelian\Data\DataPembayaranKonsinyasi;
use App\Domain\Pembelian\Enum\JenisDokumenKonsinyasi;
use App\Domain\Pembelian\Kueri\DaftarKonsinyasi;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Model\DokumenKonsinyasi;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PembayaranKonsinyasi;
use App\Domain\Persediaan\Kueri\CariProdukStok;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Konsinyasi F-05i (`/kelola/pembelian/konsinyasi`, izin `pembelian.kelola`): daftar penitip & hutang, dokumen
 * titipan masuk/retur, rincian penitip per periode, dan setoran hasil penjualan (beserta pembatalannya).
 */
final class KonsinyasiKontroler extends DasarPembelianKontroler
{
    private const ATURAN_JUMLAH = ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'];

    private const ATURAN_UANG = ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'];

    public function Daftar(DaftarKonsinyasi $daftar): Response
    {
        return Inertia::render('Kelola/Pembelian/Konsinyasi/Daftar', $daftar->Penitip() + ['Izin' => $this->AmbilIzinPembelian()]);
    }

    public function Dokumen(Request $permintaan, DaftarKonsinyasi $daftar, DaftarPemasok $pemasok): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarKonsinyasi::KOLOM_URUT, DaftarKonsinyasi::URUT_BAWAAN, DaftarKonsinyasi::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Konsinyasi/Dokumen/Daftar', 'Dokumen', fn (): array => $daftar->Dokumen($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiJenis' => self::Opsi(JenisDokumenKonsinyasi::class),
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(Request $permintaan, DaftarPemasok $pemasok): Response
    {
        $jenis = JenisDokumenKonsinyasi::tryFrom($permintaan->string('jenis')->toString()) ?? JenisDokumenKonsinyasi::Masuk;

        return Inertia::render('Kelola/Pembelian/Konsinyasi/Form', [
            'Jenis' => $jenis->value,
            'LabelJenis' => $jenis->AmbilLabel(),
            'UuidPemasokAwal' => $permintaan->string('pemasok')->toString() ?: null,
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'HariIni' => $this->HariIni(),
            'MaksBaris' => CatatDokumenKonsinyasi::MAKS_BARIS,
        ]);
    }

    /** Pencarian produk konsinyasi untuk form titipan (`?kata=&gudang=`), tipe FE `HasilCariProdukStok`. */
    public function CariProduk(Request $permintaan, CariProdukStok $cari): JsonResponse
    {
        $uuidGudang = $permintaan->string('gudang')->toString();
        $idGudang = $uuidGudang === '' ? null : $this->CariGudangBoleh($uuidGudang)->id;

        return response()->json([
            'Data' => $cari->Cari(mb_substr(trim($permintaan->string('kata')->toString()), 0, 100), $idGudang, max(1, min(50, $permintaan->integer('batas', 20))), hanyaKonsinyasi: true),
        ]);
    }

    public function Simpan(Request $permintaan, CatatDokumenKonsinyasi $catat): RedirectResponse
    {
        $data = $permintaan->validate([
            'Jenis' => ['required', Rule::enum(JenisDokumenKonsinyasi::class)],
            'UuidPemasok' => ['required', 'string', 'size:26'],
            'UuidGudang' => ['required', 'string', 'size:26'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.CatatDokumenKonsinyasi::MAKS_BARIS],
            'Baris.*.UuidProduk' => ['required', 'string', 'size:26'],
            'Baris.*.Jumlah' => self::ATURAN_JUMLAH,
            'Baris.*.HargaTitip' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ], [
            'UuidPemasok.*' => 'Pilih penitip.',
            'UuidGudang.*' => 'Pilih lokasi stok.',
            'Baris.required' => 'Tambahkan minimal satu barang titipan.',
            'Baris.*.Jumlah.*' => 'Isi jumlah, misal 24 atau 1.5.',
            'Baris.*.HargaTitip.*' => 'Isi harga titip dalam rupiah, misal 9000.',
        ]);
        $gudang = $this->CariGudangBoleh(strtoupper((string) $data['UuidGudang']));
        /** @var list<array{UuidProduk: string, Jumlah: string, HargaTitip?: string|null}> $baris */
        $baris = array_values($data['Baris']);

        $dokumen = $catat->Jalankan(new DataDokumenKonsinyasi(
            jenis: JenisDokumenKonsinyasi::from((string) $data['Jenis']),
            uuidPemasok: strtoupper((string) $data['UuidPemasok']),
            gudang: $gudang,
            tanggal: CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['Tanggal']) ?: CarbonImmutable::today(),
            baris: array_map(fn (array $b): DataBarisKonsinyasi => new DataBarisKonsinyasi(
                strtoupper($b['UuidProduk']),
                Kuantitas::Dari($b['Jumlah']),
                is_string($b['HargaTitip'] ?? null) && $b['HargaTitip'] !== '' ? Uang::Dari($b['HargaTitip']) : null,
            ), $baris),
            catatan: is_string($data['Catatan'] ?? null) ? $data['Catatan'] : null,
            idPengguna: $this->Pelaku()->Id,
        ));

        return to_route('kelola.pembelian.konsinyasi.dokumen.detail', ['dokumen' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} dicatat. Stok titipan ".($dokumen->Jenis === JenisDokumenKonsinyasi::Masuk ? 'bertambah' : 'berkurang').'.');
    }

    public function DetailDokumen(string $dokumen, DaftarKonsinyasi $daftar): Response
    {
        $d = DokumenKonsinyasi::query()->where('Uuid', strtoupper($dokumen))->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ($d->IdOutlet === null || ! in_array($d->IdOutlet, $boleh, true)), 404);

        return Inertia::render('Kelola/Pembelian/Konsinyasi/Dokumen/Detail', $daftar->DetailDokumen($d));
    }

    public function Penitip(string $pemasok, Request $permintaan, DaftarKonsinyasi $daftar, DaftarAkunPilihan $akun): Response
    {
        $p = $this->CariPemasokTermasukArsip($pemasok);
        $hariIni = CarbonImmutable::parse($this->HariIni());
        $dari = self::UraiTanggal($permintaan->string('dari')->toString()) ?? $hariIni->startOfMonth();
        $sampai = self::UraiTanggal($permintaan->string('sampai')->toString()) ?? $hariIni;

        if ($dari->greaterThan($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return Inertia::render('Kelola/Pembelian/Konsinyasi/Penitip/Detail', $daftar->RincianPenitip($p, $dari, $sampai) + [
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'HariIni' => $hariIni->toDateString(),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function SimpanSetoran(string $pemasok, Request $permintaan, SimpanPembayaranKonsinyasi $simpan): RedirectResponse
    {
        $p = $this->CariPemasokTermasukArsip($pemasok);
        $data = $permintaan->validate([
            'UuidAkun' => ['required', 'string', 'size:26'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => self::ATURAN_UANG,
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], ['UuidAkun.*' => 'Pilih akun kas/bank.', 'Jumlah.*' => 'Isi jumlah setoran dalam rupiah, misal 250000.']);

        $setoran = $simpan->Jalankan(new DataPembayaranKonsinyasi(
            uuidPemasok: $p->Uuid,
            uuidAkun: strtoupper((string) $data['UuidAkun']),
            tanggal: CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['Tanggal']) ?: CarbonImmutable::today(),
            jumlah: Uang::Dari((string) $data['Jumlah']),
            catatan: is_string($data['Catatan'] ?? null) ? $data['Catatan'] : null,
            idPengguna: $this->Pelaku()->Id,
        ));

        return to_route('kelola.pembelian.konsinyasi.penitip', ['pemasok' => $p->Uuid])->with('Kilat', "Setoran {$setoran->Nomor} ".Uang::Dari($setoran->Jumlah)->FormatRupiah().' dicatat dan dijurnal.');
    }

    /** Tautan sumber jurnal setoran: menuju rincian penitipnya. */
    public function Setoran(string $setoran): RedirectResponse
    {
        $s = PembayaranKonsinyasi::query()->where('Uuid', strtoupper($setoran))->firstOrFail();
        $p = $this->CariPemasokTermasukArsipDariId($s->IdPemasok);

        return to_route('kelola.pembelian.konsinyasi.penitip', ['pemasok' => $p->Uuid]);
    }

    public function BatalkanSetoran(AlasanPembelianPermintaan $permintaan, string $setoran, BatalkanPembayaranKonsinyasi $batalkan): RedirectResponse
    {
        $s = PembayaranKonsinyasi::query()->where('Uuid', strtoupper($setoran))->firstOrFail();
        $s = $batalkan->Jalankan($s, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);
        $p = $this->CariPemasokTermasukArsipDariId($s->IdPemasok);

        return to_route('kelola.pembelian.konsinyasi.penitip', ['pemasok' => $p->Uuid])->with('Kilat', $s->Status === StatusDokumenTerposting::Dibatalkan ? "Setoran {$s->Nomor} dibatalkan. Jurnalnya dibalik dan hutang penitip kembali." : "Setoran {$s->Nomor} tidak berubah.");
    }

    private function CariPemasokTermasukArsip(string $uuid): Pemasok
    {
        return Pemasok::query()->withTrashed()->where('Uuid', strtoupper($uuid))->firstOrFail();
    }

    private function CariPemasokTermasukArsipDariId(int $id): Pemasok
    {
        return Pemasok::query()->withTrashed()->whereKey($id)->firstOrFail();
    }

    private static function UraiTanggal(string $teks): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $teks) !== 1) {
            return null;
        }

        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $teks);

        return $tanggal instanceof CarbonImmutable && $tanggal->format('Y-m-d') === $teks ? $tanggal : null;
    }
}
