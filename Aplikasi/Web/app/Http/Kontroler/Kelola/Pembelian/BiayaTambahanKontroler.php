<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\BatalkanBiayaTambahanPembelian;
use App\Domain\Pembelian\Aksi\CatatBiayaTambahanPembelian;
use App\Domain\Pembelian\Data\DataBiayaTambahanPembelian;
use App\Domain\Pembelian\Enum\DasarAlokasiBiaya;
use App\Domain\Pembelian\Enum\JenisBiayaTambahan;
use App\Domain\Pembelian\Kueri\DaftarBiayaTambahan;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Kueri\DokumenHuluPembelian;
use App\Domain\Pembelian\Model\BiayaTambahanPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Respons\HalamanPilihDokumen;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Biaya tambahan pembelian (v3.41, INV-14, `/kelola/pembelian/biaya-tambahan`, izin `pembelian.kelola`): daftar,
 * formulir dari satu penerimaan barang (`?penerimaan=`), rincian, dan pembatalan.
 */
final class BiayaTambahanKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarBiayaTambahan $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarBiayaTambahan::KOLOM_URUT, DaftarBiayaTambahan::URUT_BAWAAN, DaftarBiayaTambahan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/BiayaTambahan/Daftar', 'Biaya', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiJenis' => self::Opsi(JenisBiayaTambahan::class),
            'OpsiStatus' => self::Opsi(StatusDokumenTerposting::class),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(Request $permintaan, DaftarBiayaTambahan $daftar, DaftarAkunPilihan $akun, DaftarPemasok $pemasok, DokumenHuluPembelian $hulu): Response
    {
        $uuid = $permintaan->string('penerimaan')->toString();

        // D-287: dibuka dari menu tanpa penerimaan asal, pilih dari penerimaan yang barangnya masih ada.
        if ($uuid === '') {
            return HalamanPilihDokumen::Kirim([
                'Judul' => 'Biaya tambahan pembelian',
                'Petunjuk' => 'Pilih penerimaan barang yang kena biaya ekspedisi, bea masuk, atau asuransi susulan.',
                'JudulDaftar' => 'Penerimaan barang',
                'LabelCari' => 'Cari penerimaan',
                'LabelAksi' => 'Catat biaya',
                'Kosong' => 'Belum ada penerimaan barang yang bisa dibebani biaya tambahan.',
            ], $hulu->PenerimaanUntukBiayaTambahan($this->IdOutletBoleh()), 'kelola.pembelian.biaya-tambahan.buat', 'penerimaan');
        }

        $grn = $this->CariDokumen(PenerimaanBarang::class, strtoupper($uuid));

        return Inertia::render('Kelola/Pembelian/BiayaTambahan/Buat', $daftar->Isian($grn) + [
            'OpsiJenis' => self::Opsi(JenisBiayaTambahan::class),
            'OpsiDasar' => self::Opsi(DasarAlokasiBiaya::class),
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'HariIni' => $this->HariIni(),
        ]);
    }

    public function Simpan(Request $permintaan, CatatBiayaTambahanPembelian $catat): RedirectResponse
    {
        $data = $permintaan->validate([
            'UuidPenerimaan' => ['required', 'string', 'size:26'],
            'Jenis' => ['required', Rule::enum(JenisBiayaTambahan::class)],
            'DasarAlokasi' => ['required', Rule::enum(DasarAlokasiBiaya::class)],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'UuidAkun' => ['required', 'string', 'size:26'],
            'UuidPemasok' => ['nullable', 'string', 'size:26'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], ['Jumlah.*' => 'Isi jumlah biaya dalam rupiah, misal 350000.', 'UuidAkun.*' => 'Pilih akun kas/bank.']);
        $grn = $this->CariDokumen(PenerimaanBarang::class, strtoupper((string) $data['UuidPenerimaan']));

        $biaya = $catat->Jalankan(new DataBiayaTambahanPembelian(
            idPenerimaanBarang: $grn->Id,
            jenis: JenisBiayaTambahan::from((string) $data['Jenis']),
            dasarAlokasi: DasarAlokasiBiaya::from((string) $data['DasarAlokasi']),
            tanggal: CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['Tanggal']) ?: CarbonImmutable::today(),
            jumlah: Uang::Dari((string) $data['Jumlah']),
            uuidAkun: strtoupper((string) $data['UuidAkun']),
            uuidPemasok: is_string($data['UuidPemasok'] ?? null) ? strtoupper($data['UuidPemasok']) : null,
            catatan: is_string($data['Catatan'] ?? null) ? $data['Catatan'] : null,
            idPengguna: $this->Pelaku()->Id,
        ));

        return to_route('kelola.pembelian.biaya-tambahan.detail', ['biaya' => $biaya->Uuid])->with('Kilat', "{$biaya->Nomor} dicatat: ".Uang::Dari($biaya->KePersediaan)->FormatRupiah().' ke nilai stok, '.Uang::Dari($biaya->KeHpp)->FormatRupiah().' ke HPP.');
    }

    public function Detail(string $biaya, DaftarBiayaTambahan $daftar): Response
    {
        $b = $this->CariDokumen(BiayaTambahanPembelian::class, strtoupper($biaya));
        $izin = $this->AmbilIzinPembelian();

        return Inertia::render('Kelola/Pembelian/BiayaTambahan/Detail', $daftar->Detail($b) + [
            'Izin' => $izin,
            'Tindakan' => ['Batalkan' => $izin['Kelola'] && $b->Status === StatusDokumenTerposting::Diposting],
        ]);
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $biaya, BatalkanBiayaTambahanPembelian $batalkan): RedirectResponse
    {
        $b = $batalkan->Jalankan($this->CariDokumen(BiayaTambahanPembelian::class, strtoupper($biaya)), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.biaya-tambahan.detail', ['biaya' => $b->Uuid])->with('Kilat', "{$b->Nomor} dibatalkan. Nilai stok dan jurnalnya dikembalikan.");
    }
}
