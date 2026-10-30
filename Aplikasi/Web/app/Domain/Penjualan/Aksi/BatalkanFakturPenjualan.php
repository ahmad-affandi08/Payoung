<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Penjualan\Layanan\PenyusunJurnalGrosir;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan faktur penjualan grosir (F-12, §9.7, J-12.2 pembalik). Dokumen tidak diedit dan tidak dihapus
 * (CLAUDE.md #8): jurnal reklasifikasinya dibalik, piutangnya dibatalkan, dan surat jalannya **dilepas** sehingga bisa
 * difakturkan ulang dengan benar.
 *
 * Yang **tidak** terjadi: pendapatan, HPP, dan PPN tidak tersentuh, karena faktur tidak pernah mengakuinya. Barangnya
 * tetap terserah di pembeli dan nilainya kembali ke `PiutangBelumDifakturkan` — di situlah memang tempatnya selama
 * belum ada tagihan yang sah.
 *
 * Ditolak bila piutangnya sudah dibayar walau sebagian (`PiutangSudahDibayar`): uang yang sudah masuk tidak boleh
 * kehilangan dokumen dasarnya. Batalkan pelunasannya dulu, atau terbitkan nota kredit bila barangnya kembali.
 *
 * Ditolak pula bila faktur sudah punya retur grosir yang masih berlaku (`FakturPunyaReturGrosir`): nota kredit retur
 * itu sudah mengurangi `PiutangUsaha`, sedangkan jurnal pembalik di sini memakai total penuh faktur. Membatalkannya
 * begitu saja membuat `PiutangUsaha` minus dan `PiutangBelumDifakturkan` lebih besar dari yang sebenarnya masih
 * tertagih. Batalkan retur grosirnya dulu (urutan terbalik dari penerbitannya).
 */
final class BatalkanFakturPenjualan
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly PenyusunJurnalGrosir $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatPiutangPenjualan $pencatatPiutang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, FakturTidakDitemukan, PiutangSudahDibayar, FakturPunyaReturGrosir
     */
    public function Jalankan(string $uuidFaktur, string $alasan, int $idPengguna): FakturPenjualan
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis(
                'AlasanBatalWajib',
                'Alasan pembatalan wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.',
                'Alasan',
            );
        }

        return DB::transaction(
            fn (): FakturPenjualan => $this->Batalkan($uuidFaktur, $alasan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Batalkan(string $uuid, string $alasan, int $idPengguna): FakturPenjualan
    {
        $faktur = FakturPenjualan::query()->where('Uuid', $uuid)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('FakturTidakDitemukan', 'Faktur penjualan tidak ditemukan.');

        if ($faktur->Status === StatusDokumenTerposting::Dibatalkan) {
            return $faktur;
        }

        $halangan = $this->pencatatPiutang->PeriksaBisaBatalFaktur($faktur->Id);

        if ($halangan !== null) {
            throw new PelanggaranAturanBisnis('PiutangSudahDibayar', $halangan);
        }

        // Surat jalan dikunci lebih dulu (urutan yang sama dengan BuatReturGrosir) sebelum retur dibaca, supaya retur yang
        // sedang diterbitkan untuk faktur ini selesai dan terlihat di pemeriksaan di bawah.
        $suratJalan = SuratJalan::query()->where('IdFakturPenjualan', $faktur->Id)->orderBy('Id')->lockForUpdate()->get();
        $retur = ReturGrosir::query()
            ->where('IdFakturPenjualan', $faktur->Id)
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->orderBy('Id')
            ->lockForUpdate()
            ->first(['Nomor']);

        if ($retur !== null) {
            throw new PelanggaranAturanBisnis(
                'FakturPunyaReturGrosir',
                "Faktur {$faktur->Nomor} punya retur grosir {$retur->Nomor} yang masih berlaku. Batalkan retur itu dulu, baru faktur ini.",
            );
        }

        $tanggal = CarbonImmutable::parse($faktur->Tanggal->format('Y-m-d'));
        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::FakturPenjualan,
            idSumber: $faktur->Id,
            uuidSumber: $faktur->Uuid,
            nomorSumber: $faktur->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan faktur penjualan {$faktur->Nomor}", 0, 255),
            baris: $this->penyusunJurnal->BarisFaktur($faktur->AmbilTotal(), $faktur->IdOutlet, pembalik: true),
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $faktur->IdJurnal,
        ));

        $this->pencatatPiutang->BatalkanFaktur($faktur->Id, $idPengguna);
        $nomorSuratJalan = [];

        foreach ($suratJalan as $sj) {
            $nomorSuratJalan[] = $sj->Nomor;
            $sj->IdFakturPenjualan = null;
            $sj->DiubahOleh = $idPengguna;
            $sj->save();
        }

        $faktur->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $faktur->fill([
            'IdJurnalPembatalan' => $jurnal->idJurnal,
            'AlasanBatal' => $alasan,
            'DibatalkanOleh' => $idPengguna,
            'DibatalkanPada' => CarbonImmutable::now(),
            'DiubahOleh' => $idPengguna,
        ])->save();

        $this->riwayat->Catat(
            FakturPenjualan::JENIS_DOKUMEN,
            $faktur->Id,
            StatusDokumenTerposting::Diposting->value,
            StatusDokumenTerposting::Dibatalkan->value,
            $idPengguna,
            $alasan,
        );
        $this->audit->Catat('grosir.faktur-batalkan', $faktur, nilaiLama: ['Status' => StatusDokumenTerposting::Diposting->value], nilaiBaru: [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $faktur->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal->nomor,
            'SuratJalanDilepas' => $nomorSuratJalan,
        ], idPengguna: $idPengguna);

        return $faktur;
    }
}
