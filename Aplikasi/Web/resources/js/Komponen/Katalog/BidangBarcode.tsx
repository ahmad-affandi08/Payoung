import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';

import { PeriksaBarcode, PERISTIWA_BARCODE_TERTUNDA } from './BantuanKatalog';

type PropsBidangBarcode = {
    label: string;
    nilai: string[];
    saatBerubah: (nilai: string[]) => void;
    /** Barcode di satuan lain produk yang sama: dipakai untuk menolak duplikat sebelum dikirim. */
    barcodeLain?: string[];
    /** Galat server per indeks ("Barcode.1") atau untuk seluruh daftar. */
    galatPerIndeks?: Record<number, string | undefined>;
    galat?: string | undefined;
    disabled?: boolean;
};

/**
 * Daftar barcode satu satuan produk (BR-03.1). Pemindai kode batang mengetik lalu menekan Enter,
 * jadi Enter menambah barcode tanpa mengirim formulir. Barcode tampil dengan font Mono.
 */
export default function BidangBarcode({
    label,
    nilai,
    saatBerubah,
    barcodeLain = [],
    galatPerIndeks = {},
    galat,
    disabled,
}: PropsBidangBarcode) {
    const id = useId();
    const [ketikan, AturKetikan] = useState('');
    const [galatLokal, AturGalatLokal] = useState<string | null>(null);
    const pesanGalat = galatLokal ?? galat;
    const elemenInput = useRef<HTMLInputElement>(null);
    const adaKetikan = ketikan.trim() !== '';

    // Formulir pemanggil menahan Lanjut/Simpan bila ada barcode yang diketik tetapi belum ditambahkan
    // (`TahanBilaBarcodeTertunda`); isian ini lalu menjelaskan apa yang harus dilakukan.
    useEffect(() => {
        const elemen = elemenInput.current;
        const Beritahu = () =>
            AturGalatLokal((lama) => lama ?? 'Barcode ini belum ditambahkan. Tekan Enter atau tombol Tambah barcode.');

        elemen?.addEventListener(PERISTIWA_BARCODE_TERTUNDA, Beritahu);

        return () => elemen?.removeEventListener(PERISTIWA_BARCODE_TERTUNDA, Beritahu);
    }, []);

    const Tambah = () => {
        const barcode = ketikan.trim();

        if (barcode === '') {
            return;
        }

        const pesan = PeriksaBarcode(barcode, [...nilai, ...barcodeLain]);
        AturGalatLokal(pesan);

        if (pesan === null) {
            saatBerubah([...nilai, barcode]);
            AturKetikan('');
        }
    };

    const TekanTombol = (peristiwa: KeyboardEvent<HTMLInputElement>) => {
        if (peristiwa.key === 'Enter') {
            peristiwa.preventDefault();
            Tambah();
        }
    };

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                {label}
            </Label>
            {nilai.length > 0 ? (
                <ul className="flex flex-col gap-1" aria-label={`${label} tersimpan`}>
                    {nilai.map((barcode, indeks) => (
                        <li key={barcode} className="flex flex-col gap-0.5">
                            <span className="flex items-center justify-between gap-2 rounded-kontrol border border-garis bg-muted px-3 py-1">
                                <span className="font-mono text-label break-all text-teks-utama">{barcode}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    disabled={disabled}
                                    onClick={() => saatBerubah(nilai.filter((_, i) => i !== indeks))}
                                    className="text-destructive"
                                    aria-label={`Hapus barcode ${barcode}`}
                                >
                                    Hapus
                                </Button>
                            </span>
                            {galatPerIndeks[indeks] ? (
                                <span className="text-keterangan font-semibold text-bahaya">
                                    {galatPerIndeks[indeks]}
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            <div className="flex gap-2">
                <Input
                    id={id}
                    type="text"
                    value={ketikan}
                    onChange={(peristiwa) => AturKetikan(peristiwa.target.value)}
                    onKeyDown={TekanTombol}
                    // Barcode yang diketik lalu ditinggal (pindah isian, klik Lanjut/Simpan) ikut ditambahkan,
                    // bukan hilang diam-diam. Yang tidak valid tetap di isian beserta pesannya.
                    onBlur={Tambah}
                    ref={elemenInput}
                    data-barcode-tertunda={adaKetikan ? 'true' : undefined}
                    disabled={disabled}
                    autoComplete="off"
                    placeholder="Pindai atau ketik barcode"
                    aria-invalid={pesanGalat ? true : undefined}
                    aria-describedby={`${id}-keterangan${pesanGalat ? ` ${id}-galat` : ''}`}
                    className="h-8 pointer-coarse:h-11 flex-1 font-mono tracking-wide"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={Tambah}
                    disabled={disabled}
                    className="h-8 pointer-coarse:h-11"
                >
                    Tambah barcode
                </Button>
            </div>
            <p id={`${id}-keterangan`} className="text-keterangan text-teks-sekunder">
                Boleh lebih dari satu. Tekan Enter setelah memindai.
            </p>
            <div aria-live="polite">
                {pesanGalat ? (
                    <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                        {pesanGalat}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
