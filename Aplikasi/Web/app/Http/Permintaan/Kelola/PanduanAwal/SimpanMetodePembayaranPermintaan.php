<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\PanduanAwal;

use App\Domain\Penjualan\Aksi\SimpanMetodePembayaran;
use App\Domain\Penjualan\Data\DataMetodePembayaran;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * F-01 langkah 5: QRIS statis (gambar), QRIS dinamis (F-08, tanpa isian tambahan), EDC (bank), atau transfer (bank +
 * rekening), deposit, atau (X8) platform ojol/marketplace (wajib kanal platform). Kolom yang tidak relevan dikirim
 * null. Biaya string desimal bertitik, dibandingkan dengan BigDecimal: MDR 0–10 persen, komisi platform 0–40 persen.
 */
final class SimpanMetodePembayaranPermintaan extends FormRequest
{
    public const POLA_PERSEN_BIAYA = '/^\d{1,2}(\.\d{1,4})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $jenis = implode(',', array_map(fn (JenisMetodePembayaran $satu) => $satu->value, JenisMetodePembayaran::AmbilJenisPanduan()));
        $kanal = implode(',', array_map(fn (KanalPenjualan $satu) => $satu->value, KanalPenjualan::AmbilPlatform()));
        $maksimal = SimpanMetodePembayaran::AmbilPersenMaksimal(JenisMetodePembayaran::tryFrom($this->string('Jenis')->toString()) ?? JenisMetodePembayaran::Edc);

        return [
            'Jenis' => ['required', 'in:'.$jenis],
            'Nama' => ['required', 'string', 'max:60'],
            'KodeBank' => ['nullable', 'required_if:Jenis,Edc,Transfer', 'string', 'max:30'],
            'GambarQris' => ['nullable', 'required_if:Jenis,QrisStatis', 'file', 'mimes:'.implode(',', (array) config('pembayaran.EkstensiGambarQris')), 'max:'.config('pembayaran.UkuranMaksimalGambarQrisKb')],
            // D-189: payload QRIS hasil baca gambar di peramban; keabsahannya diperiksa Aksi (kode galat jelas).
            'TeksQris' => ['nullable', 'string', 'max:600'],
            'NomorRekening' => ['nullable', 'required_if:Jenis,Transfer', 'string', 'regex:/^\d{5,30}$/'],
            'NamaPemilikRekening' => ['nullable', 'required_if:Jenis,Transfer', 'string', 'max:100'],
            'Kanal' => ['nullable', 'required_if:Jenis,Marketplace', 'in:'.$kanal],
            'PersenBiaya' => [
                'nullable',
                'string',
                'regex:'.self::POLA_PERSEN_BIAYA,
                function (string $atribut, mixed $nilai, Closure $gagal) use ($maksimal): void {
                    if (is_string($nilai) && preg_match(self::POLA_PERSEN_BIAYA, $nilai) === 1
                        && BigDecimal::of($nilai)->isGreaterThan($maksimal)) {
                        $gagal("Biaya 0 sampai {$maksimal} persen.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Jenis.required' => 'Pilih jenis metode pembayaran.',
            'Jenis.in' => 'Pilih QRIS statis, QRIS dinamis, kartu (EDC), transfer bank, deposit pelanggan, atau platform ojol/marketplace.',
            'Nama.required' => 'Isi nama metode pembayaran, misal QRIS Toko.',
            'KodeBank.required_if' => 'Pilih bank.',
            'GambarQris.required_if' => 'Unggah gambar QRIS dari bank atau penyedia QRIS Anda.',
            'GambarQris.mimes' => 'Gambar QRIS berupa PNG, JPG, atau WEBP.',
            'GambarQris.max' => 'Ukuran gambar QRIS maksimal '.config('pembayaran.UkuranMaksimalGambarQrisKb').' KB.',
            'NomorRekening.required_if' => 'Isi nomor rekening tujuan transfer.',
            'NomorRekening.regex' => 'Nomor rekening berisi 5–30 angka tanpa spasi atau tanda baca.',
            'NamaPemilikRekening.required_if' => 'Isi nama pemilik rekening.',
            'Kanal.required_if' => 'Pilih platform: GoFood, GrabFood, ShopeeFood, atau Marketplace.',
            'Kanal.in' => 'Pilih platform: GoFood, GrabFood, ShopeeFood, atau Marketplace.',
            'PersenBiaya.regex' => 'Biaya berupa angka persen dengan titik, misal 0.7.',
        ];
    }

    public function AmbilData(): DataMetodePembayaran
    {
        return new DataMetodePembayaran(
            jenis: JenisMetodePembayaran::from($this->string('Jenis')->toString()),
            nama: $this->string('Nama')->trim()->toString(),
            kodeBank: $this->filled('KodeBank') ? $this->string('KodeBank')->toString() : null,
            nomorRekening: $this->filled('NomorRekening') ? $this->string('NomorRekening')->toString() : null,
            namaPemilikRekening: $this->filled('NamaPemilikRekening') ? $this->string('NamaPemilikRekening')->trim()->toString() : null,
            persenBiaya: $this->filled('PersenBiaya') ? $this->string('PersenBiaya')->toString() : null,
            kanal: $this->filled('Kanal') ? KanalPenjualan::tryFrom($this->string('Kanal')->toString()) : null,
            teksQris: $this->filled('TeksQris') ? $this->string('TeksQris')->toString() : null,
        );
    }

    public function AmbilGambarQris(): ?UploadedFile
    {
        $gambar = $this->file('GambarQris');

        return $gambar instanceof UploadedFile ? $gambar : null;
    }
}
