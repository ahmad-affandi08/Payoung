import type { ReactNode } from 'react';

import { Field, FieldDescription, FieldLabel } from '@/Komponen/Ui/field';
import { cn } from '@/Komponen/Ui/utils';

/*
 * Kerangka bersama bidang formulir di atas komponen shadcn/ui `Field`. Dipakai BidangTeks, BidangUang, dst. agar
 * label, keterangan, dan galat seragam dan terhubung ke aria (PRD §17.6). Galat sengaja bukan `FieldError`
 * (role="alert"): pesan per isian diumumkan lewat aria-describedby dan RingkasanGalatFormulir, bukan satu per satu.
 */

/** Kelas kontrol bersama (tinggi, ukuran teks token, tepi galat). */
export function BuatKelasKontrol(galat: unknown, tambahan?: string): string {
    return cn(
        'h-8 pointer-coarse:h-11 bg-permukaan text-isi text-teks-utama disabled:bg-latar disabled:text-teks-sekunder disabled:opacity-100',
        galat ? 'border-bahaya' : 'border-garis-input',
        tambahan,
    );
}

/** Gabungkan id keterangan/galat yang ada menjadi nilai aria-describedby (undefined bila kosong). */
export function GabungDijelaskanOleh(...id: (string | null | false | undefined)[]): string | undefined {
    const hasil = id.filter(Boolean).join(' ');

    return hasil || undefined;
}

export function KerangkaBidang({
    galat,
    className,
    children,
}: {
    galat?: unknown;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Field data-invalid={galat ? true : undefined} className={cn('gap-1', className)}>
            {children}
        </Field>
    );
}

export function LabelBidang({
    htmlFor,
    tersembunyi = false,
    children,
}: {
    htmlFor: string;
    tersembunyi?: boolean;
    children: ReactNode;
}) {
    return (
        <FieldLabel
            htmlFor={htmlFor}
            // `max-w-px`: di dalam `Field` aturan `[&>.sr-only]:w-auto` membuat label tersembunyi selebar teksnya, dan lebar itu
            // memperluas area gulir tabel dan kartu walau tak terlihat.
            className={tersembunyi ? 'max-w-px sr-only' : 'text-label leading-[inherit] font-semibold text-teks-utama'}
        >
            {children}
        </FieldLabel>
    );
}

export function KeteranganBidang({ id, children }: { id?: string; children: ReactNode }) {
    return (
        <FieldDescription id={id} className="m-0 text-keterangan text-teks-sekunder nth-last-2:mt-0">
            {children}
        </FieldDescription>
    );
}

export function GalatBidang({ id, children }: { id?: string; children: ReactNode }) {
    return (
        <p id={id} data-slot="field-error" className="text-keterangan font-semibold text-bahaya">
            {children}
        </p>
    );
}
