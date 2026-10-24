import TombolEkspor from '@/Komponen/Laporan/TombolEkspor';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';

/** Batas pilihan agar alamat unduhan tetap pendek (Uuid dikirim di query). */
export const MaksEksporTerpilih = 200;

/**
 * Ekspor hanya baris yang dicentang (Excel, CSV, atau cetak). Server menerima `uuid=a,b,c` di alamat ekspor yang sama
 * dengan ekspor sesuai saringan; baris yang tidak dicentang tidak ikut.
 */
export default function AksiMassalEkspor<T>({
    konteks,
    alamat,
    ambilUuid,
}: {
    konteks: KonteksAksiMassal<T>;
    alamat: string;
    ambilUuid: (baris: T) => string;
}) {
    const uuid = konteks.terpilih.map(ambilUuid);
    const terlaluBanyak = uuid.length > MaksEksporTerpilih;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <TombolEkspor
                alamat={alamat}
                query={`uuid=${uuid.join(',')}`}
                label={`Ekspor ${uuid.length.toLocaleString('id-ID')} terpilih`}
                nonaktif={terlaluBanyak}
            />
            {terlaluBanyak ? (
                <p className="text-keterangan text-bahaya">
                    Maksimal {MaksEksporTerpilih} baris untuk ekspor terpilih. Kurangi pilihan, atau pakai Ekspor di
                    atas tabel untuk semua hasil filter.
                </p>
            ) : null}
        </div>
    );
}
