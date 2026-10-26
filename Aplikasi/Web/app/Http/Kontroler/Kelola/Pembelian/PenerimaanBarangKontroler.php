<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\BatalkanPenerimaanBarang;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStok;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStokTempo;
use App\Domain\Pembelian\Aksi\TerimaBarang;
use App\Domain\Pembelian\Kueri\DaftarDokumenPembelian;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Kueri\DetailPembelian;
use App\Domain\Pembelian\Kueri\IsianFormPembelian;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PenyimpanLampiranPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\SimpanPenerimaanBarangPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penerimaan barang (F-04 fase 1, `/kelola/pembelian/penerimaan`): daftar, form dari PO (`?pesanan=`) atau tanpa PO,
 * posting, detail, pembatalan (pembalik), lampiran; serta belanja stok satu langkah (`/kelola/pembelian/belanja-stok`).
 */
final class PenerimaanBarangKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenPembelian $daftar, DaftarPemasok $pemasok): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenPembelian::KOLOM_URUT, DaftarDokumenPembelian::URUT_BAWAAN, DaftarDokumenPembelian::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Penerimaan/Daftar', 'Penerimaan', fn (): array => $daftar->Penerimaan($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusDokumenTerposting::class),
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(Request $permintaan, IsianFormPembelian $isian, DaftarPemasok $pemasok): Response|RedirectResponse
    {
        $uuidPesanan = $permintaan->query('pesanan');
        $pesanan = null;

        if (is_string($uuidPesanan) && $uuidPesanan !== '') {
            $po = $this->CariDokumen(PesananPembelian::class, $uuidPesanan);

            if (! $po->Status->CekBolehDiterima()) {
                return to_route('kelola.pembelian.pesanan.detail', ['pesanan' => $po->Uuid])->withErrors(['Umum' => "Pesanan berstatus {$po->Status->AmbilLabel()} tidak bisa menerima barang."]);
            }

            $pesanan = $isian->PenerimaanDariPesanan($po);
        }

        return Inertia::render('Kelola/Pembelian/Penerimaan/Form', [
            'Mode' => 'Penerimaan',
            'Pesanan' => $pesanan,
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'OpsiAkun' => [],
            'HariIni' => $this->HariIni(),
            'Lampiran' => self::AturanLampiran(),
            'MaksimalBaris' => PemrosesPenerimaanBarang::MAKS_BARIS,
        ]);
    }

    public function Simpan(SimpanPenerimaanBarangPermintaan $permintaan, TerimaBarang $terima): RedirectResponse
    {
        $uuidPesanan = $permintaan->validated('UuidPesananPembelian');
        $idGudang = null;

        if (is_string($uuidPesanan) && $uuidPesanan !== '') {
            $this->CariDokumen(PesananPembelian::class, $uuidPesanan);
        } else {
            $idGudang = $this->CariGudangBoleh((string) $permintaan->AmbilUuidGudang())->id;
        }

        $grn = $terima->Jalankan($permintaan->AmbilData($idGudang, $this->Pelaku()->Id));

        return to_route('kelola.pembelian.penerimaan.detail', ['penerimaan' => $grn->Uuid])->with('Kilat', "{$grn->Nomor} dicatat. Stok bertambah dan hutang belum difakturkan tercatat.");
    }

    public function BuatBelanja(DaftarPemasok $pemasok, DaftarAkunPilihan $akun): Response
    {
        return Inertia::render('Kelola/Pembelian/Penerimaan/Form', [
            'Mode' => 'BelanjaStok',
            'Pesanan' => null,
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'OpsiGudang' => $this->AmbilOpsiGudang(),
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'HariIni' => $this->HariIni(),
            'Lampiran' => self::AturanLampiran(),
            'MaksimalBaris' => PemrosesPenerimaanBarang::MAKS_BARIS,
        ]);
    }

    public function SimpanBelanja(SimpanPenerimaanBarangPermintaan $permintaan, SimpanBelanjaStok $simpan, SimpanBelanjaStokTempo $simpanTempo): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->AmbilUuidGudang());

        if ($permintaan->CekBayarNanti()) {
            $grn = $simpanTempo->Jalankan($permintaan->AmbilDataBelanja($gudang->id, $this->Pelaku()->Id), $permintaan->AmbilTerminHari());

            return to_route('kelola.pembelian.penerimaan.detail', ['penerimaan' => $grn->Uuid])->with('Kilat', "Belanja stok {$grn->Nomor} dicatat: stok bertambah dan hutang ke pemasok tercatat di halaman Hutang.");
        }

        $grn = $simpan->Jalankan($permintaan->AmbilDataBelanja($gudang->id, $this->Pelaku()->Id));

        return to_route('kelola.pembelian.penerimaan.detail', ['penerimaan' => $grn->Uuid])->with('Kilat', "Belanja stok {$grn->Nomor} dicatat: stok bertambah dan kas/bank berkurang.");
    }

    public function Detail(string $penerimaan, DetailPembelian $detail): Response
    {
        $grn = $this->CariDokumen(PenerimaanBarang::class, $penerimaan);
        $izin = $this->AmbilIzinPembelian();
        $aktif = $grn->Status === StatusDokumenTerposting::Diposting;

        return Inertia::render('Kelola/Pembelian/Penerimaan/Detail', [
            ...$detail->Penerimaan($grn),
            'Izin' => $izin,
            'Tindakan' => [
                'Batalkan' => $izin['Kelola'] && $aktif,
                'Retur' => $izin['Kelola'] && $aktif,
                'Fakturkan' => $izin['Kelola'] && $aktif && ! $grn->BelanjaStok && $grn->IdFakturPembelian === null && $grn->IdPemasok !== null,
                // v3.41: biaya pihak ketiga (ekspedisi, bea masuk) yang ditagih terpisah setelah barang diterima.
                'BiayaTambahan' => $izin['Kelola'] && $aktif,
            ],
        ]);
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $penerimaan, BatalkanPenerimaanBarang $batalkan): RedirectResponse
    {
        $grn = $batalkan->Jalankan($this->CariDokumen(PenerimaanBarang::class, $penerimaan), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.penerimaan.detail', ['penerimaan' => $grn->Uuid])->with('Kilat', "{$grn->Nomor} dibatalkan. Stok dan jurnalnya sudah dibalik.");
    }

    public function Lampiran(string $penerimaan, PenyimpanLampiranPembelian $penyimpan): StreamedResponse
    {
        $grn = $this->CariDokumen(PenerimaanBarang::class, $penerimaan);

        return $penyimpan->Unduh($grn->PathLampiran, $grn->NamaLampiran, $grn->MimeLampiran);
    }

    /**
     * @return array{Ekstensi: list<string>, UkuranMaksimalKb: int}
     */
    public static function AturanLampiran(): array
    {
        return ['Ekstensi' => array_values(array_map('strval', (array) config('akuntansi.EkstensiLampiran'))), 'UkuranMaksimalKb' => (int) config('akuntansi.UkuranMaksimalLampiranKb')];
    }
}
