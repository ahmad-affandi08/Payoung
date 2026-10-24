import { SearchIcon, XIcon } from 'lucide-react';
import { useId, useState } from 'react';

import { PanelRentangTanggal, RingkasRentang } from '@/Komponen/Tanggal/PemilihRentangTanggal';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { Label } from '@/Komponen/Ui/label';
import { Switch } from '@/Komponen/Ui/switch';
import { cn } from '@/Komponen/Ui/utils';

import type { DefinisiSaring } from './Tipe';
import { CocokkanCari } from '@/Komponen/Formulir/PilihanCari';

export { BuatPresetTanggal, PecahRentang } from '@/Pustaka/Tanggal';

/** Teks ringkas nilai saring untuk chip & tombol. */
export function RingkasSaring(definisi: DefinisiSaring, nilai: string): string {
    if (definisi.jenis === 'ya') {
        return definisi.labelAktif ?? definisi.label;
    }

    if (definisi.jenis === 'rentangTanggal') {
        return RingkasRentang(nilai);
    }

    const label = new Map((definisi.opsi ?? []).map((o) => [o.nilai, o.label]));

    return nilai
        .split(',')
        .map((n) => label.get(n) ?? n)
        .join(', ');
}

type PropsPenyunting = { definisi: DefinisiSaring; nilai: string; saatBerubah: (nilai: string) => void };

/** Isi penyunting satu saring; dipakai di Popover (desktop) dan Sheet "Saring" (HP). */
export function PenyuntingSaring({ definisi, nilai: nilaiUrl, saatBerubah: TulisUrl }: PropsPenyunting) {
    const id = useId();
    const bawaan = definisi.nilaiBawaan ?? '';
    const nilai = nilaiUrl === '' ? bawaan : nilaiUrl;
    const SaatBerubah = (baru: string) => TulisUrl(baru === bawaan ? '' : baru);

    if (definisi.jenis === 'ya') {
        return (
            <div className="flex min-h-11 items-center justify-between gap-3">
                <Label htmlFor={id} className="text-isi text-teks-utama">
                    {definisi.labelAktif ?? definisi.label}
                </Label>
                <Switch id={id} checked={nilai === '1'} onCheckedChange={(aktif) => SaatBerubah(aktif ? '1' : '')} />
            </div>
        );
    }

    if (definisi.jenis === 'rentangTanggal') {
        return <PanelRentangTanggal label={definisi.label} nilai={nilai} saatBerubah={SaatBerubah} />;
    }

    return <DaftarOpsiSaring definisi={definisi} nilai={nilai} saatBerubah={SaatBerubah} />;
}

/** Daftar opsi saring (tunggal/banyak) dengan kotak cari di atasnya. */
function DaftarOpsiSaring({ definisi, nilai, saatBerubah: SaatBerubah }: PropsPenyunting) {
    const id = useId();
    const [kata, AturKata] = useState('');
    const terpilih = new Set(nilai === '' ? [] : nilai.split(','));
    const banyak = definisi.jenis === 'pilihanBanyak';
    const semua = definisi.opsi ?? [];
    const tampil = semua.filter((o) => CocokkanCari({ Nilai: o.nilai, Label: o.label }, kata));
    const Ganti = (opsi: string, aktif: boolean) => {
        if (!banyak) {
            SaatBerubah(aktif ? opsi : '');

            return;
        }

        const baru = new Set(terpilih);

        if (aktif) {
            baru.add(opsi);
        } else {
            baru.delete(opsi);
        }

        // Urutan mengikuti daftar opsi agar URL stabil.
        SaatBerubah(
            semua
                .map((o) => o.nilai)
                .filter((n) => baru.has(n))
                .join(','),
        );
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex h-8 pointer-coarse:h-11 items-center gap-2 rounded-kontrol border border-garis-input bg-permukaan px-3 focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/40">
                <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                <input
                    value={kata}
                    onChange={(peristiwa) => AturKata(peristiwa.target.value)}
                    placeholder={`Cari ${definisi.label.toLowerCase()}…`}
                    aria-label={`Cari opsi ${definisi.label}`}
                    autoComplete="off"
                    className="h-full w-full bg-transparent text-isi text-teks-utama outline-none placeholder:text-teks-sekunder"
                />
            </div>
            <fieldset className="flex max-h-72 flex-col gap-1 overflow-y-auto">
                <legend className="sr-only">{definisi.label}</legend>
                {tampil.length === 0 ? (
                    <p className="px-1 py-3 text-center text-label text-teks-sekunder">
                        Tidak ada yang cocok dengan “{kata}”.
                    </p>
                ) : null}
                {tampil.map((opsi) => {
                    const idOpsi = `${id}-${opsi.nilai}`;

                    return (
                        <div
                            key={opsi.nilai}
                            className="flex min-h-10 items-center gap-2 rounded-kontrol px-1 hover:bg-brand-lembut"
                        >
                            <Checkbox
                                id={idOpsi}
                                checked={terpilih.has(opsi.nilai)}
                                onCheckedChange={(aktif) => Ganti(opsi.nilai, aktif === true)}
                                className={cn(!banyak && 'rounded-full')}
                            />
                            <Label htmlFor={idOpsi} className="flex-1 text-isi font-normal text-teks-utama">
                                {opsi.label}
                            </Label>
                        </div>
                    );
                })}
            </fieldset>
        </div>
    );
}

type PropsChip = { definisi: DefinisiSaring; nilai: string; saatHapus: () => void };

/** Chip saring aktif dengan tombol hapus. */
export function ChipSaring({ definisi, nilai, saatHapus }: PropsChip) {
    const ringkas = RingkasSaring(definisi, nilai);
    const teks = definisi.jenis === 'ya' ? ringkas : `${definisi.label}: ${ringkas}`;

    return (
        <span className="inline-flex max-w-full items-center gap-1 rounded-kontrol border border-garis bg-brand-lembut py-0.5 pr-0.5 pl-2 text-label text-teks-utama">
            <span className="truncate">{teks}</span>
            <button
                type="button"
                onClick={saatHapus}
                aria-label={`Hapus filter ${teks}`}
                className="inline-flex size-7 shrink-0 items-center justify-center rounded-kontrol text-teks-sekunder hover:bg-brand-lembut-sorot hover:text-teks-utama focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
            >
                <XIcon aria-hidden="true" className="size-4" />
            </button>
        </span>
    );
}
