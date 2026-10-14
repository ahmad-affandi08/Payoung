<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Kasir;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\ArahPembulatan;
use App\Domain\Tenant\Data\DataPengaturanKasir;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian pengaturan kasir (F-06, F-07b, F-11). Bidang F-07b (`BatasDiskonManual`, `BatasDiskonPenyetuju`,
 * `PembulatanTunai`) F-11 (`TutupShiftButa`, `ToleransiSelisihKas`), dan F-09 (`BatasHariRetur`) boleh tidak dikirim: nilai tersimpan dipertahankan. `PembulatanTunai` null = tanpa pembulatan.
 */
final class UbahPengaturanKasirPermintaan extends FormRequest
{
    private const POLA_PERSEN = '/^\d{1,3}(\.\d{1,2})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'BatasKasKeluar' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'ShiftBersama' => ['required', 'boolean'],
            'BatasDiskonManual' => ['sometimes', 'required', 'string', 'regex:'.self::POLA_PERSEN],
            'BatasDiskonPenyetuju' => ['sometimes', 'required', 'string', 'regex:'.self::POLA_PERSEN],
            'PembulatanTunai' => ['sometimes', 'nullable', 'array'],
            'PembulatanTunai.Kelipatan' => ['required_with:PembulatanTunai', 'integer', 'min:1', 'max:1000'],
            'PembulatanTunai.Arah' => ['required_with:PembulatanTunai', 'string', Rule::enum(ArahPembulatan::class)],
            'TutupShiftButa' => ['sometimes', 'boolean'],
            'ToleransiSelisihKas' => ['sometimes', 'required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'BatasHariRetur' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL],
            'BatasHariLewatJatuhTempo' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL],
            'BukaLaciPerluPin' => ['sometimes', 'boolean'],
            'BatasReturTanpaStrukHarian' => ['sometimes', 'required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'BatasNotifikasiTransaksi' => ['sometimes', 'required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'BatasKasKeluar.required' => 'Isi batas kas keluar. Isi 0 agar setiap kas keluar butuh persetujuan.',
            'BatasDiskonManual.required' => 'Isi batas diskon kasir dalam persen, misal 10.',
            'BatasDiskonPenyetuju.required' => 'Isi batas diskon dengan persetujuan dalam persen, misal 30.',
            'ToleransiSelisihKas.required' => 'Isi toleransi selisih kas. Isi 0 agar setiap selisih butuh persetujuan.',
            'BatasKasKeluar.regex' => 'Batas kas keluar harus berupa nominal rupiah, misal 200000.',
            'BatasDiskonManual.regex' => 'Batas diskon kasir berupa persen 0 sampai 100, misal 10.',
            'BatasDiskonPenyetuju.regex' => 'Batas diskon dengan persetujuan berupa persen 0 sampai 100, misal 30.',
            'PembulatanTunai.Kelipatan.*' => 'Kelipatan pembulatan berupa bilangan bulat Rupiah 1 sampai 1.000, misal 100.',
            'PembulatanTunai.Arah.*' => 'Pilih arah pembulatan.',
            'ToleransiSelisihKas.regex' => 'Toleransi selisih kas harus berupa nominal rupiah, misal 10000.',
            'BatasHariLewatJatuhTempo.*' => 'Batas hari lewat jatuh tempo berupa bilangan bulat 0 sampai '.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL.', misal 0.',
            'BatasReturTanpaStrukHarian.*' => 'Batas retur tanpa struk harus berupa nominal rupiah, misal 1000000. Isi 0 agar setiap retur tanpa struk ditinjau.',
            'BatasNotifikasiTransaksi.*' => 'Batas notifikasi transaksi harus berupa nominal rupiah, misal 500000. Isi 0 agar setiap void, retur, dan diskon diberitahukan.',
            'BatasHariRetur.*' => 'Batas hari retur berupa bilangan bulat 0 sampai '.DataPengaturanKasir::BATAS_HARI_RETUR_MAKSIMAL.', misal 7.',
        ];
    }

    /** Pengaturan baru; bidang F-07b/F-11/F-09 yang tidak dikirim memakai nilai `lama`. */
    public function AmbilData(DataPengaturanKasir $lama): DataPengaturanKasir
    {
        $pembulatan = $lama->pembulatanTunai;

        if ($this->has('PembulatanTunai')) {
            $pembulatan = $this->filled('PembulatanTunai.Kelipatan')
                ? ['Kelipatan' => $this->integer('PembulatanTunai.Kelipatan'), 'Arah' => ArahPembulatan::from($this->string('PembulatanTunai.Arah')->toString())]
                : null;
        }

        return new DataPengaturanKasir(
            Uang::Dari((string) $this->validated('BatasKasKeluar')),
            $this->boolean('ShiftBersama'),
            $this->has('BatasDiskonManual') ? $this->string('BatasDiskonManual')->toString() : $lama->batasDiskonManual,
            $this->has('BatasDiskonPenyetuju') ? $this->string('BatasDiskonPenyetuju')->toString() : $lama->batasDiskonPenyetuju,
            $pembulatan,
            tutupShiftButa: $this->has('TutupShiftButa') ? $this->boolean('TutupShiftButa') : $lama->tutupShiftButa,
            toleransiSelisihKas: $this->has('ToleransiSelisihKas') ? Uang::Dari($this->string('ToleransiSelisihKas')->toString()) : $lama->toleransiSelisihKas,
            batasHariRetur: $this->has('BatasHariRetur') ? $this->integer('BatasHariRetur') : $lama->batasHariRetur,
            batasHariLewatJatuhTempo: $this->has('BatasHariLewatJatuhTempo') ? $this->integer('BatasHariLewatJatuhTempo') : $lama->batasHariLewatJatuhTempo,
            bukaLaciPerluPin: $this->has('BukaLaciPerluPin') ? $this->boolean('BukaLaciPerluPin') : $lama->bukaLaciPerluPin,
            batasReturTanpaStrukHarian: $this->has('BatasReturTanpaStrukHarian') ? Uang::Dari($this->string('BatasReturTanpaStrukHarian')->toString()) : $lama->batasReturTanpaStrukHarian,
            batasNotifikasiTransaksi: $this->has('BatasNotifikasiTransaksi') ? Uang::Dari($this->string('BatasNotifikasiTransaksi')->toString()) : $lama->batasNotifikasiTransaksi,
        );
    }
}
