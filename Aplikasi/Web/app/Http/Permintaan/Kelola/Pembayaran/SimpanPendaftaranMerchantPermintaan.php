<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembayaran;

use App\Domain\Integrasi\Data\DataPendaftaranMerchant;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Isian pendaftaran merchant (Aktivasi QRIS). NIK dan nomor rekening kosong = pertahankan yang tersimpan (wajib bila
 * belum pernah disimpan). Foto boleh kosong saat draf sudah punya fotonya; keberadaan ketiga foto diperiksa saat mengirim.
 * Isi foto diperiksa sekali lagi dari byte-nya di Aksi (bukan hanya ekstensi).
 */
final class SimpanPendaftaranMerchantPermintaan extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'Nik' => $this->BersihkanAngka('Nik'),
            'NomorRekening' => $this->BersihkanAngka('NomorRekening'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $ada = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->first();
        $foto = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.(int) config('merchant.UkuranMaksimalFotoKb')];

        return [
            'NamaPemilik' => ['required', 'string', 'max:150'],
            'Nik' => [$ada?->Nik !== null ? 'nullable' : 'required', 'digits:16'],
            'Email' => ['required', 'email:rfc', 'max:150'],
            'NomorHp' => ['required', 'string', 'max:20', $this->AturanNomorHp()],
            'NamaUsaha' => ['required', 'string', 'max:150'],
            'AlamatUsaha' => ['required', 'string', 'min:10', 'max:300'],
            'IdReferensiBank' => ['required', 'integer'],
            'NamaPemilikRekening' => ['required', 'string', 'max:150'],
            'NomorRekening' => [$ada?->NomorRekening !== null ? 'nullable' : 'required', 'digits_between:5,20'],
            'FotoKtp' => $foto,
            'FotoSwafoto' => $foto,
            'FotoBuktiUsaha' => $foto,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'NamaPemilik' => 'nama pemilik',
            'Nik' => 'NIK',
            'Email' => 'email',
            'NomorHp' => 'nomor HP',
            'NamaUsaha' => 'nama usaha',
            'AlamatUsaha' => 'alamat usaha',
            'IdReferensiBank' => 'bank',
            'NamaPemilikRekening' => 'nama pemilik rekening',
            'NomorRekening' => 'nomor rekening',
            'FotoKtp' => 'foto KTP',
            'FotoSwafoto' => 'foto selfie',
            'FotoBuktiUsaha' => 'foto tempat usaha',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Nik.digits' => 'NIK harus 16 angka.',
            'NomorRekening.digits_between' => 'Nomor rekening harus berupa angka, 5 sampai 20 digit.',
            'mimes' => ':attribute harus berupa foto JPG atau PNG.',
            'max.file' => ':attribute terlalu besar. Maksimal :max KB.',
            'file' => ':attribute harus berupa berkas foto.',
        ];
    }

    public function AmbilData(): DataPendaftaranMerchant
    {
        $nik = $this->string('Nik')->toString();
        $rekening = $this->string('NomorRekening')->toString();

        return new DataPendaftaranMerchant(
            $this->string('NamaPemilik')->toString(),
            $nik === '' ? null : $nik,
            $this->string('Email')->toString(),
            $this->string('NomorHp')->toString(),
            $this->string('NamaUsaha')->toString(),
            $this->string('AlamatUsaha')->toString(),
            $this->integer('IdReferensiBank'),
            $this->string('NamaPemilikRekening')->toString(),
            $rekening === '' ? null : $rekening,
            $this->AmbilFoto('FotoKtp'),
            $this->AmbilFoto('FotoSwafoto'),
            $this->AmbilFoto('FotoBuktiUsaha'),
        );
    }

    private function AmbilFoto(string $nama): ?UploadedFile
    {
        $berkas = $this->file($nama);

        return $berkas instanceof UploadedFile ? $berkas : null;
    }

    private function BersihkanAngka(string $nama): string
    {
        return (string) preg_replace('/\D+/', '', (string) $this->input($nama, ''));
    }

    /** Nomor HP Indonesia: `08xx`, `+628xx`, atau `628xx`, spasi dan tanda hubung diabaikan. */
    private function AturanNomorHp(): Closure
    {
        return static function (string $atribut, mixed $nilai, Closure $gagal): void {
            $angka = (string) preg_replace('/[\s\-().]+/', '', (string) $nilai);

            if (preg_match('/^(?:\+?62|0)8\d{7,12}$/', $angka) !== 1) {
                $gagal('Nomor HP tidak valid. Contoh: 0812 3456 7890.');
            }
        };
    }
}
