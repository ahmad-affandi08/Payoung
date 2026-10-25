{{--
    Tabel rincian dua kolom (label di kiri, nilai di kanan) untuk data dokumen: nomor tiket, tagihan, prioritas.
    Dipakai sebagai tabel, bukan daftar berlabel, karena isinya memang data berpasangan (§17.6).

    Dipakai: @include('Surel.Komponen.Rincian', ['Baris' => ['Nomor' => $Nomor, 'Prioritas' => $Prioritas]])
--}}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%; margin:0 0 20px 0; background-color:#ebf0ec; border:1px solid #d2dcd6; border-radius:8px;">
    @foreach ($Baris as $Label => $Nilai)
        <tr>
            <td style="padding:{{ $loop->first ? '14' : '0' }}px 16px {{ $loop->last ? '14' : '8' }}px 16px; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#4f6567; white-space:nowrap; vertical-align:top;">{{ $Label }}</td>
            <td align="right" style="padding:{{ $loop->first ? '14' : '0' }}px 16px {{ $loop->last ? '14' : '8' }}px 8px; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335; vertical-align:top;">{{ $Nilai }}</td>
        </tr>
    @endforeach
</table>
