import { Fragment, type ReactNode } from 'react';

import { BuatIdJudul } from '@/Pustaka/DaftarIsiTeks';

/** Hanya tautan aman yang dirender sebagai `<a>` (tanpa javascript:, data:, dst.). */
function CekTautanAman(url: string): boolean {
    return /^(https:\/\/|\/(?!\/)|#|mailto:|tel:)/.test(url);
}

/** Inline: **tebal** dan [teks](tautan). Teks lain dirender apa adanya (React meng-escape HTML). */
function RenderInline(teks: string, kunci: string): ReactNode[] {
    const hasil: ReactNode[] = [];
    const pola = /\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;
    let akhir = 0;
    let cocok: RegExpExecArray | null;
    let i = 0;

    while ((cocok = pola.exec(teks)) !== null) {
        if (cocok.index > akhir) {
            hasil.push(teks.slice(akhir, cocok.index));
        }

        if (cocok[1] !== undefined) {
            hasil.push(<strong key={`${kunci}-b${i}`}>{cocok[1]}</strong>);
        } else if (cocok[2] !== undefined && cocok[3] !== undefined) {
            hasil.push(
                CekTautanAman(cocok[3]) ? (
                    <a key={`${kunci}-a${i}`} href={cocok[3]} className="font-semibold text-brand underline">
                        {cocok[2]}
                    </a>
                ) : (
                    cocok[2]
                ),
            );
        }

        akhir = cocok.index + cocok[0].length;
        i += 1;
    }

    if (akhir < teks.length) {
        hasil.push(teks.slice(akhir));
    }

    return hasil;
}

type JenisBlok = 'p' | 'ul' | 'ol' | 'h2' | 'h3' | 'h4';
type Blok = { jenis: JenisBlok; baris: string[] };

function AmbilJenisBaris(baris: string): JenisBlok {
    if (baris.startsWith('### ')) return 'h4';
    if (baris.startsWith('## ')) return 'h3';
    if (baris.startsWith('# ')) return 'h2';
    if (baris.trimStart().startsWith('- ')) return 'ul';
    if (/^\s*\d+\.\s/.test(baris)) return 'ol';

    return 'p';
}

/**
 * Menguraikan teks menjadi blok per baris. Baris kosong memisahkan blok; selain itu judul selalu berdiri sendiri
 * dan daftar/paragraf berhenti di baris yang jenisnya berbeda, jadi judul atau daftar yang ditulis tanpa baris kosong
 * di sekitarnya tetap terbaca sebagai judul/daftar (sebelumnya tampil sebagai teks mentah "## ...").
 */
function UraiBlok(teks: string): Blok[] {
    const hasil: Blok[] = [];
    let kini: Blok | null = null;

    for (const mentah of teks.split('\n')) {
        if (mentah.trim() === '') {
            kini = null;
            continue;
        }

        const jenis = AmbilJenisBaris(mentah);
        const judul = jenis === 'h2' || jenis === 'h3' || jenis === 'h4';

        if (kini === null || judul || kini.jenis !== jenis) {
            kini = { jenis, baris: [] };
            hasil.push(kini);
        }

        kini.baris.push(mentah);

        if (judul) {
            kini = null;
        }
    }

    return hasil;
}

/**
 * Teks bebas tanpa HTML (D-21, dipakai juga dokumen legal D-28): paragraf dipisah baris kosong, `# `/`## `/`### `
 * judul bertingkat, `- ` butir daftar, `1. ` daftar bernomor, baris baru di dalam paragraf dipertahankan,
 * **tebal**, dan [tautan](url).
 *
 * Tidak ada HTML yang dirender sama sekali — React meng-escape isinya, dan tautan disaring `CekTautanAman` — jadi
 * isi yang ditulis pengelola tidak bisa menyuntikkan skrip ke halaman publik.
 */
export default function TeksKaya({
    teks,
    className,
    jangkar = false,
}: {
    teks: string;
    className?: string;
    /** Beri id pada judul `# ` dan `## ` supaya bisa ditautkan dari daftar isi (dokumen legal). */
    jangkar?: boolean;
}) {
    const blok = UraiBlok(teks);

    return (
        <div className={`flex flex-col gap-4 ${className ?? ''}`}>
            {blok.map((b, i) => {
                const { baris } = b;

                if (b.jenis === 'ul') {
                    return (
                        <ul key={i} className="flex list-disc flex-col gap-1 pl-5">
                            {baris.map((l, j) => (
                                <li key={j}>{RenderInline(l.trimStart().slice(2), `${i}-${j}`)}</li>
                            ))}
                        </ul>
                    );
                }

                // Daftar bernomor: nomor awalnya dipakai apa adanya supaya pasal yang dikutip sebagian tetap bernomor benar.
                if (b.jenis === 'ol') {
                    const mulai = Number(/^\s*(\d+)\./.exec(baris[0] ?? '')?.[1] ?? 1);

                    return (
                        <ol key={i} start={mulai} className="flex list-decimal flex-col gap-1 pl-5">
                            {baris.map((l, j) => (
                                <li key={j}>{RenderInline(l.replace(/^\s*\d+\.\s/, ''), `${i}-${j}`)}</li>
                            ))}
                        </ol>
                    );
                }

                // Judul bertingkat. `## ` tetap `h3` seperti sebelumnya supaya artikel blog yang sudah terbit tidak
                // berubah tampilannya; `# ` dan `### ` menambah tingkat di atas & di bawahnya.
                if (b.jenis === 'h4') {
                    return (
                        <h4 key={i} className="text-subjudul font-semibold text-teks-utama">
                            {(baris[0] ?? '').slice(4)}
                        </h4>
                    );
                }

                if (b.jenis === 'h3') {
                    const isi = (baris[0] ?? '').slice(3);

                    return (
                        <h3
                            key={i}
                            {...(jangkar ? { id: BuatIdJudul(isi) } : {})}
                            className="scroll-mt-24 text-judul font-bold text-teks-utama"
                        >
                            {isi}
                        </h3>
                    );
                }

                if (b.jenis === 'h2') {
                    const isi = (baris[0] ?? '').slice(2);

                    return (
                        <h2
                            key={i}
                            {...(jangkar ? { id: BuatIdJudul(isi) } : {})}
                            className="scroll-mt-24 text-tampilan font-bold text-teks-utama"
                        >
                            {isi}
                        </h2>
                    );
                }

                return (
                    <p key={i}>
                        {baris.map((l, j) => (
                            <Fragment key={j}>
                                {j > 0 ? <br /> : null}
                                {RenderInline(l, `${i}-${j}`)}
                            </Fragment>
                        ))}
                    </p>
                );
            })}
        </div>
    );
}
