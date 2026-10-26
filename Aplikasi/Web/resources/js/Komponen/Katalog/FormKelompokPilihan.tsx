import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { JenisBahan } from '@/Komponen/Katalog/BantuanKatalog';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import PemilihProduk from '@/Komponen/Katalog/PemilihProduk';
import RingkasanGalatFormulir, { FokusGalatPertama } from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import { Button } from '@/Komponen/Ui/button';
import { FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import type {
    FormKelompokPilihan as DataFormKelompokPilihan,
    FormPilihan,
    PropsDaftarKelompokPilihan,
} from '@/Tipe/Katalog';

type Kelompok = PropsDaftarKelompokPilihan['KelompokPilihan'][number];
/** Baris pilihan di formulir + nama bahan untuk tampilan (tidak dikirim ke server). */
type BarisPilihan = FormPilihan & { NamaBahan: string | null };
type PilihanTampil = FormPilihan & { NamaProdukBahan: string | null; SimbolSatuanBahan: string | null };

/**
 * Kontrak E.9 menulis `FormKelompokPilihan & { Pilihan: (FormPilihan & {…})[] }`; interseksi dua tipe array membuat
 * TypeScript memakai elemen `FormPilihan` saat `.map`. Nilai runtime-nya memang berisi NamaProdukBahan, jadi dipersempit di sini.
 */
export function AmbilPilihanTampil(kelompok: Kelompok): PilihanTampil[] {
    return kelompok.Pilihan as PilihanTampil[];
}

export const MaksimalPilihan = 50;

/** Aturan batas pilih (DesainF03 C.4 BatasPilihanTidakValid) dan nama unik; null = sesuai. */
export function PeriksaKelompokPilihan(
    data: Pick<DataFormKelompokPilihan, 'MinimalPilih' | 'MaksimalPilih'> & { Pilihan: FormPilihan[] },
): string | null {
    const minimal = data.MinimalPilih === '' ? 0 : Number.parseInt(data.MinimalPilih, 10);
    const maksimal = data.MaksimalPilih === '' ? 0 : Number.parseInt(data.MaksimalPilih, 10);
    const aktif = data.Pilihan.filter((item) => item.Aktif).length;
    const nama = data.Pilihan.map((item) => item.Nama.trim().toLowerCase());

    if (data.Pilihan.length === 0 || data.Pilihan.length > MaksimalPilihan) {
        return `Isi 1 sampai ${String(MaksimalPilihan)} pilihan.`;
    }

    if (maksimal < 1 || maksimal > 20) {
        return 'Maksimal pilih harus 1 sampai 20.';
    }

    if (minimal > maksimal) {
        return 'Minimal pilih tidak boleh lebih besar dari maksimal pilih.';
    }

    if (minimal > aktif) {
        return `Minimal pilih ${String(minimal)}, tetapi hanya ${String(aktif)} pilihan aktif.`;
    }

    if (nama.some((item) => item === '') || new Set(nama).size !== nama.length) {
        return 'Setiap pilihan perlu nama, dan nama tidak boleh sama dalam satu kelompok.';
    }

    return null;
}

/** Ringkasan aturan kelompok: "Wajib pilih 1", "Opsional, maks 3". */
export function RingkasAturanPilih(minimal: string, maksimal: string): string {
    return minimal !== '' && minimal !== '0'
        ? `Wajib pilih ${minimal === maksimal ? minimal : `${minimal}–${maksimal}`}`
        : `Opsional, maks ${maksimal}`;
}

type PropsFormKelompokPilihan = {
    kelompok: Kelompok | null;
    bolehUbahHarga: boolean;
    /** Dipanggil setelah tersimpan (panel ubah menutup diri). Halaman buat tidak memakainya: server mengarahkan. */
    saatSelesai?: () => void;
    saatBatal: () => void;
};

/** Formulir kelompok pilihan (E.9): dipakai halaman penuh Tambah dan panel samping Ubah di halaman daftar. */
export default function FormKelompokPilihan({
    kelompok,
    bolehUbahHarga,
    saatSelesai,
    saatBatal,
}: PropsFormKelompokPilihan) {
    const formulir = useForm<Omit<DataFormKelompokPilihan, 'Pilihan'> & { Pilihan: BarisPilihan[] }>({
        Nama: kelompok?.Nama ?? '',
        MinimalPilih: kelompok?.MinimalPilih ?? '0',
        MaksimalPilih: kelompok?.MaksimalPilih ?? '1',
        Urutan: kelompok?.Urutan ?? '0',
        Pilihan: (kelompok ? AmbilPilihanTampil(kelompok) : null)?.map((item) => ({
            Uuid: item.Uuid,
            Nama: item.Nama,
            Harga: item.Harga,
            Aktif: item.Aktif,
            UuidProdukBahan: item.UuidProdukBahan,
            Jumlah: item.Jumlah,
            NamaBahan: item.NamaProdukBahan ? `${item.NamaProdukBahan} (${item.SimbolSatuanBahan ?? ''})` : null,
        })) ?? [{ Uuid: null, Nama: '', Harga: '0', Aktif: true, UuidProdukBahan: null, Jumlah: '', NamaBahan: null }],
    });
    const data = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [periksa, AturPeriksa] = useState(false);
    const [elemen, AturElemen] = useState<HTMLFormElement | null>(null);
    const galatLokal = periksa ? PeriksaKelompokPilihan(data) : null;
    const UbahPilihan = (indeks: number, perubahan: Partial<BarisPilihan>) =>
        formulir.setData((lama) => ({
            ...lama,
            Pilihan: lama.Pilihan.map((item, i) => (i === indeks ? { ...item, ...perubahan } : item)),
        }));

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (PeriksaKelompokPilihan(data) !== null) {
            return;
        }

        const opsi = {
            preserveScroll: true,
            onSuccess: () => saatSelesai?.(),
            onError: () => FokusGalatPertama(elemen),
        };

        formulir.transform((isi) => ({
            ...isi,
            Pilihan: isi.Pilihan.map((pilihan) => ({
                Uuid: pilihan.Uuid,
                Nama: pilihan.Nama,
                Harga: pilihan.Harga,
                Aktif: pilihan.Aktif,
                UuidProdukBahan: pilihan.UuidProdukBahan,
                Jumlah: pilihan.Jumlah,
            })),
        }));

        if (kelompok === null) {
            formulir.post('/kelola/kelompok-pilihan', opsi);
        } else {
            formulir.put(`/kelola/kelompok-pilihan/${kelompok.Uuid}`, opsi);
        }
    };

    return (
        <form
            ref={AturElemen}
            onSubmit={Kirim}
            noValidate
            aria-label={kelompok ? `Ubah kelompok pilihan ${kelompok.Nama}` : 'Tambah kelompok pilihan'}
            className="flex flex-col gap-4"
        >
            <RingkasanGalatFormulir galat={{ ...galat, ...(galatLokal ? { Pilihan: galatLokal } : {}) }} />
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Nama kelompok"
                        nilai={data.Nama}
                        saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                        galat={galat.Nama}
                        keterangan="Misal Level gula, Ukuran, atau Topping."
                        maxLength={100}
                        autoFocus
                        required
                    />
                </div>
                <BidangTeks
                    label="Minimal pilih"
                    nilai={data.MinimalPilih}
                    saatBerubah={(nilai) => formulir.setData('MinimalPilih', nilai.replace(/\D/g, ''))}
                    galat={galat.MinimalPilih}
                    required
                    inputMode="numeric"
                    maxLength={2}
                    keterangan="0 = boleh dilewati kasir."
                />
                <BidangTeks
                    label="Maksimal pilih"
                    nilai={data.MaksimalPilih}
                    saatBerubah={(nilai) => formulir.setData('MaksimalPilih', nilai.replace(/\D/g, ''))}
                    galat={galat.MaksimalPilih}
                    required
                    inputMode="numeric"
                    maxLength={2}
                />
            </div>
            <FieldSet className="gap-3">
                <FieldLegend variant="label" className="mb-0 text-label font-semibold text-teks-utama">
                    Pilihan ({data.Pilihan.length})
                </FieldLegend>
                {data.Pilihan.map((pilihan, indeks) => (
                    <div
                        key={pilihan.Uuid ?? `baru-${String(indeks)}`}
                        className="flex flex-col gap-3 border-t border-garis pt-3"
                    >
                        <div className="grid gap-3 sm:grid-cols-3">
                            <BidangTeks
                                label={`Nama pilihan ${String(indeks + 1)}`}
                                nilai={pilihan.Nama}
                                saatBerubah={(nilai) => UbahPilihan(indeks, { Nama: nilai })}
                                galat={galat[`Pilihan.${String(indeks)}.Nama`]}
                                required
                                maxLength={100}
                            />
                            <BidangUang
                                label={`Tambahan harga ${String(indeks + 1)}`}
                                nilai={pilihan.Harga}
                                saatBerubah={(nilai) => UbahPilihan(indeks, { Harga: nilai })}
                                galat={galat[`Pilihan.${String(indeks)}.Harga`]}
                                required
                                keterangan={
                                    bolehUbahHarga
                                        ? 'Isi 0 bila gratis.'
                                        : 'Hanya pengguna yang boleh mengubah harga produk yang bisa mengisi harga selain 0.'
                                }
                                disabled={!bolehUbahHarga}
                            />
                            <div className="flex items-end">
                                <KotakCentang
                                    label="Aktif di kasir"
                                    nilai={pilihan.Aktif}
                                    saatBerubah={(nilai) => UbahPilihan(indeks, { Aktif: nilai })}
                                />
                            </div>
                        </div>
                        {pilihan.UuidProdukBahan === null ? (
                            <PemilihProduk
                                label={`Bahan terpakai ${String(indeks + 1)} (opsional)`}
                                jenis={JenisBahan}
                                keterangan="Untuk memotong stok bahan, misal topping keju 20 gram."
                                saatPilih={(produk) =>
                                    UbahPilihan(indeks, {
                                        UuidProdukBahan: produk.Uuid,
                                        NamaBahan: `${produk.Nama} (${produk.Satuan.find((s) => s.Uuid === produk.UuidSatuanDasar)?.Simbol ?? ''})`,
                                        Jumlah: '',
                                    })
                                }
                                galat={galat[`Pilihan.${String(indeks)}.UuidProdukBahan`]}
                            />
                        ) : (
                            <div className="grid items-end gap-3 sm:grid-cols-3">
                                <p className="text-isi text-teks-utama sm:col-span-1">
                                    <span className="block text-label font-semibold">Bahan terpakai</span>
                                    {pilihan.NamaBahan ?? 'Bahan'}
                                </p>
                                <BidangJumlah
                                    label={`Jumlah bahan ${String(indeks + 1)} (satuan dasar)`}
                                    nilai={pilihan.Jumlah}
                                    saatBerubah={(nilai) => UbahPilihan(indeks, { Jumlah: nilai })}
                                    galat={galat[`Pilihan.${String(indeks)}.Jumlah`]}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() =>
                                        UbahPilihan(indeks, { UuidProdukBahan: null, Jumlah: '', NamaBahan: null })
                                    }
                                    className="h-8 pointer-coarse:h-11 justify-start text-destructive"
                                >
                                    Lepas bahan
                                </Button>
                            </div>
                        )}
                        {data.Pilihan.length > 1 ? (
                            <p>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        formulir.setData((lama) => ({
                                            ...lama,
                                            Pilihan: lama.Pilihan.filter((_, i) => i !== indeks),
                                        }))
                                    }
                                    className="text-destructive"
                                >
                                    Hapus pilihan {pilihan.Nama || String(indeks + 1)}
                                </Button>
                            </p>
                        ) : null}
                    </div>
                ))}
                {data.Pilihan.length < MaksimalPilihan ? (
                    <p>
                        <Tombol
                            type="button"
                            varian="sekunder"
                            onClick={() =>
                                formulir.setData((lama) => ({
                                    ...lama,
                                    Pilihan: [
                                        ...lama.Pilihan,
                                        {
                                            Uuid: null,
                                            Nama: '',
                                            Harga: '0',
                                            Aktif: true,
                                            UuidProdukBahan: null,
                                            Jumlah: '',
                                            NamaBahan: null,
                                        },
                                    ],
                                }))
                            }
                        >
                            Tambah pilihan
                        </Tombol>
                    </p>
                ) : null}
            </FieldSet>
            <div aria-live="polite">
                {(galatLokal ?? galat.Pilihan) ? (
                    <FieldError className="text-keterangan font-semibold">{galatLokal ?? galat.Pilihan}</FieldError>
                ) : null}
            </div>
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan kelompok pilihan
                </Tombol>
                <Tombol type="button" varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
