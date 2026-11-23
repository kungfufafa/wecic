<?php

/**
 * The sections of the signed-in app and where each page sits in them. A
 * section has a label and an icon (resources/views/components/data/icons.php).
 * A page, keyed by its route name, names its section, its own icon when it
 * has one, and its breadcrumb: a list of [label] or [label, route name]
 * pairs, the last one being the page itself.
 *
 * The layout derives the top bar breadcrumb from it (<x-breadcrumb>), and
 * <x-page-hero> hands a breadcrumb it is given to the top bar.
 *
 * @return array{
 *     sections: array<string, array{label: string, icon: string}>,
 *     pages: array<string, array{section: string, icon?: string, crumbs: list<array{0: string, 1?: string}>}>
 * }
 */
return [
    'sections' => [
        'beranda' => ['label' => 'Beranda', 'icon' => 'home'],
        'kuliah' => ['label' => 'Kuliah', 'icon' => 'class'],
        'akademik' => ['label' => 'Akademik', 'icon' => 'academic'],
        'keuangan' => ['label' => 'Keuangan', 'icon' => 'finance'],
        'skkm' => ['label' => 'SKKM', 'icon' => 'skkm'],
        'skripsi' => ['label' => 'Skripsi', 'icon' => 'thesis'],
        'perpustakaan' => ['label' => 'Perpustakaan', 'icon' => 'library'],
        'pengumuman' => ['label' => 'Pengumuman', 'icon' => 'announcement'],
        'notifikasi' => ['label' => 'Notifikasi', 'icon' => 'bell'],
        'notebook' => ['label' => 'Notebook', 'icon' => 'notebook'],
        'arsip' => ['label' => 'Arsip', 'icon' => 'archive'],
        'akun' => ['label' => 'Akun', 'icon' => 'user'],
    ],

    'pages' => [
        'dashboard' => ['section' => 'beranda', 'crumbs' => [['Beranda']]],

        'class.index' => ['section' => 'kuliah', 'crumbs' => [['Kuliah'], ['Jadwal & Kelas']]],
        'class.show' => ['section' => 'kuliah', 'crumbs' => [['Kuliah', 'class.index'], ['Detail kelas']]],
        'meeting.show' => ['section' => 'kuliah', 'icon' => 'presentation', 'crumbs' => [['Kuliah', 'class.index'], ['Detail pertemuan']]],
        'exam.index' => ['section' => 'kuliah', 'icon' => 'exam', 'crumbs' => [['Kuliah', 'class.index'], ['Ujian']]],
        'exam.show' => ['section' => 'kuliah', 'icon' => 'exam', 'crumbs' => [['Kuliah', 'class.index'], ['Ujian', 'exam.index'], ['Detail ujian']]],

        'krs.index' => ['section' => 'akademik', 'icon' => 'list-check', 'crumbs' => [['Akademik'], ['KRS & Perwalian']]],
        'krs.show' => ['section' => 'akademik', 'icon' => 'list-check', 'crumbs' => [['Akademik', 'krs.index'], ['KRS & Perwalian', 'krs.index'], ['Detail KRS']]],
        'grades.index' => ['section' => 'akademik', 'icon' => 'chart', 'crumbs' => [['Akademik', 'krs.index'], ['Nilai']]],
        'transcript.index' => ['section' => 'akademik', 'icon' => 'file', 'crumbs' => [['Akademik', 'krs.index'], ['Transkrip']]],

        'finance.index' => ['section' => 'keuangan', 'crumbs' => [['Keuangan']]],

        'skkm.index' => ['section' => 'skkm', 'crumbs' => [['Lainnya', 'more'], ['SKKM']]],
        'thesis.index' => ['section' => 'skripsi', 'crumbs' => [['Lainnya', 'more'], ['Skripsi']]],
        'library.index' => ['section' => 'perpustakaan', 'crumbs' => [['Lainnya', 'more'], ['Perpustakaan']]],
        'library.theses' => ['section' => 'perpustakaan', 'crumbs' => [['Perpustakaan', 'library.index'], ['Repositori skripsi']]],
        'announcement.index' => ['section' => 'pengumuman', 'crumbs' => [['Lainnya', 'more'], ['Pengumuman']]],
        'announcement.show' => ['section' => 'pengumuman', 'crumbs' => [['Pengumuman', 'announcement.index'], ['Detail pengumuman']]],
        'notifications.index' => ['section' => 'notifikasi', 'crumbs' => [['Notifikasi']]],
        'notebook.index' => ['section' => 'notebook', 'crumbs' => [['Lainnya', 'more'], ['Notebook']]],
        'notebook.show' => ['section' => 'notebook', 'crumbs' => [['Notebook', 'notebook.index'], ['Detail notebook']]],
        'notebook.ai-settings' => ['section' => 'notebook', 'icon' => 'settings', 'crumbs' => [['Notebook', 'notebook.index'], ['Pengaturan AI']]],
        'archive.index' => ['section' => 'arsip', 'crumbs' => [['Lainnya', 'more'], ['Arsip WeCIC']]],
        'archive.show' => ['section' => 'arsip', 'crumbs' => [['Arsip WeCIC', 'archive.index'], ['Riwayat']]],
        'archive.print' => ['section' => 'arsip', 'crumbs' => [['Arsip WeCIC', 'archive.index'], ['Bukti arsip']]],

        'profile' => ['section' => 'akun', 'crumbs' => [['Akun'], ['Profil']]],
        'password.edit' => ['section' => 'akun', 'icon' => 'lock', 'crumbs' => [['Akun'], ['Ubah password']]],
        'more' => ['section' => 'akun', 'icon' => 'more', 'crumbs' => [['Lainnya']]],
    ],
];
