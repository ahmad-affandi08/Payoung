import { MapPinIcon, ScanFaceIcon, TriangleAlertIcon, WifiIcon, WifiOffIcon } from 'lucide-react';

import type { StatusMesin } from '@/Fitur/Absensi/MesinWajah';

/** Teks penanda kesiapan pemindai wajah untuk karyawan. */
function TeksMesin(mesin: StatusMesin): string {
    switch (mesin.Tahap) {
        case 'siap':
            return 'Pemindai wajah siap';
        case 'galat':
            return 'Pemindai gagal dimuat';
        case 'belum':
            return 'Pemindai wajah dicek';
        default:
            return `Menyiapkan pemindai ${String(mesin.Persen)}%`;
    }
}

/**
 * Kesiapan perangkat (internet, lokasi, pemindai wajah) dan tiga langkah absen. Berada di lembar bawah halaman Absen
 * (D-337), di bawah tombol utama, jadi tombolnya tidak terdorong ke bawah layar.
 */
export default function PanelKesiapanAbsen({ daring, mesin }: { daring: boolean; mesin: StatusMesin }) {
    return (
        <section className="flex flex-col gap-3" aria-label="Kesiapan absen">
            <ul className="grid grid-cols-3 gap-2 text-center text-keterangan text-teks-sekunder">
                <li className="flex flex-col items-center gap-1 rounded-panel border border-garis bg-permukaan px-2 py-2.5">
                    {daring ? (
                        <WifiIcon aria-hidden="true" className="size-5 text-sukses" />
                    ) : (
                        <WifiOffIcon aria-hidden="true" className="size-5 text-bahaya" />
                    )}
                    {daring ? 'Internet siap' : 'Tanpa internet'}
                </li>
                <li className="flex flex-col items-center gap-1 rounded-panel border border-garis bg-permukaan px-2 py-2.5">
                    <MapPinIcon aria-hidden="true" className="size-5 text-brand" />
                    Lokasi dicatat
                </li>
                <li
                    className="flex flex-col items-center gap-1 rounded-panel border border-garis bg-permukaan px-2 py-2.5"
                    role="status"
                >
                    {mesin.Tahap === 'galat' ? (
                        <TriangleAlertIcon aria-hidden="true" className="size-5 text-bahaya" />
                    ) : (
                        <ScanFaceIcon
                            aria-hidden="true"
                            className={`size-5 ${mesin.Tahap === 'siap' ? 'text-sukses' : 'text-brand'}`}
                        />
                    )}
                    {TeksMesin(mesin)}
                </li>
            </ul>
            <ol className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-3 text-keterangan text-teks-sekunder">
                {[
                    'Ketuk Absen masuk atau geser tombol Absen pulang, lalu izinkan kamera dan lokasi bila diminta.',
                    'Hadapkan wajah ke lingkaran. Hijau berarti wajah cocok, merah berarti tidak cocok.',
                    'Saat lingkaran hijau, ketuk Ambil foto. Absen langsung tercatat.',
                ].map((teks, indeks) => (
                    <li key={teks} className="flex items-start gap-2">
                        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-brand-lembut text-keterangan font-semibold text-brand">
                            {indeks + 1}
                        </span>
                        {teks}
                    </li>
                ))}
            </ol>
        </section>
    );
}
