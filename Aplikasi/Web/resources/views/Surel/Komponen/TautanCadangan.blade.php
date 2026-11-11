{{--
    Alamat lengkap sebagai teks, untuk penerima yang tombolnya tidak bisa diklik (klien memblokir tautan,
    atau email dibuka di perangkat lain).

    `{{ $Url }}` aman di sini karena ini badan HTML: `&` menjadi `&amp;` di sumber, lalu dipulihkan peramban
    menjadi `&` saat ditampilkan dan saat diklik. Di badan **teks biasa** hal ini justru merusak tanda
    tangan tautan, jadi templat teks memakai `{!! !!}`.

    Dipakai: @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])
--}}
<p style="margin:0 0 4px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#4f6567;">Tombol tidak bisa diklik? Tempel alamat ini di peramban Anda:</p>
<p style="margin:0 0 20px 0; font-family:'Lato','SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:13px; line-height:20px; color:#4f6567; word-break:break-all;"><a href="{{ $Url }}" style="color:#4f6567; text-decoration:underline;">{{ $Url }}</a></p>
