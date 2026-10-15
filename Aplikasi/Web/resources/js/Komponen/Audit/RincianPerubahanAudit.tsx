export type PerubahanAudit = { Bidang: string; Lama: string | null; Baru: string | null };

type PropsRincian = {
    Perubahan: PerubahanAudit[];
    NilaiLama: Record<string, unknown> | null;
    NilaiBaru: Record<string, unknown> | null;
    Alasan?: string | null;
};

/**
 * Perubahan log audit dalam bahasa sehari-hari ("Bidang: lama → baru"); JSON mentah tetap tersedia di bawah
 * "Data teknis" untuk keperluan telusur.
 */
export default function RincianPerubahanAudit({ Perubahan, NilaiLama, NilaiBaru, Alasan }: PropsRincian) {
    const adaTeknis = NilaiLama !== null || NilaiBaru !== null;

    if (Perubahan.length === 0 && !adaTeknis && !Alasan) {
        return <span>—</span>;
    }

    return (
        <div className="space-y-1">
            {Perubahan.length > 0 ? (
                <ul className="space-y-0.5">
                    {Perubahan.map((p) => (
                        <li key={p.Bidang}>
                            <span className="font-medium text-teks">{p.Bidang}:</span>{' '}
                            {p.Lama !== null && p.Lama !== '—' ? (
                                <span className="text-teks-sekunder">{p.Lama} → </span>
                            ) : null}
                            <span className="text-teks">{p.Baru ?? '—'}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
            {Alasan ? <p>Alasan: {Alasan}</p> : null}
            {adaTeknis ? (
                <details>
                    <summary className="cursor-pointer text-teks-sekunder">Data teknis</summary>
                    {NilaiLama ? (
                        <p>
                            Lama: <code className="font-mono break-all">{JSON.stringify(NilaiLama)}</code>
                        </p>
                    ) : null}
                    {NilaiBaru ? (
                        <p>
                            Baru: <code className="font-mono break-all">{JSON.stringify(NilaiBaru)}</code>
                        </p>
                    ) : null}
                </details>
            ) : null}
        </div>
    );
}
