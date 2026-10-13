import { usePage } from '@inertiajs/react';

import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * Unit servis (§9.10, §9.6, D-142): `Kendaraan` (plat, KM) atau `Perangkat` (HP, laptop; IMEI / nomor seri boleh kosong).
 * Kata yang tampil mengikuti jenis unit supaya bengkel tidak melihat "perangkat" dan toko gadget tidak melihat "plat".
 */
export type JenisUnit = 'Kendaraan' | 'Perangkat';

type UnitRingkas = { Jenis: JenisUnit; NomorPolisi: string | null; Label: string };

export const LabelJenisUnit: Record<JenisUnit, string> = {
    Kendaraan: 'Kendaraan',
    Perangkat: 'Perangkat elektronik',
};

/** "Kendaraan" / "Perangkat" untuk judul, tombol, dan keterangan. */
export function KataUnit(jenis: JenisUnit | null): string {
    return jenis === 'Kendaraan' ? 'Kendaraan' : jenis === 'Perangkat' ? 'Perangkat' : 'Unit servis';
}

export function LabelPengenal(jenis: JenisUnit): string {
    return jenis === 'Perangkat' ? 'IMEI / nomor seri' : 'Nomor polisi';
}

/** Teks utama baris: plat untuk kendaraan, "Merek Tipe" untuk perangkat. */
export function JudulUnit(unit: UnitRingkas | null): string {
    if (unit === null) {
        return '-';
    }

    return unit.Jenis === 'Perangkat' ? unit.Label : (unit.NomorPolisi ?? '-');
}

/** Teks pendamping: merek/tipe untuk kendaraan, IMEI / nomor seri (bila ada) untuk perangkat. */
export function RincianUnit(unit: UnitRingkas | null): string {
    if (unit === null) {
        return '';
    }

    return unit.Jenis === 'Perangkat' ? (unit.NomorPolisi ?? '') : unit.Label;
}

/**
 * Jenis unit yang lazim bagi tenant ini menurut sektor outlet: bengkel (SVC-WRK) = Kendaraan, toko elektronik
 * (RTL-ELC) = Perangkat, keduanya atau sektor belum diketahui = null (pilihan ditampilkan).
 */
export function JenisUnitBawaan(sektorOutlet: string[]): JenisUnit | null {
    const bengkel = sektorOutlet.some((k) => k === 'SVC-WRK');
    const elektronik = sektorOutlet.some((k) => k === 'RTL-ELC');

    if (bengkel && !elektronik) {
        return 'Kendaraan';
    }

    return elektronik && !bengkel ? 'Perangkat' : null;
}

export function PakaiJenisUnitBawaan(): JenisUnit | null {
    const { props } = usePage<PropsBersamaAplikasi>();

    return JenisUnitBawaan(props.SektorOutlet ?? []);
}
