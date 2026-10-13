import Panel from '@/Komponen/Kelola/Panel';
import LabelKesehatan from '@/Komponen/Pengelola/Tenant/LabelKesehatan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { KesehatanTenant } from '@/Tipe/TenantPengelola';

/** Uraian tiap faktor skor dalam bahasa tim (nilai ukuran ditulis konkret, bukan kode). */
const uraianFaktor: Record<string, (nilai: number | boolean) => string> = {
    HariAktif: (n) => `${String(n)} dari 14 hari terakhir ada transaksi`,
    TrenTransaksi: (n) => `${String(n)} transaksi dalam 7 hari terakhir`,
    FiturDipakai: (n) => `${String(n)} fitur utama dipakai dalam 30 hari`,
    PerangkatLamaOffline: (n) =>
        n === 0 ? 'Tidak ada perangkat lama offline' : `${String(n)} perangkat offline lebih dari 7 hari`,
    TiketTerbukaLama: (n) =>
        n === 0 ? 'Tidak ada tiket terbuka lewat 3 hari' : `${String(n)} tiket terbuka lewat 3 hari`,
    Tertunggak: (n) => (n === true ? 'Langganan tertunggak' : 'Langganan tidak tertunggak'),
};

/**
 * Skor kesehatan tenant di tampilan 360° (P-07 PGL-09): skor terbaru dengan rincian poin tiap faktor, riwayat 14 hari
 * terakhir, dan tugas otomatis yang masih terbuka. Skor dihitung harian dini hari.
 */
export default function PanelKesehatanTenant({ kesehatan }: { kesehatan: KesehatanTenant }) {
    const { Terbaru, Riwayat, Tugas } = kesehatan;

    return (
        <Panel judul="Skor kesehatan" keterangan="Dihitung harian dari pemakaian 14 hari terakhir.">
            {Terbaru === null ? (
                <p className="text-isi text-teks-sekunder">
                    Belum dinilai. Tenant baru dinilai pada hitungan harian berikutnya.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-tampilan font-bold text-teks-utama tabular-nums">{Terbaru.Skor}</span>
                        <span className="text-isi text-teks-sekunder">dari 100</span>
                        <LabelKesehatan kategori={Terbaru.Kategori} teks={Terbaru.LabelKategori} />
                        <span className="text-keterangan text-teks-sekunder">per {FormatTanggal(Terbaru.Tanggal)}</span>
                    </div>
                    <ul aria-label="Rincian skor" className="flex flex-col gap-1 text-isi">
                        {Terbaru.Faktor.map((f) => (
                            <li key={f.Kunci} className="flex flex-wrap justify-between gap-x-3">
                                <span className="text-teks-utama">{uraianFaktor[f.Kunci]?.(f.Nilai) ?? f.Kunci}</span>
                                <span className="text-teks-sekunder tabular-nums">
                                    {f.Poin} / {f.Maks} poin
                                </span>
                            </li>
                        ))}
                    </ul>
                    {Riwayat.length > 1 ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Riwayat (terbaru dulu): {Riwayat.map((r) => r.Skor).join(', ')}
                        </p>
                    ) : null}
                </div>
            )}
            {Tugas.length > 0 ? (
                <div className="mt-3 flex flex-col gap-2">
                    <h3 className="text-subjudul font-semibold text-teks-utama">Tugas terbuka</h3>
                    <ul className="flex flex-col gap-2">
                        {Tugas.map((t) => (
                            <li key={t.Uuid} className="rounded-kontrol border border-garis p-3 text-isi">
                                <span className="font-semibold text-teks-utama">{t.LabelJenis}: </span>
                                {t.Ringkasan}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </Panel>
    );
}
