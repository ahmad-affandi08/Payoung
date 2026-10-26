import { useId, useState, type KeyboardEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import { Badge } from '@/Komponen/Ui/badge';
import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';

import { HitungKombinasiVarian, TambahUnik } from './BantuanKatalog';

export const MaksimalAtribut = 3;
export const MaksimalNilai = 20;
export const MaksimalKombinasi = 100;

type Atribut = { Nama: string; Nilai: string[] };

type PropsPenyuntingAtributVarian = {
    nilai: Atribut[];
    saatBerubah: (nilai: Atribut[]) => void;
    /** Galat server relatif, misal {"0.Nilai": "…", "1.Nama": "…"}. */
    galat?: Record<string, string | undefined>;
    disabled?: boolean;
};

function BidangNilai({
    atribut,
    saatTambah,
    disabled,
}: {
    atribut: Atribut;
    saatTambah: (nilai: string) => void;
    disabled: boolean;
}) {
    const id = useId();
    const [ketikan, AturKetikan] = useState('');
    const penuh = atribut.Nilai.length >= MaksimalNilai;
    const Tambah = () => {
        if (ketikan.trim() !== '') {
            saatTambah(ketikan);
            AturKetikan('');
        }
    };

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                Nilai {atribut.Nama || 'atribut'}
            </Label>
            <div className="flex gap-2">
                <Input
                    id={id}
                    type="text"
                    value={ketikan}
                    maxLength={50}
                    disabled={disabled || penuh}
                    onChange={(peristiwa) => AturKetikan(peristiwa.target.value)}
                    onKeyDown={(peristiwa: KeyboardEvent<HTMLInputElement>) => {
                        if (peristiwa.key === 'Enter') {
                            peristiwa.preventDefault();
                            Tambah();
                        }
                    }}
                    aria-describedby={`${id}-keterangan`}
                    className="h-8 pointer-coarse:h-11 flex-1"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={Tambah}
                    disabled={disabled || penuh}
                    className="h-8 pointer-coarse:h-11"
                >
                    Tambah nilai
                </Button>
            </div>
            <p id={`${id}-keterangan`} className="text-keterangan text-teks-sekunder">
                {penuh
                    ? `Maksimal ${String(MaksimalNilai)} nilai per atribut.`
                    : 'Tekan Enter untuk menambah, misal S, M, L.'}
            </p>
        </div>
    );
}

/**
 * Definisi atribut varian (maks 3 atribut × 20 nilai; ≤ 100 kombinasi per generasi, DesainF03 C.2).
 * Menampilkan jumlah kombinasi yang akan dihasilkan.
 */
export default function PenyuntingAtributVarian({
    nilai,
    saatBerubah,
    galat = {},
    disabled = false,
}: PropsPenyuntingAtributVarian) {
    const kombinasi = HitungKombinasiVarian(nilai);
    // Atribut pertama langsung tampil sebagai isian kosong (tanpa tombol "Tambah atribut"); baru tersimpan begitu diisi.
    const tampil: Atribut[] = nilai.length === 0 && !disabled ? [{ Nama: '', Nilai: [] }] : nilai;
    const Ubah = (indeks: number, perubahan: Partial<Atribut>) =>
        saatBerubah(tampil.map((item, i) => (i === indeks ? { ...item, ...perubahan } : item)));

    return (
        <div className="flex flex-col gap-3">
            {tampil.map((atribut, indeks) => (
                <fieldset key={indeks} className="flex flex-col gap-2 rounded-panel border border-garis bg-card p-3">
                    <legend className="px-1 text-label font-semibold text-teks-utama">Atribut {indeks + 1}</legend>
                    <BidangTeks
                        label="Nama atribut"
                        nilai={atribut.Nama}
                        saatBerubah={(teks) => Ubah(indeks, { Nama: teks })}
                        galat={galat[`${String(indeks)}.Nama`]}
                        required
                        keterangan="Misal Ukuran, Warna, atau Rasa."
                        maxLength={50}
                        disabled={disabled}
                    />
                    {atribut.Nilai.length > 0 ? (
                        <ul className="flex flex-wrap gap-2" aria-label={`Nilai ${atribut.Nama || 'atribut'}`}>
                            {atribut.Nilai.map((item) => (
                                <li key={item}>
                                    <Badge
                                        variant="secondary"
                                        className="gap-2 overflow-visible py-1 text-label font-normal"
                                    >
                                        <span className="break-all whitespace-normal">{item}</span>
                                        {!disabled ? (
                                            <Button
                                                type="button"
                                                variant="link"
                                                size="xs"
                                                onClick={() =>
                                                    Ubah(indeks, { Nilai: atribut.Nilai.filter((n) => n !== item) })
                                                }
                                                className="h-auto p-0 text-destructive"
                                                aria-label={`Hapus nilai ${item}`}
                                            >
                                                Hapus
                                            </Button>
                                        ) : null}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <BidangNilai
                        atribut={atribut}
                        disabled={disabled}
                        saatTambah={(teks) => Ubah(indeks, { Nilai: TambahUnik(atribut.Nilai, teks) })}
                    />
                    {galat[`${String(indeks)}.Nilai`] ? (
                        <p className="text-keterangan font-semibold text-bahaya">{galat[`${String(indeks)}.Nilai`]}</p>
                    ) : null}
                    {!disabled ? (
                        <p>
                            <Button
                                type="button"
                                variant="link"
                                onClick={() => saatBerubah(tampil.filter((_, i) => i !== indeks))}
                                className="h-auto px-0 text-destructive"
                            >
                                Hapus atribut {atribut.Nama || String(indeks + 1)}
                            </Button>
                        </p>
                    ) : null}
                </fieldset>
            ))}
            <div className="flex flex-wrap items-center gap-3">
                {!disabled && nilai.length > 0 && nilai.length < MaksimalAtribut ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => saatBerubah([...nilai, { Nama: '', Nilai: [] }])}
                        className="h-8 pointer-coarse:h-11"
                    >
                        Tambah atribut
                    </Button>
                ) : null}
                <p aria-live="polite" className="text-label text-teks-sekunder tabular-nums">
                    {kombinasi.length === 0
                        ? 'Belum ada kombinasi varian.'
                        : `${String(kombinasi.length)} kombinasi varian.`}
                    {kombinasi.length > MaksimalKombinasi
                        ? ` Maksimal ${String(MaksimalKombinasi)} varian baru sekali buat; kurangi nilai atribut.`
                        : ''}
                </p>
            </div>
        </div>
    );
}
