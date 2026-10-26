<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Data\DataMutasiKas;
use App\Domain\Kasir\Enum\JenisMutasiKas;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Layanan\PenyimpanBuktiKas;
use App\Domain\Kasir\Layanan\PenyusunJurnalMutasiKas;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Kasir\Model\MutasiKas;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F-06 langkah 4: kas masuk/keluar/setoran non-penjualan dalam shift diterima server lewat sinkron.
 *
 * - Idempoten per `Uuid` (`Duplikat`); Uuid dipakai data lain = `UuidSudahDipakai`.
 * - Shift wajib milik perangkat pengirim dan masih aktif (dikunci baris agar serial dengan tutup shift F-11). Shift yang
 *   sudah `Tertutup` tetap menerima kas dengan `PerluTinjauan` (`ShiftSudahDitutup`, F-11).
 * - Pencatat: anggota usaha; izin `penjualan.buat`/akses outlet yang hilang saat offline = diterima + `IzinBerubah` (K33); pada shift yang bukan bersama (BR-06.2) hanya pembuka
 *   shift atau pemegang `kas.keluar.setujui` (supervisor).
 * - Masuk/Keluar wajib kategori aktif yang jenisnya sama; Setoran tanpa kategori.
 * - BR-06.4: kas keluar di atas batas (`PengaturanKasirTenant`, bawaan Rp 200.000) wajib `UuidPenyetuju` yang punya
 *   izin `kas.keluar.setujui` di outlet itu. PIN diperiksa di perangkat; server memeriksa kewenangannya.
 * - Jurnal (J-06.1 / kas masuk / J-11.3) dicatat sinkron di transaksi yang sama (aturan #10).
 * - K-18: foto bukti opsional (bukan untuk setoran) disimpan sebelum transaksi; dihapus lagi bila item ditolak atau
 *   ternyata duplikat.
 */
final class CatatMutasiKas
{
    private const TOLERANSI_JAM_DETIK = 600;

    private const JUMLAH_MAKSIMAL = '9999999999999999.99';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly PenyusunJurnalMutasiKas $penyusun,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatAudit $audit,
        private readonly PenyimpanBuktiKas $bukti,
    ) {}

    public function Jalankan(DataMutasiKas $data): StatusItemSinkron
    {
        if ($data->jumlah->Bandingkan(Uang::Nol()) <= 0 || $data->jumlah->Bandingkan(Uang::Dari(self::JUMLAH_MAKSIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah kas harus lebih dari Rp 0.', 'Jumlah');
        }

        if ($data->dicatatPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu pencatatan ada di masa depan. Periksa jam perangkat.', 'DicatatPada');
        }

        if ($data->bukti !== null && $data->jenis === JenisMutasiKas::Setoran) {
            throw new PelanggaranAturanBisnis('BuktiTidakValid', 'Setoran tidak memakai foto bukti.', 'Bukti');
        }

        $path = $data->bukti === null ? null : $this->bukti->Simpan($this->konteks->Wajib(), $data->bukti);

        try {
            $status = DB::transaction(fn (): StatusItemSinkron => $this->Proses($data, $path));
        } catch (Throwable $galat) {
            $this->bukti->Hapus($path);

            throw $this->TerjemahkanGalat($galat);
        }

        if ($status !== StatusItemSinkron::Diterima) {
            $this->bukti->Hapus($path);
        }

        return $status;
    }

    private function TerjemahkanGalat(Throwable $galat): Throwable
    {
        if ($galat instanceof QueryException) {
            if (($galat->errorInfo[1] ?? null) === 1062) {
                return new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik mutasi kas ini sudah dipakai. Catat ulang di aplikasi.', 'Uuid');
            }
        }

        return $galat;
    }

    private function Proses(DataMutasiKas $data, ?string $pathBukti): StatusItemSinkron
    {
        $idTenant = $this->konteks->Wajib();
        $shift = Shift::query()->where('Uuid', $data->uuidShift)->where('IdPerangkat', $data->idPerangkat)->lockForUpdate()->first();

        if ($shift === null) {
            throw new PelanggaranAturanBisnis('ShiftTidakDikenal', 'Shift untuk mutasi kas ini tidak ditemukan di perangkat ini. Kirim data shift lebih dulu.', 'UuidShift');
        }

        $lama = MutasiKas::query()->where('Uuid', $data->uuid)->first();

        if ($lama !== null) {
            if ($lama->IdShift !== $shift->Id || $lama->Jenis !== $data->jenis || ! Uang::Dari($lama->Jumlah)->SamaDengan($data->jumlah)) {
                throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik mutasi kas ini sudah dipakai data lain. Catat ulang di aplikasi.', 'Uuid');
            }

            return StatusItemSinkron::Duplikat;
        }

        // F-11: kas yang tiba setelah shift ditutup (outbox FIFO, dicatat sebelum tutup di perangkat) tetap diterima
        // dengan tanda tinjauan karena kas seharusnya shift sudah dihitung. Status lain yang tidak aktif ditolak.
        $sudahDitutup = $shift->Status === StatusShift::Tertutup;

        if (! $shift->Status->CekAktif() && ! $sudahDitutup) {
            throw new PelanggaranAturanBisnis('ShiftTidakAktif', "Shift ini sudah {$shift->Status->AmbilLabel()}. Kas tidak bisa dicatat lagi.", 'UuidShift');
        }

        if ($data->dicatatPada->lessThan(CarbonImmutable::parse($shift->DibukaPada)->subSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu pencatatan lebih awal dari waktu buka shift.', 'DicatatPada');
        }

        // K33 (v5.55): uang di laci sudah bergerak, jadi pencatat yang izin/outlet-nya berubah saat offline tetap diterima
        // dengan tinjauan `IzinBerubah` (seperti penjualan). Yang bukan anggota usaha sama sekali tetap ditolak.
        [$pencatat, $pencatatDiOutlet] = $this->anggota->CariDiTenant($idTenant, $data->uuidPencatat, $shift->IdOutlet)
            ?? throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di usaha ini.', 'UuidPencatat');
        $izinBerubah = array_values(array_filter([
            $pencatatDiOutlet ? null : "{$pencatat->nama} tidak lagi terdaftar di outlet ini",
            $pencatat->CekIzin(IzinTenant::PenjualanBuat->value) ? null : "{$pencatat->nama} tidak lagi punya izin mencatat kas di POS",
        ]));

        if (! $shift->Bersama && $pencatat->id !== $shift->DibukaOleh && ! $pencatat->CekIzin(IzinTenant::KasKeluarSetujui->value)) {
            throw new PelanggaranAturanBisnis('BukanShiftSendiri', 'Kas hanya bisa dicatat oleh kasir pemilik shift atau supervisor (BR-06.2: shift ini bukan shift bersama).', 'UuidPencatat', 403);
        }

        $kategori = $this->AmbilKategori($data);
        [$penyetuju, $penyetujuDiOutlet] = $this->PeriksaPersetujuan($data, $idTenant, $shift->IdOutlet);

        if ($penyetuju !== null && ! $penyetujuDiOutlet) {
            $izinBerubah[] = "penyetuju {$penyetuju->nama} tidak lagi terdaftar di outlet ini";
        }

        $tinjauan = array_values(array_filter([
            $sudahDitutup ? 'ShiftSudahDitutup: kas diterima setelah shift ditutup, belum masuk hitungan kas tutup shift' : null,
            $izinBerubah === [] ? null : 'IzinBerubah: '.implode('; ', $izinBerubah),
        ]));

        $mutasi = MutasiKas::query()->create([
            'Uuid' => $data->uuid,
            'IdShift' => $shift->Id,
            'Jenis' => $data->jenis,
            'IdKategoriKas' => $kategori?->Id,
            'Jumlah' => $data->jumlah->KeString(),
            'Catatan' => $data->catatan,
            'PathLampiran' => $pathBukti,
            'DicatatOleh' => $pencatat->id,
            'DicatatPada' => $data->dicatatPada,
            'TanggalBisnis' => $this->tanggalBisnis->Hitung($shift->IdOutlet, $data->dicatatPada)->toDateString(),
            'DisetujuiOleh' => $penyetuju?->id,
            'DiterimaPada' => CarbonImmutable::now(),
            'PerluTinjauan' => $tinjauan !== [],
            'AlasanTinjauan' => $tinjauan === [] ? null : implode('; ', $tinjauan),
        ]);

        $jurnal = $this->postingJurnal->Jalankan($this->penyusun->Susun($mutasi, $kategori, $shift->IdOutlet, $shift->Uuid));
        $mutasi->IdJurnal = $jurnal->idJurnal;
        $mutasi->save();

        $this->audit->Catat('kas.mutasi.catat', $mutasi, nilaiBaru: [
            'Jenis' => $data->jenis->value,
            'Jumlah' => $data->jumlah->KeString(),
            'Kategori' => $kategori?->Nama,
            'DisetujuiOleh' => $penyetuju?->nama,
            'AdaBukti' => $pathBukti !== null,
        ], idPengguna: $pencatat->id);

        return StatusItemSinkron::Diterima;
    }

    private function AmbilKategori(DataMutasiKas $data): ?KategoriKas
    {
        $jenisKategori = $data->jenis->AmbilJenisKategori();

        if ($jenisKategori === null) {
            if ($data->uuidKategori !== null) {
                throw new PelanggaranAturanBisnis('KategoriTidakValid', 'Setoran tidak memakai kategori.', 'UuidKategori');
            }

            return null;
        }

        $kategori = $data->uuidKategori === null ? null : KategoriKas::query()->where('Uuid', $data->uuidKategori)->first();

        if ($kategori === null || $kategori->Jenis !== $jenisKategori) {
            throw new PelanggaranAturanBisnis('KategoriTidakValid', "Pilih kategori {$data->jenis->AmbilLabel()} yang terdaftar.", 'UuidKategori');
        }

        if (! $kategori->Aktif) {
            throw new PelanggaranAturanBisnis('KategoriNonaktif', "Kategori \"{$kategori->Nama}\" sudah dinonaktifkan. Pilih kategori lain.", 'UuidKategori');
        }

        return $kategori;
    }

    /**
     * @return array{0: DataAnggotaOutlet|null, 1: bool} [penyetuju, punya akses outlet]; penyetuju yang sudah tidak di
     *                                                   outlet tetapi pernah berwenang = tinjauan, bukan penolakan (K33)
     */
    private function PeriksaPersetujuan(DataMutasiKas $data, int $idTenant, int $idOutlet): array
    {
        $batas = $this->pengaturan->Ambil()->batasKasKeluar;
        $wajib = $data->jenis === JenisMutasiKas::Keluar && $data->jumlah->Bandingkan($batas) > 0;

        if ($data->uuidPenyetuju === null) {
            if ($wajib) {
                throw new PelanggaranAturanBisnis('PersetujuanDiperlukan', "Kas keluar di atas {$batas->FormatRupiah()} wajib disetujui supervisor dengan PIN.", 'UuidPenyetuju', 422, [
                    'BatasKasKeluar' => $batas->KeString(),
                ]);
            }

            return [null, true];
        }

        $ditemukan = $this->anggota->CariDiTenant($idTenant, $data->uuidPenyetuju, $idOutlet);

        if ($ditemukan === null || ! $ditemukan[0]->CekIzin(IzinTenant::KasKeluarSetujui->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Penyetuju tidak punya izin menyetujui kas keluar di outlet ini.', 'UuidPenyetuju', 403);
        }

        return $ditemukan;
    }
}
