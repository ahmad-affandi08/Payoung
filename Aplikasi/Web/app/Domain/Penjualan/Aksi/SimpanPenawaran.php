<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataPenawaran;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Layanan\PenyusunAngkaPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simpan draf penawaran (F-21 bagian 3, D-168): buat baru atau ganti seluruh isi draf yang ada. Draf belum bernomor.
 *
 * Aturan baris sama persis dengan invoice karena memakai `SimpanInvoice::SusunBarisDokumen`: jasa atau barang tanpa
 * stok, harga dari pengguna, angka dihitung server (klien tidak pernah mengirim total atau pajak). Penawaran yang berisi
 * barang berstok akan ditolak invoice kelak, jadi ditolak sejak sekarang dengan alasan yang sama.
 */
final class SimpanPenawaran
{
    public const MAKS_BARIS = SimpanInvoice::MAKS_BARIS;

    public const MAKS_MASA_BERLAKU_HARI = 365;

    public function __construct(
        private readonly SimpanInvoice $simpanInvoice,
        private readonly PenyusunAngkaPenawaran $penyusun,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PenawaranTanpaBaris, TerlaluBanyakBaris, PelangganTidakDikenal, PelangganNonaktif,
     *                                 OutletTidakDikenal, PenawaranTidakDitemukan, PenawaranTidakBisaDiubah, MasaBerlakuTidakValid,
     *                                 TerminTidakValid, BarisTidakValid, ProdukTidakDikenal, ProdukBerstok, KelompokPajakTidakDikenal
     */
    public function Jalankan(DataPenawaran $data, int $idPengguna, ?string $uuidPenawaran = null): Penawaran
    {
        $this->PeriksaBentuk($data);

        return DB::transaction(function () use ($data, $idPengguna, $uuidPenawaran): Penawaran {
            $pelanggan = Pelanggan::query()->where('Uuid', $data->uuidPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');

            if ($pelanggan->Status !== StatusPelanggan::Aktif) {
                throw new PelanggaranAturanBisnis('PelangganNonaktif', "Pelanggan {$pelanggan->Nama} sudah diarsipkan. Aktifkan lagi atau pilih pelanggan lain.", 'UuidPelanggan');
            }

            $outlet = Outlet::query()->whereKey($data->idOutlet)->first()
                ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'IdOutlet');
            $penawaran = $uuidPenawaran === null
                ? null
                : (Penawaran::query()->where('Uuid', $uuidPenawaran)->lockForUpdate()->first()
                    ?? throw new PelanggaranAturanBisnis('PenawaranTidakDitemukan', 'Penawaran tidak ditemukan.'));

            if ($penawaran !== null && ! $penawaran->Status->CekBolehDiubah()) {
                throw new PelanggaranAturanBisnis(
                    'PenawaranTidakBisaDiubah',
                    "Penawaran {$penawaran->Nomor} sudah {$penawaran->Status->AmbilLabel()} dan tidak bisa diubah. Batalkan lalu buat penawaran baru (atau salin).",
                );
            }

            $baris = $this->simpanInvoice->SusunBarisDokumen($data->baris, JenisInvoice::Penjualan);
            $termin = $data->terminHari ?? (int) $pelanggan->TerminHari;

            if ($termin < 0 || $termin > 365) {
                throw new PelanggaranAturanBisnis('TerminTidakValid', 'Termin pembayaran 0 sampai 365 hari.', 'TerminHari');
            }

            $nilai = [
                'IdPelanggan' => $pelanggan->Id,
                'IdOutlet' => $outlet->Id,
                'Tanggal' => $data->tanggal->toDateString(),
                'BerlakuSampai' => $data->berlakuSampai->toDateString(),
                'TerminHari' => $termin,
                'Perihal' => $this->Bersihkan($data->perihal, 150),
                'NomorReferensi' => $this->Bersihkan($data->nomorReferensi, 60),
                'HargaTermasukPajak' => $data->hargaTermasukPajak,
                'Catatan' => $this->Bersihkan($data->catatan, 1000),
                'Syarat' => $this->Bersihkan($data->syarat, 1000),
                'DiubahOleh' => $idPengguna,
            ];

            if ($penawaran === null) {
                $penawaran = Penawaran::query()->create([
                    ...$nilai,
                    'KodePublik' => Str::random(32),
                    'Status' => StatusPenawaran::Draf,
                    'DibuatOleh' => $idPengguna,
                ]);
            } else {
                $penawaran->update($nilai);
                PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->delete();
            }

            foreach ($baris as $urutan => $satuBaris) {
                PenawaranDetail::query()->create([
                    ...$satuBaris,
                    'IdPenawaran' => $penawaran->Id,
                    'Urutan' => $urutan + 1,
                ]);
            }

            $this->penyusun->Terapkan($penawaran);
            $this->audit->Catat($uuidPenawaran === null ? 'penawaran.buat' : 'penawaran.ubah', $penawaran, nilaiBaru: [
                'IdPelanggan' => $pelanggan->Id,
                'JumlahBaris' => count($baris),
                'Total' => $penawaran->Total,
                'BerlakuSampai' => $penawaran->BerlakuSampai->toDateString(),
            ], idPengguna: $idPengguna);

            return $penawaran->refresh()->load('Detail');
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /** @throws PelanggaranAturanBisnis */
    private function PeriksaBentuk(DataPenawaran $data): void
    {
        if ($data->baris === []) {
            throw new PelanggaranAturanBisnis('PenawaranTanpaBaris', 'Penawaran harus punya minimal satu baris.', 'Baris');
        }

        if (count($data->baris) > self::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyakBaris', 'Satu penawaran paling banyak '.self::MAKS_BARIS.' baris.', 'Baris');
        }

        if ($data->berlakuSampai->lessThan($data->tanggal)) {
            throw new PelanggaranAturanBisnis('MasaBerlakuTidakValid', 'Penawaran berlaku sampai tanggal yang sama dengan atau setelah tanggal penawaran.', 'BerlakuSampai');
        }

        if ($data->tanggal->diffInDays($data->berlakuSampai) > self::MAKS_MASA_BERLAKU_HARI) {
            throw new PelanggaranAturanBisnis('MasaBerlakuTidakValid', 'Masa berlaku penawaran paling lama '.self::MAKS_MASA_BERLAKU_HARI.' hari.', 'BerlakuSampai');
        }
    }

    private function Bersihkan(?string $nilai, int $panjang): ?string
    {
        $bersih = $nilai === null ? '' : trim($nilai);

        return $bersih === '' ? null : mb_substr($bersih, 0, $panjang);
    }
}
