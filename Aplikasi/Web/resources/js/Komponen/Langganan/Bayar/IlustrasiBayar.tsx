export type JenisIlustrasiBayar = 'berhasil' | 'memeriksa' | 'kedaluwarsa' | 'tidakPasti' | 'gagal';

type PropsIlustrasiBayar = {
    jenis: JenisIlustrasiBayar;
    /** Sisi gambar dalam piksel. */
    ukuran?: number;
};

/** Titik percik di sekeliling centang (sudut dalam derajat), semuanya dihitung dari pusat 60,60. */
const sudutPercik = [20, 65, 110, 155, 200, 245, 290, 335];

/**
 * Ilustrasi hasil pembayaran dengan gerak SVG (D-305). Gambar hanya penjelas: arti hasilnya selalu ada di teks di
 * sebelahnya, jadi gambar disembunyikan dari pembaca layar. Kelas dan keyframe ada di `Gaya/Bayar.css`, warnanya
 * dari token, dan pengguna yang mematikan animasi langsung melihat gambar akhirnya.
 */
export default function IlustrasiBayar({ jenis, ukuran = 112 }: PropsIlustrasiBayar) {
    return (
        <svg
            viewBox="0 0 120 120"
            width={ukuran}
            height={ukuran}
            aria-hidden="true"
            focusable="false"
            data-ilustrasi={jenis}
            className={`ilustrasi-bayar ilustrasi-bayar--${jenis}`}
            fill="none"
            strokeWidth="5"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            {jenis === 'berhasil' ? <Berhasil /> : null}
            {jenis === 'memeriksa' ? <Memeriksa /> : null}
            {jenis === 'kedaluwarsa' ? <Kedaluwarsa /> : null}
            {jenis === 'tidakPasti' ? <TidakPasti /> : null}
            {jenis === 'gagal' ? <Gagal /> : null}
        </svg>
    );
}

function Berhasil() {
    return (
        <>
            <circle className="ib-cincin" cx="60" cy="60" r="50" strokeWidth="2" />
            <circle className="ib-alas" cx="60" cy="60" r="46" stroke="none" />
            <circle data-gambar className="ib-lingkaran" cx="60" cy="60" r="46" transform="rotate(-90 60 60)" />
            <path data-gambar className="ib-centang" d="M40 62 55 76 82 46" strokeWidth="7" />
            {sudutPercik.map((sudut) => (
                <circle
                    key={sudut}
                    className="ib-percik"
                    cx={60 + 56 * Math.cos((sudut * Math.PI) / 180)}
                    cy={60 + 56 * Math.sin((sudut * Math.PI) / 180)}
                    r="3"
                    stroke="none"
                />
            ))}
        </>
    );
}

function Memeriksa() {
    return (
        <>
            <circle className="ib-alas" cx="60" cy="60" r="46" stroke="none" />
            <circle className="ib-jalur" cx="60" cy="60" r="40" />
            <circle className="ib-busur" cx="60" cy="60" r="40" />
            <circle className="ib-titik" cx="60" cy="60" r="8" stroke="none" />
        </>
    );
}

function Kedaluwarsa() {
    return (
        <>
            <circle className="ib-alas" cx="60" cy="60" r="46" stroke="none" />
            <circle data-gambar className="ib-lingkaran" cx="60" cy="60" r="46" transform="rotate(-90 60 60)" />
            <path className="ib-jarum-pendek" d="M60 60V34" strokeWidth="6" />
            <path className="ib-jarum" d="M60 60 82 60" strokeWidth="6" />
            <circle className="ib-porosi" cx="60" cy="60" r="5" stroke="none" />
        </>
    );
}

function TidakPasti() {
    return (
        <>
            <circle className="ib-alas" cx="60" cy="60" r="46" stroke="none" />
            <g className="ib-panah">
                <path d="M84 52A26 26 0 0 0 36 46" />
                <path d="M36 32V47H51" />
                <path d="M36 68A26 26 0 0 0 84 74" />
                <path d="M84 88V73H69" />
            </g>
            <circle className="ib-titik" cx="60" cy="60" r="4" stroke="none" />
        </>
    );
}

function Gagal() {
    return (
        <>
            <circle className="ib-alas" cx="60" cy="60" r="46" stroke="none" />
            <circle data-gambar className="ib-lingkaran" cx="60" cy="60" r="46" transform="rotate(-90 60 60)" />
            <g className="ib-kelompok">
                <path data-gambar className="ib-silang" d="M46 46 74 74" strokeWidth="7" />
                <path data-gambar className="ib-silang" d="M74 46 46 74" strokeWidth="7" />
            </g>
        </>
    );
}
