import { useId, useState } from 'react';

import { Label } from '@/Komponen/Ui/label';
import TombolPindaiNomorSeri from './TombolPindaiNomorSeri';
import { Textarea } from '@/Komponen/Ui/textarea';
import { cn } from '@/Komponen/Ui/utils';

/** Panjang maksimal satu nomor seri (DesainF05a D: `Baris.*.NomorSeri.*` 1–100 karakter). */
export const PanjangMaksimalNomorSeri = 100;

/** Teks tempelan/pindaian → daftar nomor seri: satu per baris (tab dari tempelan Excel juga pemisah), dipangkas, tanpa baris kosong. */
export function UraiNomorSeri(teks: string): string[] {
    return teks
        .split(/[\r\n\t]+/)
        .map((nomor) => nomor.trim())
        .filter((nomor) => nomor !== '');
}

/** Nomor yang muncul lebih dari sekali di satu baris (BR: nomor seri unik per produk). */
export function CariNomorSeriGanda(daftar: readonly string[]): string[] {
    const terlihat = new Set<string>();
    const ganda = new Set<string>();

    daftar.forEach((nomor) => {
        if (terlihat.has(nomor)) {
            ganda.add(nomor);
        }

        terlihat.add(nomor);
    });

    return [...ganda];
}

/** Galat lokal daftar nomor seri; server tetap memeriksa ulang (PemvalidasiPelacakan). */
export function PeriksaNomorSeri(daftar: readonly string[], maksimal: number): string | null {
    if (daftar.length === 0) {
        return 'Isi minimal satu nomor seri.';
    }

    if (daftar.length > maksimal) {
        return `Paling banyak ${maksimal.toLocaleString('id-ID')} nomor seri per baris.`;
    }

    const terlaluPanjang = daftar.find((nomor) => nomor.length > PanjangMaksimalNomorSeri);

    if (terlaluPanjang !== undefined) {
        return `Nomor seri paling panjang ${String(PanjangMaksimalNomorSeri)} karakter.`;
    }

    const ganda = CariNomorSeriGanda(daftar);

    return ganda.length > 0 ? `Nomor seri ganda: ${ganda.slice(0, 5).join(', ')}.` : null;
}

type PropsBidangNomorSeri = {
    label: string;
    nilai: string[];
    saatBerubah: (nilai: string[]) => void;
    maksimal: number;
    galat?: string | undefined;
    disabled?: boolean;
};

/**
 * Isian nomor seri (DesainF05a E): tempel atau pindai, satu nomor per baris. Jumlahnya ditampilkan dan menjadi jumlah
 * stok baris ini. Teks ketikan dipertahankan apa adanya selama fokus; daftar yang dikirim sudah dipangkas.
 */
export default function BidangNomorSeri({
    label,
    nilai,
    saatBerubah,
    maksimal,
    galat,
    disabled,
}: PropsBidangNomorSeri) {
    const id = useId();
    const [teks, AturTeks] = useState(() => nilai.join('\n'));
    const jumlah = nilai.length;

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                {label}
            </Label>
            <Textarea
                id={id}
                value={teks}
                rows={4}
                disabled={disabled}
                spellCheck={false}
                onChange={(peristiwa) => {
                    AturTeks(peristiwa.target.value);
                    saatBerubah(UraiNomorSeri(peristiwa.target.value));
                }}
                onBlur={() => AturTeks(nilai.join('\n'))}
                aria-invalid={galat ? true : undefined}
                aria-describedby={[`${id}-jumlah`, galat ? `${id}-galat` : null].filter(Boolean).join(' ')}
                className={cn(
                    'h-auto bg-permukaan py-2 font-mono text-isi text-teks-utama field-sizing-fixed',
                    galat ? 'border-bahaya' : 'border-garis-input',
                )}
            />
            <div>
                <TombolPindaiNomorSeri
                    label={`Pindai nomor seri dengan kamera ${label}`}
                    daftar={nilai}
                    maksimal={maksimal}
                    disabled={disabled === true}
                    saatBerubah={(daftar) => {
                        AturTeks(daftar.join('\n'));
                        saatBerubah(daftar);
                    }}
                />
            </div>
            <p id={`${id}-jumlah`} aria-live="polite" className="text-keterangan text-teks-sekunder tabular-nums">
                {jumlah.toLocaleString('id-ID')} nomor seri (maksimal {maksimal.toLocaleString('id-ID')}). Satu nomor
                per baris; bisa ditempel dari Excel atau dipindai.
            </p>
            {galat ? (
                <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                    {galat}
                </p>
            ) : null}
        </div>
    );
}
