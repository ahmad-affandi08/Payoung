<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Data\DataPendaftaranMerchant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PembuatPendaftarMerchant;
use App\Domain\Integrasi\Merchant\PenyimpanBerkasKyc;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;
use ArrayObject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menyimpan draf pendaftaran merchant pembayaran tenant (data diri, rekening, dan tiga foto KYC).
 *
 * - Hanya saat `Draf`, `Gagal`, atau `Ditolak`. Dari `Gagal`/`Ditolak` menyimpan berarti mulai lagi dari `Draf`: semua
 *   jejak pendaftaran lama di DOKU (ID bisnis, ID berkas) dilepas karena akan didaftarkan ulang dan fotonya wajib
 *   diunggah ulang (foto lama sudah dihapus).
 * - Foto diperiksa dari isinya, dienkripsi, dan disimpan sementara; foto baru menggantikan yang lama (yang lama dihapus).
 * - Nomor HP disimpan dalam bentuk `62...`. NIK dan nomor rekening tidak pernah masuk log audit.
 */
final class SimpanDraftPendaftaranMerchant
{
    public const AKSI_AUDIT = 'merchant-pembayaran.simpan';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenyimpanBerkasKyc $berkas,
        private readonly ReferensiBankAktif $bank,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataPendaftaranMerchant $data): PendaftaranMerchantPembayaran
    {
        $this->PastikanBankSah($data->idReferensiBank);
        $idTenant = $this->konteks->Wajib();
        $baru = new ArrayObject;

        try {
            [$pendaftaran, $usang] = DB::transaction(fn (): array => $this->Simpan($data, $idTenant, $baru));
        } catch (Throwable $galat) {
            foreach ($baru as $path) {
                $this->berkas->Hapus($path);
            }

            throw $galat instanceof UniqueConstraintViolationException
                ? new PelanggaranAturanBisnis('SudahAda', 'Pendaftaran baru saja disimpan pengguna lain. Muat ulang halaman.', statusHttp: 409)
                : $galat;
        }

        foreach ($usang as $path) {
            $this->berkas->Hapus($path);
        }

        return $pendaftaran;
    }

    /**
     * @param  ArrayObject<int, string>  $baru  path foto yang baru ditulis (dibersihkan pemanggil bila transaksi gagal)
     * @return array{0: PendaftaranMerchantPembayaran, 1: list<string>} pendaftaran dan path foto lama yang digantikan (dihapus setelah transaksi berhasil)
     */
    private function Simpan(DataPendaftaranMerchant $data, int $idTenant, ArrayObject $baru): array
    {
        $usang = [];

        $pendaftaran = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->lockForUpdate()->first();

        if ($pendaftaran !== null && ! $pendaftaran->Status->CekBisaDiubah()) {
            throw new PelanggaranAturanBisnis('PendaftaranTidakBisaDiubah', 'Pendaftaran sedang diproses atau sudah disetujui, jadi datanya tidak bisa diubah.', statusHttp: 409);
        }

        $statusLama = $pendaftaran?->Status->value;
        $pendaftaran ??= new PendaftaranMerchantPembayaran(['Penyedia' => PembuatPendaftarMerchant::AmbilKodeAktif(), 'TokenCallback' => self::BuatToken($idTenant)]);

        if (in_array($pendaftaran->Status, [StatusPendaftaranMerchant::Gagal, StatusPendaftaranMerchant::Ditolak], true)) {
            // Mulai lagi: pendaftaran lama di DOKU dilepas, foto lama sudah dihapus jadi semuanya diunggah ulang.
            $pendaftaran->fill([
                'IdBisnisDoku' => null, 'IdBrandDoku' => null, 'KunciBersama' => null, 'StatusDoku' => null,
                'AlasanPenolakan' => null, 'DikirimPada' => null, 'DisetujuiPada' => null, 'DiperiksaPada' => null,
                'IdFileKtp' => null, 'IdFileSwafoto' => null, 'IdFileBuktiUsaha' => null,
            ]);
        }

        $nik = $data->nik ?? $pendaftaran->Nik;
        $nomorRekening = $data->nomorRekening ?? $pendaftaran->NomorRekening;

        if ($nik === null || $nik === '') {
            throw new PelanggaranAturanBisnis('NikWajib', 'NIK wajib diisi.', 'Nik');
        }

        if ($nomorRekening === null || $nomorRekening === '') {
            throw new PelanggaranAturanBisnis('RekeningWajib', 'Nomor rekening wajib diisi.', 'NomorRekening');
        }

        $pendaftaran->fill([
            'Status' => StatusPendaftaranMerchant::Draf,
            'NamaPemilik' => trim($data->namaPemilik),
            'Nik' => $nik,
            'Email' => mb_strtolower(trim($data->email)),
            'NomorHp' => self::NormalisasiNomorHp($data->nomorHp),
            'NamaUsaha' => trim($data->namaUsaha),
            'AlamatUsaha' => trim($data->alamatUsaha),
            'KategoriUsaha' => $pendaftaran->KategoriUsaha ?? (string) config('merchant.KategoriUsahaBawaan'),
            'IdReferensiBank' => $data->idReferensiBank,
            'NamaPemilikRekening' => trim($data->namaPemilikRekening),
            'NomorRekening' => $nomorRekening,
            'PesanGalat' => null,
        ]);

        $berkasBaru = false;

        foreach ([
            ['PathKtp', 'IdFileKtp', $data->fotoKtp, 'Foto KTP', 'FotoKtp'],
            ['PathSwafoto', 'IdFileSwafoto', $data->fotoSwafoto, 'Foto selfie', 'FotoSwafoto'],
            ['PathBuktiUsaha', 'IdFileBuktiUsaha', $data->fotoBuktiUsaha, 'Foto tempat usaha', 'FotoBuktiUsaha'],
        ] as [$kolomPath, $kolomId, $foto, $label, $kolomForm]) {
            if (! $foto instanceof UploadedFile) {
                continue;
            }

            if ($pendaftaran->{$kolomPath} !== null) {
                $usang[] = $pendaftaran->{$kolomPath};
            }

            $path = $this->berkas->Simpan($idTenant, $foto, $label, $kolomForm);
            $baru->append($path);
            $pendaftaran->{$kolomPath} = $path;
            $pendaftaran->{$kolomId} = null;
            $berkasBaru = true;
        }

        if ($berkasBaru) {
            $pendaftaran->BerkasDiunggahPada = now();
        }

        $pendaftaran->save();

        // Tanpa NIK dan rekening: hanya status dan foto mana yang diganti.
        $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, $statusLama === null ? null : ['Status' => $statusLama], [
            'Status' => $pendaftaran->Status->value,
            'FotoDiganti' => $berkasBaru,
            'BerkasLengkap' => $pendaftaran->CekBerkasLengkap() || $pendaftaran->CekBerkasTerunggah(),
        ]);

        return [$pendaftaran, $usang];
    }

    private function PastikanBankSah(int $idBank): void
    {
        foreach ($this->bank->Ambil([JenisReferensiBank::Bank]) as $bank) {
            if ($bank['Id'] === $idBank) {
                return;
            }
        }

        throw new PelanggaranAturanBisnis('BankTidakDikenal', 'Bank yang dipilih tidak tersedia. Pilih bank dari daftar.', 'IdReferensiBank');
    }

    /** `{IdTenant basis-36}-{acak}`: bagian tenant menetapkan scope pencarian callback tanpa query lintas tenant. */
    public static function BuatToken(int $idTenant): string
    {
        return base_convert((string) $idTenant, 10, 36).'-'.Str::random(40);
    }

    /** `0812-3456-789`, `+62 812...`, `812...` semuanya menjadi `62812...`. */
    public static function NormalisasiNomorHp(string $nomor): string
    {
        $angka = (string) preg_replace('/\D+/', '', $nomor);

        return match (true) {
            str_starts_with($angka, '62') => $angka,
            str_starts_with($angka, '0') => '62'.substr($angka, 1),
            default => '62'.$angka,
        };
    }
}
