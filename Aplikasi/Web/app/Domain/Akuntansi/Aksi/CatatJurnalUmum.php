<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\DataJurnalUmum;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Layanan\PenjagaAkunKendali;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Layanan\PenyimpanLampiranKasBank;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JurnalUmum;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FIN-05 jurnal umum manual (F-13, D-131, izin `akuntansi.kelola`): Akuntan/Pemilik memilih sendiri baris debit & kredit.
 * Dokumen `JM/{YYYY}/{MM}/{SEQ4}` dan jurnalnya (`JU/…`, `Otomatis = false`) disimpan di transaksi DB yang sama
 * (aturan #10). Dijaga di kode, bukan hanya di formulir:
 * - sekurangnya dua baris, tiap baris satu sisi > 0, Σ debit = Σ kredit > 0;
 * - akun aktif milik tenant dan **bukan akun kendali** (persediaan, piutang, hutang, deposit, aset tetap, …; aturan #9);
 * - akun yang sama di outlet yang sama tidak boleh muncul di kedua sisi (tanpa efek);
 * - pelaku berbatas outlet wajib mengisi outlet pada setiap baris dan hanya outlet aksesnya;
 * - tanggal tidak lewat hari ini dan periodenya terbuka — tidak digeser diam-diam seperti transaksi POS;
 * - idempoten per `UuidKlien`: kiriman ulang mengembalikan dokumen yang sama tanpa jurnal kedua.
 * Dokumen append-only; koreksi lewat `BalikkanJurnalUmum`. LogAudit `jurnal-umum.simpan` (dan `jurnal.posting`).
 */
final class CatatJurnalUmum
{
    public const MAKS_BARIS = 100;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PenjagaAkunKendali $penjagaKendali,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenomorDokumen $penomor,
        private readonly PostingJurnal $posting,
        private readonly PenyimpanLampiranKasBank $lampiran,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis KeteranganTidakValid, BarisTidakCukup, BarisTerlaluBanyak, AkunTidakDikenal,
     *                                 BarisTidakValid, AkunKendali, AkunDuaSisi, OutletWajib, OutletTidakBoleh,
     *                                 JurnalTidakSeimbang, JurnalKosong, TanggalMasaDepan, PeriodeTerkunci
     */
    public function Jalankan(DataJurnalUmum $data): JurnalUmum
    {
        $idTenant = $this->konteks->Wajib();
        $sudahAda = $this->CariUlang($data->uuidKlien);

        if ($sudahAda instanceof JurnalUmum) {
            return $sudahAda;
        }

        $keterangan = mb_substr(trim($data->keterangan), 0, 255);

        if ($keterangan === '') {
            throw new PelanggaranAturanBisnis('KeteranganTidakValid', 'Keterangan jurnal wajib diisi.', 'Keterangan');
        }

        $hariIni = $this->tanggalBisnis->Hitung(null);

        if ($data->tanggal->toDateString() > $hariIni->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal jurnal tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        $this->penjagaPeriode->PastikanTerbuka($data->tanggal);
        $baris = $this->PeriksaBaris($data);
        $berkas = $data->lampiran === null ? null : $this->lampiran->Simpan($idTenant, $data->lampiran, 'jurnal-umum');

        try {
            return DB::transaction(function () use ($data, $keterangan, $baris, $berkas): JurnalUmum {
                $total = Uang::Nol();

                foreach ($baris as $satu) {
                    $total = $total->Tambah($satu['Debit']);
                }

                $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::JurnalUmum, $data->tanggal->format('Y-m'));
                $referensi = $data->referensi === null ? null : mb_substr(trim($data->referensi), 0, 100);
                $dokumen = JurnalUmum::query()->create([
                    'Nomor' => $nomor,
                    'Jenis' => $data->jenis,
                    'Tanggal' => $data->tanggal->toDateString(),
                    'Referensi' => $referensi === '' ? null : $referensi,
                    'Keterangan' => $keterangan,
                    'UuidKlien' => $data->uuidKlien,
                    'TotalNilai' => $total->KeString(),
                    'PathLampiran' => $berkas['Path'] ?? null,
                    'NamaLampiran' => $berkas['NamaAsli'] ?? null,
                    'MimeLampiran' => $berkas['Mime'] ?? null,
                    'UkuranLampiran' => $berkas['Ukuran'] ?? null,
                    'DibuatOleh' => $data->idPengguna,
                ]);

                $jurnal = $this->posting->Jalankan(new DataJurnal(
                    jenisSumber: JenisSumberJurnal::JurnalUmum,
                    idSumber: $dokumen->Id,
                    uuidSumber: $dokumen->Uuid,
                    nomorSumber: $nomor,
                    tanggal: $data->tanggal,
                    keterangan: mb_substr("{$data->jenis->AmbilLabel()} {$nomor}: {$keterangan}", 0, 255),
                    baris: array_map(
                        fn (array $satu): DataBarisJurnal => new DataBarisJurnal(null, $satu['IdAkun'], $satu['IdOutlet'], $satu['Debit'], $satu['Kredit'], $satu['Memo']),
                        $baris,
                    ),
                    idPengguna: $data->idPengguna,
                    otomatis: false,
                ));

                $this->audit->Catat('jurnal-umum.simpan', $dokumen, nilaiBaru: [
                    'Nomor' => $nomor,
                    'Jenis' => $data->jenis->value,
                    'Tanggal' => $data->tanggal->toDateString(),
                    'Referensi' => $dokumen->Referensi,
                    'JumlahBaris' => count($baris),
                    'TotalNilai' => $total->KeString(),
                    'NomorJurnal' => $jurnal->nomor,
                    'AdaLampiran' => $berkas !== null,
                ], idPengguna: $data->idPengguna);

                return $dokumen;
            }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
        } catch (Throwable $galat) {
            if ($berkas !== null) {
                $this->lampiran->Hapus($berkas['Path']);
            }

            // Dua kiriman bersamaan dengan UuidKlien sama: yang kalah balapan mengembalikan dokumen pemenangnya.
            if ($galat instanceof UniqueConstraintViolationException) {
                $pemenang = $this->CariUlang($data->uuidKlien);

                if ($pemenang instanceof JurnalUmum) {
                    return $pemenang;
                }
            }

            throw $galat;
        }
    }

    /**
     * Baris tervalidasi dalam bentuk siap posting.
     *
     * @return list<array{IdAkun: int, IdOutlet: int|null, Debit: Uang, Kredit: Uang, Memo: string|null}>
     */
    private function PeriksaBaris(DataJurnalUmum $data): array
    {
        $jumlah = count($data->baris);

        if ($jumlah < 2) {
            throw new PelanggaranAturanBisnis('BarisTidakCukup', 'Jurnal minimal dua baris: sekurangnya satu debit dan satu kredit.', 'Baris');
        }

        if ($jumlah > self::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', 'Satu jurnal paling banyak '.self::MAKS_BARIS.' baris. Pecah menjadi beberapa jurnal.', 'Baris');
        }

        $uuid = array_values(array_unique(array_map(fn ($b): string => $b->uuidAkun, $data->baris)));
        $akunPerUuid = Akun::query()->whereIn('Uuid', $uuid)->where('Aktif', true)->get()->keyBy('Uuid');
        $akunBidang = [];
        $hasil = [];
        $totalDebit = Uang::Nol();
        $totalKredit = Uang::Nol();
        $sisiPerKunci = [];

        foreach ($data->baris as $indeks => $satu) {
            $bidang = "Baris.{$indeks}";
            $akun = $akunPerUuid->get($satu->uuidAkun);

            if (! $akun instanceof Akun) {
                throw new PelanggaranAturanBisnis('AkunTidakDikenal', 'Akun tidak ditemukan atau nonaktif.', "{$bidang}.UuidAkun");
            }

            if ($satu->debit->BernilaiNegatif() || $satu->kredit->BernilaiNegatif()) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', 'Nilai debit/kredit tidak boleh negatif.', "{$bidang}.Debit");
            }

            $adaDebit = ! $satu->debit->BernilaiNol();
            $adaKredit = ! $satu->kredit->BernilaiNol();

            if ($adaDebit === $adaKredit) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', $adaDebit
                    ? 'Satu baris hanya boleh debit atau kredit, tidak keduanya.'
                    : 'Isi debit atau kredit pada baris ini, atau hapus barisnya.', $adaDebit ? "{$bidang}.Kredit" : "{$bidang}.Debit");
            }

            if ($data->idOutletBoleh !== null) {
                if ($satu->idOutlet === null) {
                    throw new PelanggaranAturanBisnis('OutletWajib', 'Pilih outlet untuk baris ini.', "{$bidang}.UuidOutlet");
                }

                if (! in_array($satu->idOutlet, $data->idOutletBoleh, true)) {
                    throw new PelanggaranAturanBisnis('OutletTidakBoleh', 'Outlet ini di luar akses Anda.', "{$bidang}.UuidOutlet");
                }
            }

            $kunci = $akun->Id.'|'.($satu->idOutlet ?? 0);
            $sisi = $adaDebit ? 'D' : 'K';

            if (isset($sisiPerKunci[$kunci]) && $sisiPerKunci[$kunci] !== $sisi) {
                throw new PelanggaranAturanBisnis('AkunDuaSisi', "Akun {$akun->AmbilLabel()} muncul di sisi debit dan kredit pada outlet yang sama; nettokan jadi satu baris.", "{$bidang}.UuidAkun");
            }

            $sisiPerKunci[$kunci] = $sisi;
            $akunBidang["{$bidang}.UuidAkun"] = $akun;
            $totalDebit = $totalDebit->Tambah($satu->debit);
            $totalKredit = $totalKredit->Tambah($satu->kredit);
            $memo = $satu->memo === null ? null : mb_substr(trim($satu->memo), 0, 255);
            $hasil[] = [
                'IdAkun' => $akun->Id,
                'IdOutlet' => $satu->idOutlet,
                'Debit' => $satu->debit,
                'Kredit' => $satu->kredit,
                'Memo' => $memo === '' ? null : $memo,
            ];
        }

        $this->penjagaKendali->PastikanBanyak($akunBidang);

        if (! $totalDebit->SamaDengan($totalKredit)) {
            throw new PelanggaranAturanBisnis(
                'JurnalTidakSeimbang',
                "Jurnal belum seimbang: total debit {$totalDebit->FormatRupiah()}, total kredit {$totalKredit->FormatRupiah()}.",
                'Baris',
                detail: ['TotalDebit' => $totalDebit->KeString(), 'TotalKredit' => $totalKredit->KeString()],
            );
        }

        if ($totalDebit->BernilaiNol()) {
            throw new PelanggaranAturanBisnis('JurnalKosong', 'Jurnal tanpa nilai tidak dicatat.', 'Baris');
        }

        return $hasil;
    }

    private function CariUlang(string $uuidKlien): ?JurnalUmum
    {
        return JurnalUmum::query()->where('UuidKlien', $uuidKlien)->first();
    }
}
