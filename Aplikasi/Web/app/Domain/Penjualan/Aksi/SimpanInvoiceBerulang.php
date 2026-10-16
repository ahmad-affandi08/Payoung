<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataInvoiceBerulang;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulangDetail;
use Illuminate\Support\Facades\DB;

/**
 * Simpan templat invoice berulang (F-21): buat baru atau ganti isi templat yang ada. Templat yang pasti gagal ditolak di
 * sini, bukan baru saat jadwal pertamanya berjalan: baris, produk jasa/tanpa stok, kelompok pajak, dan PPh diperiksa oleh
 * `SimpanInvoice::Periksa` (aturan yang sama dengan invoice biasa), dan pelanggan harus aktif.
 *
 * - Hanya invoice penjualan yang bisa berulang; DP selalu dibuat satu per satu.
 * - Tanggal mulai tidak boleh lampau saat membuat templat (bukan menagih mundur); tanggal tak berubah saat templat diubah.
 * - Setelah siklus pertama terbit, jadwal (frekuensi, interval, tanggal mulai) terkunci, karena siklus berikutnya dihitung
 *   dari tanggal mulai; isi tagihan (baris, harga, catatan, mode) tetap boleh diubah dan berlaku untuk siklus berikutnya.
 * - Templat yang `Selesai` tidak bisa diubah.
 */
final class SimpanInvoiceBerulang
{
    public const MAKS_INTERVAL = 52;

    public const MAKS_BATAS_KALI = 600;

