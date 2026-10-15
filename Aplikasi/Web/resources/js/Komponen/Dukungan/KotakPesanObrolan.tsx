import { useId, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { Textarea } from '@/Komponen/Ui/textarea';
import { CekBisaKirim } from '@/Pustaka/Obrolan';

type PropsKotakPesanObrolan = {
    maksimal: number;
    memproses: boolean;
    nonaktif?: boolean;
    placeholder?: string;
    labelKirim?: string;
    /** Mengembalikan true bila pesan terkirim, supaya kotaknya dikosongkan; pada galat isinya dipertahankan. */
    saatKirim: (isi: string) => Promise<boolean>;
};

/**
 * Kotak tulis pesan obrolan (PLT-12): Enter mengirim, Shift+Enter baris baru. Pesan yang gagal terkirim tidak hilang
 * dari kotak. Dipakai panel tenant dan inbox petugas.
 */
export default function KotakPesanObrolan({
    maksimal,
    memproses,
    nonaktif = false,
    placeholder = 'Tulis pesan...',
    labelKirim = 'Kirim pesan',
    saatKirim,
}: PropsKotakPesanObrolan) {
    const id = useId();
    const [isi, AturIsi] = useState('');
    const bisaKirim = !nonaktif && !memproses && CekBisaKirim(isi, maksimal);
    const sisa = maksimal - isi.length;

    const Kirim = async () => {
        if (!bisaKirim) {
            return;
        }

        if (await saatKirim(isi.trim())) {
            AturIsi('');
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="sr-only">
                Pesan obrolan
            </label>
            <Textarea
                id={id}
                value={isi}
                rows={3}
                maxLength={maksimal}
                disabled={nonaktif}
                placeholder={placeholder}
                onChange={(peristiwa) => AturIsi(peristiwa.target.value)}
                onKeyDown={(peristiwa) => {
                    if (peristiwa.key === 'Enter' && !peristiwa.shiftKey && !peristiwa.nativeEvent.isComposing) {
                        peristiwa.preventDefault();
                        void Kirim();
                    }
                }}
                className="h-auto py-2 field-sizing-fixed"
            />
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-keterangan text-teks-sekunder">
                    Enter untuk kirim, Shift+Enter untuk baris baru
                    {sisa <= 200 ? ` | sisa ${String(sisa)} karakter` : ''}
                </span>
                <div className="ml-auto">
                    <Tombol onClick={() => void Kirim()} disabled={!bisaKirim} memproses={memproses}>
                        {labelKirim}
                    </Tombol>
                </div>
            </div>
        </div>
    );
}
