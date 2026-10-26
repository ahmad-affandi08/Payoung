<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Data\DataPencairan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Layanan\PenyusunJurnalPencairan;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Pencairan;
use App\Domain\Penjualan\Model\PencairanDetail;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat pencairan dana non-tunai ke rekening toko (F-08, BR-08.4, J-08.1) — dokumen yang melunasi akun kliring dan
 * memunculkan potongan platform di laba-rugi.
 *
 * Tanpa dokumen ini, akun kliring hanya bertambah selamanya dan MDR/komisi ojol tidak pernah dibebankan, sehingga laba
 * terlihat lebih besar daripada kenyataan dan tidak ada yang bisa membuktikan bila platform kurang bayar.
 *
 * Semuanya dalam satu transaksi DB (aturan #10): baris pembayaran dikunci, dokumen dibuat, dan J-08.1 dicatat.
 *
 * **Yang ditolak, dan alasannya:**
 * - pembayaran tunai/tempo/deposit/uang muka (`PembayaranBukanKliring`) — uangnya tidak pernah lewat akun kliring, jadi
 *   tidak ada yang bisa dicairkan;
 * - pembayaran dari metode atau outlet lain (`PembayaranBedaMetode`, `PembayaranBedaOutlet`) — satu setoran datang dari
 *   satu platform dan akun kliringnya milik metode, jadi mencampurnya akan mengkredit akun yang bukan sumbernya;
 * - pembayaran dari penjualan yang di-void (`PenjualanSudahVoid`) — J-07.1-nya sudah dibalik, jadi di akun kliring tidak
 *   ada lagi sisa untuk dilunasi. Penjualan yang **diretur** tetap boleh: platform tetap menyetor nilai brutonya, dan
 *   pengembalian uang ke pembeli adalah peristiwa tersendiri;
 * - pembayaran yang sudah masuk pencairan lain (`PembayaranSudahDicairkan`) — dijaga juga indeks unik
 *   `PencairanDetail.IdPembayaranAktif`, bukan hanya pemeriksaan ini;
 * - `JumlahBersih` negatif (`JumlahBersihTidakValid`). Nol diizinkan: platform bisa menahan seluruhnya untuk
 *   diperhitungkan dengan tagihan lain, dan itu tetap peristiwa yang perlu dibukukan.
 */
final class BuatPencairan
{
    /**
     * Jenis pembayaran yang uangnya melewati akun kliring (BR-08.3); sisanya bukan urusan pencairan. `Transfer` ikut,
     * karena metode transfer boleh dipetakan ke akun kliring alih-alih langsung ke bank bila tokonya menunggu mutasi
     * rekening sebelum mengakuinya.
     *
     * @return list<JenisMetodePembayaran>
     */
    private static function JenisKliring(): array
    {
        return [
            JenisMetodePembayaran::QrisStatis,
            JenisMetodePembayaran::QrisDinamis,
            JenisMetodePembayaran::Edc,
            JenisMetodePembayaran::Ewallet,
            JenisMetodePembayaran::Transfer,
            JenisMetodePembayaran::Marketplace,
        ];
    }

    public function __construct(
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly DaftarAkunPilihan $akun,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PenomorDokumen $penomor,
        private readonly PenyusunJurnalPencairan $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis MetodeTidakDikenal, OutletTidakDikenal, AkunKasBankWajib, PembayaranWajib,
     *                                 PembayaranTidakDitemukan, PembayaranBukanKliring, PembayaranBedaMetode,
     *                                 PembayaranBedaOutlet, PenjualanSudahVoid, PembayaranSudahDicairkan,
     *                                 JumlahBersihTidakValid, TanggalMasaDepan, PeriodeTerkunci
     */
    public function Jalankan(DataPencairan $data, int $idPengguna): Pencairan
    {
        if ($data->jumlahBersih->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('JumlahBersihTidakValid', 'Jumlah yang masuk rekening tidak boleh negatif.', 'JumlahBersih');
        }

        $uuidPembayaran = array_values(array_unique(array_filter($data->uuidPembayaran, static fn (string $u): bool => $u !== '')));

        if ($uuidPembayaran === []) {
            throw new PelanggaranAturanBisnis('PembayaranWajib', 'Pilih minimal satu pembayaran yang dicairkan.', 'Pembayaran');
        }

        $metode = MetodePembayaran::query()->where('Uuid', $data->uuidMetodePembayaran)->first()
            ?? throw new PelanggaranAturanBisnis('MetodeTidakDikenal', 'Metode pembayaran tidak dikenal.', 'UuidMetodePembayaran');

        if (! in_array($metode->Jenis, self::JenisKliring(), true)) {
            throw new PelanggaranAturanBisnis(
                'PembayaranBukanKliring',
                "Metode {$metode->Nama} tidak memakai akun kliring, jadi tidak ada dana yang perlu dicairkan.",
                'UuidMetodePembayaran',
            );
        }

        $idOutlet = $this->petaOutlet->AmbilIdDariUuid([$data->uuidOutlet])[$data->uuidOutlet]
            ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak dikenal.', 'UuidOutlet');
        $idAkunTujuan = $this->akun->CariKasBankDariUuid($data->uuidAkunTujuan)['Id']
            ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas atau bank yang menerima setoran.', 'UuidAkunTujuan');

        // Setoran bertanggal besok belum terjadi; mencatatnya akan membuat saldo bank di buku mendahului rekeningnya.
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($data->tanggal, $idOutlet);
        $this->penjagaPeriode->PastikanTerbuka($data->tanggal);

        return DB::transaction(
            fn (): Pencairan => $this->Catat($data, $uuidPembayaran, $metode, $idOutlet, $idAkunTujuan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    /**
     * @param  list<string>  $uuidPembayaran
     *
     * @throws PelanggaranAturanBisnis
     */
    private function Catat(DataPencairan $data, array $uuidPembayaran, MetodePembayaran $metode, int $idOutlet, int $idAkunTujuan, int $idPengguna): Pencairan
    {
        // Dikunci berurut Id supaya dua operator yang memilih pembayaran yang sama tidak saling menyalip.
        $pembayaran = PenjualanPembayaran::query()
            ->whereIn('Uuid', $uuidPembayaran)
            ->orderBy('Id')
            ->lockForUpdate()
            ->get();

        if ($pembayaran->count() !== count($uuidPembayaran)) {
            throw new PelanggaranAturanBisnis('PembayaranTidakDitemukan', 'Ada pembayaran yang tidak ditemukan. Muat ulang halamannya.', 'Pembayaran');
        }

        $penjualan = Penjualan::query()->whereIn('Id', $pembayaran->pluck('IdPenjualan')->all())->get()->keyBy('Id');
        $sudah = PencairanDetail::query()
            ->whereIn('IdPembayaranAktif', $pembayaran->pluck('Id')->all())
            ->pluck('IdPembayaranAktif')
            ->all();

        if ($sudah !== []) {
            throw new PelanggaranAturanBisnis(
                'PembayaranSudahDicairkan',
                'Ada pembayaran yang sudah masuk pencairan lain. Muat ulang halamannya supaya daftarnya sesuai.',
                'Pembayaran',
            );
        }

        $jumlahKotor = Uang::Nol();

        foreach ($pembayaran as $satu) {
            $dokumen = $penjualan->get($satu->IdPenjualan);

            if ($satu->IdMetodePembayaran !== $metode->Id) {
                throw new PelanggaranAturanBisnis('PembayaranBedaMetode', "Pembayaran {$satu->NamaMetode} bukan milik metode {$metode->Nama}. Satu pencairan hanya untuk satu metode.", 'Pembayaran');
            }

            if ($dokumen === null || $dokumen->IdOutlet !== $idOutlet) {
                throw new PelanggaranAturanBisnis('PembayaranBedaOutlet', 'Ada pembayaran dari outlet lain. Satu pencairan hanya untuk satu outlet.', 'Pembayaran');
            }

            if ($dokumen->Status === StatusPenjualan::Void) {
                throw new PelanggaranAturanBisnis('PenjualanSudahVoid', "Penjualan {$dokumen->Nomor} sudah di-void, jadi tidak ada dana yang dicairkan darinya.", 'Pembayaran');
            }

            $jumlahKotor = $jumlahKotor->Tambah($satu->AmbilJumlah());
        }

        $biaya = $jumlahKotor->Kurangi($data->jumlahBersih);
        $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::Pencairan, $data->tanggal->format('Y-m'));

        $pencairan = Pencairan::query()->create([
            'Nomor' => $nomor,
            'IdOutlet' => $idOutlet,
            'IdMetodePembayaran' => $metode->Id,
            'Tanggal' => $data->tanggal->toDateString(),
            'IdAkunTujuan' => $idAkunTujuan,
            'IdAkunKliring' => $metode->IdAkunKliring,
            'JumlahKotor' => $jumlahKotor->KeString(),
            'JumlahBersih' => $data->jumlahBersih->KeString(),
            'Biaya' => $biaya->KeString(),
            'BiayaDiharapkan' => self::HitungBiayaDiharapkan($metode, $jumlahKotor, $pembayaran->count())->KeString(),
            'Referensi' => $data->referensi,
            'Catatan' => $data->catatan,
            'DibuatOleh' => $idPengguna,
        ]);

        $urutan = 0;

        foreach ($pembayaran as $satu) {
            $dokumen = $penjualan->get($satu->IdPenjualan);
            $urutan++;
            PencairanDetail::query()->create([
                'IdPencairan' => $pencairan->Id,
                'Urutan' => $urutan,
                'IdPenjualanPembayaran' => $satu->Id,
                'IdPembayaranAktif' => $satu->Id,
                'IdPenjualan' => $satu->IdPenjualan,
                'NomorPenjualan' => $dokumen->Nomor ?? '',
                'TanggalPenjualan' => ($dokumen->TanggalBisnis ?? $satu->DibayarPada)->format('Y-m-d'),
                'Jumlah' => $satu->AmbilJumlah()->KeString(),
                'RefEksternal' => $satu->RefEksternal,
            ]);
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::Pencairan,
            idSumber: $pencairan->Id,
            uuidSumber: $pencairan->Uuid,
            nomorSumber: $nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Pencairan {$metode->Nama} {$nomor}", 0, 255),
            baris: $this->penyusunJurnal->Baris(
                $jumlahKotor,
                $data->jumlahBersih,
                $biaya,
                $idAkunTujuan,
                $metode->IdAkunKliring,
                $idOutlet,
            ),
            idPengguna: $idPengguna,
        ));

        $pencairan->IdJurnal = $jurnal->idJurnal;
        $pencairan->save();

        $this->audit->Catat('pencairan.buat', $pencairan, nilaiBaru: [
            'Nomor' => $nomor,
            'Metode' => $metode->Nama,
            'Tanggal' => $data->tanggal->toDateString(),
            'JumlahKotor' => $jumlahKotor->KeString(),
            'JumlahBersih' => $data->jumlahBersih->KeString(),
            'Biaya' => $biaya->KeString(),
            'JumlahPembayaran' => $pembayaran->count(),
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $idPengguna);

        return $pencairan;
    }

    /**
     * Biaya yang diharapkan dari pengaturan metode: persen dari nilai transaksi + biaya tetap per transaksi. Hanya
     * pembanding untuk operator; yang masuk jurnal selalu selisih uang yang benar-benar masuk.
     */
    private static function HitungBiayaDiharapkan(MetodePembayaran $metode, Uang $jumlahKotor, int $jumlahTransaksi): Uang
    {
        $persen = BigDecimal::of($metode->PersenBiaya)->dividedBy(100, 8, RoundingMode::HalfUp);

        return $jumlahKotor->Kali($persen)->Tambah(Uang::Dari($metode->BiayaTetap)->Kali($jumlahTransaksi));
    }
}