    public function __construct(
        private readonly SimpanInvoice $simpanInvoice,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis NamaWajib, IntervalTidakValid, TanggalMulaiLampau, TanggalBerakhirTidakValid, BatasKaliTidakValid,
     *                                 JenisTidakDidukung, PelangganTidakDikenal, PelangganNonaktif, OutletTidakDikenal,
     *                                 TemplatTidakDitemukan, TemplatSelesai, JadwalTerkunci, plus galat isian invoice
     */
    public function Jalankan(DataInvoiceBerulang $data, int $idPengguna, ?string $uuidTemplat = null): InvoiceBerulang
    {
        $nama = trim($data->nama);

        if ($nama === '' || mb_strlen($nama) > 120) {
            throw new PelanggaranAturanBisnis('NamaWajib', 'Beri nama templat (maksimal 120 karakter), misalnya "Retainer PT Sumber Makmur".', 'Nama');
        }

        if ($data->interval < 1 || $data->interval > self::MAKS_INTERVAL) {
            throw new PelanggaranAturanBisnis('IntervalTidakValid', 'Interval pengulangan 1 sampai '.self::MAKS_INTERVAL.'.', 'Interval');
        }

        if ($data->frekuensi !== FrekuensiInvoiceBerulang::Mingguan && $data->interval > 12) {
            throw new PelanggaranAturanBisnis('IntervalTidakValid', 'Interval bulanan atau tahunan paling banyak 12.', 'Interval');
        }

        if ($data->batasKali !== null && ($data->batasKali < 1 || $data->batasKali > self::MAKS_BATAS_KALI)) {
            throw new PelanggaranAturanBisnis('BatasKaliTidakValid', 'Jumlah penerbitan 1 sampai '.self::MAKS_BATAS_KALI.', atau kosongkan bila tanpa batas.', 'BatasKali');
        }

        if ($data->tanggalBerakhir !== null && $data->tanggalBerakhir->lessThan($data->tanggalMulai)) {
            throw new PelanggaranAturanBisnis('TanggalBerakhirTidakValid', 'Tanggal berakhir tidak boleh sebelum tanggal mulai.', 'TanggalBerakhir');
        }

        if ($data->isi->jenis !== JenisInvoice::Penjualan || $data->isi->uuidUangMuka !== []) {
            throw new PelanggaranAturanBisnis('JenisTidakDidukung', 'Hanya invoice penjualan yang bisa diulang otomatis; uang muka dibuat satu per satu.', 'Jenis');
        }

        $this->simpanInvoice->Periksa($data->isi);

        return DB::transaction(function () use ($data, $nama, $idPengguna, $uuidTemplat): InvoiceBerulang {
            $pelanggan = Pelanggan::query()->where('Uuid', $data->isi->uuidPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');

            if ($pelanggan->Status !== StatusPelanggan::Aktif) {
                throw new PelanggaranAturanBisnis('PelangganNonaktif', "Pelanggan {$pelanggan->Nama} sudah diarsipkan. Aktifkan lagi atau pilih pelanggan lain.", 'UuidPelanggan');
            }

            $outlet = Outlet::query()->whereKey($data->isi->idOutlet)->first()
                ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'IdOutlet');
            $templat = $uuidTemplat === null
                ? null
                : (InvoiceBerulang::query()->where('Uuid', $uuidTemplat)->lockForUpdate()->first()
                    ?? throw new PelanggaranAturanBisnis('TemplatTidakDitemukan', 'Templat invoice berulang tidak ditemukan.'));

            if ($templat !== null && $templat->Status === StatusInvoiceBerulang::Selesai) {
                throw new PelanggaranAturanBisnis('TemplatSelesai', 'Templat yang sudah selesai tidak bisa diubah. Buat templat baru.');
            }

            $nilai = $this->SusunNilai($data, $nama, $pelanggan, $outlet, $idPengguna);

            if ($templat === null) {
                $hariIni = $this->tanggalBisnis->Hitung($outlet->Id);

                if ($data->tanggalMulai->toDateString() < $hariIni->toDateString()) {
                    throw new PelanggaranAturanBisnis('TanggalMulaiLampau', 'Tanggal mulai tidak boleh sebelum hari ini ('.$hariIni->format('d/m/Y').').', 'TanggalMulai');
                }

                $templat = InvoiceBerulang::query()->create([
                    ...$nilai,
                    'Frekuensi' => $data->frekuensi,
                    'Interval' => $data->interval,
                    'TanggalMulai' => $data->tanggalMulai->toDateString(),
                    'BerikutnyaPada' => $data->tanggalMulai->toDateString(),
                    'SiklusBerikutnya' => 0,
                    'JumlahTerbit' => 0,
                    'Status' => StatusInvoiceBerulang::Aktif,
                    'DibuatOleh' => $idPengguna,
                ]);
                $lama = null;
            } else {
                $this->PastikanJadwalTidakBerubah($templat, $data);
                $lama = ['Nama' => $templat->Nama, 'Mode' => $templat->Mode->value, 'BatasKali' => $templat->BatasKali, 'TanggalBerakhir' => $templat->TanggalBerakhir?->toDateString()];
                $templat->update($nilai);
                InvoiceBerulangDetail::query()->where('IdInvoiceBerulang', $templat->Id)->delete();
            }

            $this->SimpanBaris($templat, $data);

            $this->audit->Catat($lama === null ? 'invoice-berulang.buat' : 'invoice-berulang.ubah', $templat, nilaiLama: $lama, nilaiBaru: [
                'Nama' => $templat->Nama,
                'Frekuensi' => $templat->Frekuensi->value,
                'Interval' => $templat->Interval,
                'Mode' => $templat->Mode->value,
                'BatasKali' => $templat->BatasKali,
                'TanggalBerakhir' => $templat->TanggalBerakhir?->toDateString(),
                'JumlahBaris' => count($data->isi->baris),
            ], idPengguna: $idPengguna);

            return $templat->refresh();
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /**
     * @return array<string, mixed>
     */
    private function SusunNilai(DataInvoiceBerulang $data, string $nama, Pelanggan $pelanggan, Outlet $outlet, int $idPengguna): array
    {
        $isi = $data->isi;
        $tarif = $isi->tarifPph === null || trim($isi->tarifPph) === '' ? null : str_replace(',', '.', trim($isi->tarifPph));

        return [
            'Nama' => $nama,
            'IdPelanggan' => $pelanggan->Id,
            'IdOutlet' => $outlet->Id,
            'TanggalBerakhir' => $data->tanggalBerakhir?->toDateString(),
            'BatasKali' => $data->batasKali,
            'Mode' => $data->mode,
            'TerminHari' => $isi->terminHari,
            'HargaTermasukPajak' => $isi->hargaTermasukPajak,
            'Perihal' => $this->Bersihkan($isi->perihal, 150),
            'NomorReferensi' => $this->Bersihkan($isi->nomorReferensi, 60),
            'KodePph' => $tarif === null ? null : $this->Bersihkan($isi->kodePph, 20),
            'TarifPph' => $tarif,
            'Catatan' => $this->Bersihkan($isi->catatan, 1000),
            'Syarat' => $this->Bersihkan($isi->syarat, 1000),
            'InfoPembayaran' => $this->Bersihkan($isi->infoPembayaran, 500),
            'DiubahOleh' => $idPengguna,
        ];
    }

    private function PastikanJadwalTidakBerubah(InvoiceBerulang $templat, DataInvoiceBerulang $data): void
    {
        if ($templat->JumlahTerbit === 0 && $templat->SiklusBerikutnya === 0) {
            return;
        }

        if ($templat->Frekuensi !== $data->frekuensi || $templat->Interval !== $data->interval || $templat->TanggalMulai->toDateString() !== $data->tanggalMulai->toDateString()) {
            throw new PelanggaranAturanBisnis('JadwalTerkunci', 'Jadwal (frekuensi, interval, tanggal mulai) tidak bisa diubah setelah invoice pertama terbit. Hentikan templat ini lalu buat templat baru.', 'Frekuensi');
        }
    }

    private function SimpanBaris(InvoiceBerulang $templat, DataInvoiceBerulang $data): void
    {
        foreach ($data->isi->baris as $urutan => $b) {
            $idKelompok = null;

            if ($b->uuidKelompokPajak !== null && $b->uuidKelompokPajak !== '') {
                $idKelompok = $this->kelompokPajak->CariIdBerdasarkanUuid($b->uuidKelompokPajak);
            }

            $idProduk = $b->uuidProduk === null || $b->uuidProduk === ''
                ? null
                : Produk::query()->where('Uuid', $b->uuidProduk)->value('Id');

            InvoiceBerulangDetail::query()->create([
                'IdInvoiceBerulang' => $templat->Id,
                'Urutan' => $urutan + 1,
                'IdProduk' => is_int($idProduk) ? $idProduk : null,
                'Nama' => trim($b->nama),
                'Keterangan' => $this->Bersihkan($b->keterangan, 255),
                'JenisBaris' => $b->jenisBaris,
                'SimbolSatuan' => $this->Bersihkan($b->simbolSatuan, 20),
                'Jumlah' => $b->jumlah->KeString(),
                'Harga' => $b->harga->KeString(),
                'Diskon' => $b->diskon->KeString(),
                'HargaTermasukPajak' => $b->hargaTermasukPajak,
                'IdKelompokPajak' => $idKelompok,
            ]);
        }
    }

    private function Bersihkan(?string $nilai, int $panjang): ?string
    {
        $bersih = $nilai === null ? '' : trim($nilai);

        return $bersih === '' ? null : mb_substr($bersih, 0, $panjang);
    }
}
