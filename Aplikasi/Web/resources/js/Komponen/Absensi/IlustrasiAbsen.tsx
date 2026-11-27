import type { ReactNode } from 'react';

export type KeadaanIlustrasiAbsen = 'belum' | 'bekerja' | 'selesai' | 'wajah';

/** Ilustrasi gerai: tenda bergaris, meja jual, dan hasil dagangan. Selalu sama, hanya langit di atasnya yang berganti. */
function Gerai({ tutup }: { tutup: boolean }) {
    const garis = [0, 1, 2, 3, 4, 5];

    return (
        <g>
            <rect x="96" y="66" width="5" height="52" className="fill-brand-gelap-gulir" />
            <rect x="259" y="66" width="5" height="52" className="fill-brand-gelap-gulir" />
            <rect x="84" y="38" width="192" height="8" rx="3" className="fill-brand" />
            {garis.map((i) => (
                <g key={i}>
                    <rect
                        x={90 + i * 30}
                        y="44"
                        width="30"
                        height="22"
                        className={i % 2 === 0 ? 'fill-aksen' : 'fill-permukaan'}
                    />
                    <circle
                        cx={105 + i * 30}
                        cy="66"
                        r="15"
                        className={i % 2 === 0 ? 'fill-aksen' : 'fill-permukaan'}
                    />
                </g>
            ))}
            <rect x="92" y="92" width="176" height="6" rx="3" className="fill-brand-gelap-gulir" />
            <rect x="96" y="98" width="168" height="20" className="fill-brand" />
            {tutup ? (
                <rect x="96" y="70" width="168" height="26" rx="2" className="fill-brand-gelap-garis" />
            ) : (
                <g>
                    <circle cx="122" cy="85" r="7" className="fill-sukses" />
                    <circle cx="137" cy="85" r="7" className="fill-sukses" />
                    <circle cx="152" cy="85" r="7" className="fill-sukses" />
                    <circle cx="178" cy="85" r="7" className="fill-aksen" />
                    <circle cx="193" cy="85" r="7" className="fill-aksen" />
                    <circle cx="219" cy="85" r="7" className="fill-bahaya" />
                    <circle cx="234" cy="85" r="7" className="fill-bahaya" />
                </g>
            )}
        </g>
    );
}

function Bintang({ x, y }: { x: number; y: number }) {
    return <circle cx={x} cy={y} r="1.6" className="fill-brand-gelap-teks" />;
}

function Bulan({ x, y, r }: { x: number; y: number; r: number }) {
    return (
        <g>
            <circle cx={x} cy={y} r={r} className="fill-brand-gelap-teks" />
            <circle cx={x + r * 0.45} cy={y - r * 0.2} r={r * 0.9} className="fill-brand-gelap" />
        </g>
    );
}

function Matahari({ x, y, r }: { x: number; y: number; r: number }) {
    const sinar = [0, 45, 90, 135, 180, 225, 270, 315];

    return (
        <g>
            {sinar.map((sudut) => (
                <line
                    key={sudut}
                    x1={x}
                    y1={y - r - 6}
                    x2={x}
                    y2={y - r - 14}
                    strokeWidth="3"
                    strokeLinecap="round"
                    className="stroke-aksen/60"
                    transform={`rotate(${String(sudut)} ${String(x)} ${String(y)})`}
                />
            ))}
            <circle cx={x} cy={y} r={r} className="fill-aksen" />
        </g>
    );
}

function Awan({ x, y }: { x: number; y: number }) {
    return (
        <g className="fill-brand-gelap-sorot">
            <circle cx={x} cy={y} r="9" />
            <circle cx={x + 12} cy={y - 5} r="11" />
            <circle cx={x + 26} cy={y} r="9" />
            <rect x={x - 9} y={y} width="44" height="9" rx="4.5" />
        </g>
    );
}

function Pemindai() {
    const sudut = 'fill-none stroke-aksen';

    return (
        <g strokeWidth="4" strokeLinecap="round" strokeLinejoin="round">
            <path d="M138 36 V22 H152" className={sudut} />
            <path d="M208 22 H222 V36" className={sudut} />
            <path d="M222 100 V114 H208" className={sudut} />
            <path d="M152 114 H138 V100" className={sudut} />
            <ellipse cx="180" cy="68" rx="22" ry="27" strokeWidth="3" className="fill-none stroke-permukaan" />
            <circle cx="171" cy="62" r="2.6" className="fill-permukaan" stroke="none" />
            <circle cx="189" cy="62" r="2.6" className="fill-permukaan" stroke="none" />
            <path d="M171 78 Q180 86 189 78" strokeWidth="3" className="fill-none stroke-permukaan" />
            <line x1="132" y1="68" x2="228" y2="68" strokeWidth="2.5" className="stroke-sukses" />
        </g>
    );
}

/**
 * Ilustrasi merek halaman Absen (D-337): pemandangan gerai yang berganti menurut keadaan karyawan. Dini hari sebelum
 * masuk (bulan, bintang, matahari terbit), siang saat bekerja (matahari tinggi, awan), malam setelah pulang (gerai
 * tutup, bulan), dan bingkai pemindai saat wajah belum didaftarkan. Murni hiasan penjelas: semua teks keadaan sudah
 * ada di layar, jadi disembunyikan dari pembaca layar. Warna hanya dari token, tanpa gradien.
 */
export default function IlustrasiAbsen({ keadaan }: { keadaan: KeadaanIlustrasiAbsen }) {
    let langit: ReactNode;

    switch (keadaan) {
        case 'bekerja':
            langit = (
                <>
                    <Matahari x={300} y={50} r={17} />
                    <Awan x={40} y={32} />
                    <Awan x={316} y={96} />
                </>
            );
            break;
        case 'selesai':
            langit = (
                <>
                    <Bulan x={304} y={42} r={17} />
                    <Bintang x={40} y={28} />
                    <Bintang x={70} y={60} />
                    <Bintang x={334} y={80} />
                    <Bintang x={20} y={84} />
                    <circle cx="180" cy="30" r="5" className="fill-aksen" />
                </>
            );
            break;
        case 'wajah':
            langit = null;
            break;
        default:
            langit = (
                <>
                    <Matahari x={306} y={112} r={19} />
                    <Bulan x={46} y={36} r={11} />
                    <Bintang x={90} y={24} />
                    <Bintang x={24} y={74} />
                    <Bintang x={64} y={56} />
                    <Bintang x={338} y={40} />
                </>
            );
    }

    return (
        <svg viewBox="0 0 360 140" className="h-auto w-full" aria-hidden="true" focusable="false">
            {keadaan === 'wajah' ? (
                <Pemindai />
            ) : (
                <>
                    {langit}
                    <rect x="0" y="118" width="360" height="22" className="fill-brand-gelap-sorot" />
                    <Gerai tutup={keadaan === 'selesai'} />
                </>
            )}
        </svg>
    );
}
