# Real portal fixtures

Sanitized copies of real CIC portal pages, captured read-only with
`php artisan portal:capture-fixtures` so the heuristics that read the
portal's pages can be tested against real markup instead of hand-written
HTML.

```
php artisan portal:capture-fixtures --session=<campus session token> \
    --meeting=<meeting_id>:<courses_id>[:<pertemuan>] --exam=<exam id>
```

The command only sends GET requests with the given session, never submits
anything and refuses to run in production. Before a page is written, the
student's name, NIM/username, email addresses, phone numbers, cookies and
session ids, CSRF and hidden tokens, uploaded file names, every other
value of the session's `user_info` and the identifying fields of the
student's stored profile (name, NIM, email, phone, home address, place and
date of birth, NISN, school) are replaced by the placeholders of
`PortalResponseRecorder` (`MAHASISWA UJI`, `20990000001`,
`mahasiswa@example.test`, `surel1@example.test`, `081200000001`,
`SANITIZED_COOKIE`, `SANITIZED_TOKEN`, `berkas-unggahan.pdf`,
`DATA_MAHASISWA_<KEY>`); a word of the name standing alone becomes
`Mahasiswa` (first word) or `Uji`. Lecturers' names become `Dosen Uji A`,
`Dosen Uji B`, ... (titles such as `M.Kom.` stay): those the page shows in
its lecturer fields (`Nama Dosen`, `Dosen`, a `Dosen` column, the page's
author) and those of the student's stored class list, wherever they
appear. A login whose session and stored profile do not name the student
and their NIM captures nothing (open the Profil page once with that
login).

Write actions (attendance, assignment upload and delete, exam upload)
cannot be captured here: only the student may perform them. With
`PORTAL_RECORD_ACTION_RESPONSES=true` (in production only together with
`PORTAL_DEBUG_ALLOW_IN_PRODUCTION=true`) MyCIC keeps sanitized copies of
the portal's answers to them in `storage/app/portal-captures/{action}/`
(`attendance_submit`, `assignment_upload`, `assignment_delete`,
`exam_upload`) and of the pages read to verify them (`{action}_verify`);
copy the ones a test needs into this folder and list them below. Without
a campus session naming the student and their NIM nothing is recorded.

## Before committing a capture

The placeholders cover what the session knows about the student. Read
every new file and look for what they cannot cover:

- the student's name written another way: a nickname, initials, a
  misspelling, or a name word that is also a page word (month and day
  names, words such as `Tugas` or `Hadir`), which stays so dates and the
  heuristics' keywords read as the portal wrote them;
- other people: a lecturer named outside a lecturer field and not in the
  stored class list, a nickname of one, and classmates' names, NIMs,
  emails and photos;
- anything else personal in free text, comments or image URLs.

## Files

The table between the markers is rewritten by the command; add hand-copied
captures as rows of the same shape.
<!-- captures:start -->
| File | What it is | Captured at |
| --- | --- | --- |
| `assignment_form_36342_9521_3.html` | Assignment upload page (act=send), meeting 36342 of course 9521, pertemuan 3 | 2026-10-23T11:42:33+07:00 |
| `assignment_form_36365_9496_3.html` | Assignment upload page (act=send), meeting 36365 of course 9496, pertemuan 3 | 2026-10-23T11:42:21+07:00 |
| `assignment_form_36620_9521_4.html` | Assignment upload page (act=send), meeting 36620 of course 9521, pertemuan 4 | 2026-10-23T11:42:35+07:00 |
| `assignment_form_36633_9496_4.html` | Assignment upload page (act=send), meeting 36633 of course 9496, pertemuan 4 | 2026-10-23T11:42:23+07:00 |
| `assignment_form_36689_9520_4.html` | Assignment upload page (act=send), meeting 36689 of course 9520, pertemuan 4 | 2026-10-23T11:42:37+07:00 |
| `assignment_form_36783_9525_4.html` | Assignment upload page (act=send), meeting 36783 of course 9525, pertemuan 4 | 2026-10-23T11:42:28+07:00 |
| `assignment_form_36957_9496_5.html` | Assignment upload page (act=send), meeting 36957 of course 9496, pertemuan 5 | 2026-10-23T11:42:25+07:00 |
| `assignment_form_37082_9525_5.html` | Assignment upload page (act=send), meeting 37082 of course 9525, pertemuan 5 | 2026-10-23T11:42:30+07:00 |
| `exam_detail_9496.html` | Exam detail and upload page (act=sendujian), exam 9496 | 2026-10-23T11:42:42+07:00 |
| `exam_detail_9519.html` | Exam detail and upload page (act=sendujian), exam 9519 | 2026-10-23T11:42:40+07:00 |
| `exam_detail_9524.html` | Exam detail and upload page (act=sendujian), exam 9524 | 2026-10-23T11:42:41+07:00 |
| `exam_list.html` | Exam list (act=ujian) | 2026-10-23T11:42:39+07:00 |
| `meeting_36342_9521.html` | Meeting page (act=discuss), meeting 36342 of course 9521 | 2026-10-23T11:42:31+07:00 |
| `meeting_36365_9496.html` | Meeting page (act=discuss), meeting 36365 of course 9496 | 2026-10-23T11:42:19+07:00 |
| `meeting_36620_9521.html` | Meeting page (act=discuss), meeting 36620 of course 9521 | 2026-10-23T11:42:34+07:00 |
| `meeting_36633_9496.html` | Meeting page (act=discuss), meeting 36633 of course 9496 | 2026-10-23T11:42:22+07:00 |
| `meeting_36689_9520.html` | Meeting page (act=discuss), meeting 36689 of course 9520 | 2026-10-23T11:42:36+07:00 |
| `meeting_36783_9525.html` | Meeting page (act=discuss), meeting 36783 of course 9525 | 2026-10-23T11:42:27+07:00 |
| `meeting_36957_9496.html` | Meeting page (act=discuss), meeting 36957 of course 9496 | 2026-10-23T11:42:24+07:00 |
| `meeting_37082_9525.html` | Meeting page (act=discuss), meeting 37082 of course 9525 | 2026-10-23T11:42:29+07:00 |
<!-- captures:end -->
