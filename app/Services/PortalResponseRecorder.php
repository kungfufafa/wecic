<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Keeps sanitized copies of what the portal answers to write actions
 * (attendance, assignment upload and delete, exam upload) and of the pages
 * read to verify them, so the heuristics that read those answers can be
 * tested against real markup (config `portal.debug`). Recording is an
 * opt-in debug switch, and production never records unless that is
 * allowed as well.
 *
 * Nothing is stored before sanitize() ran: the student's name, NIM or
 * username, email addresses (Cloudflare-protected ones too), phone numbers,
 * session cookies and ids, CSRF and other hidden tokens, the names of the
 * uploaded files and every other value of the session's `user_info` become
 * stable placeholders. Lecturers' names become "Dosen Uji A", "Dosen Uji B"
 * and so on: the names the page shows in its lecturer fields ("Nama Dosen",
 * "Dosen", a "Dosen" column, the page's author) and those of the student's
 * stored class list. Only text and attribute values are rewritten; tags,
 * attribute names and the document structure stay as the portal sent them.
 * Without a session naming the student and their NIM nothing is stored.
 *
 * @phpstan-type SanitizeContext array{
 *     names?: list<string>,
 *     nims?: list<string>,
 *     emails?: list<string>,
 *     phones?: list<string>,
 *     user_info?: array<string, string>,
 *     uploaded_file_names?: list<string>,
 *     secrets?: list<string>,
 *     lecturers?: list<string>
 * }
 */
class PortalResponseRecorder
{
    public const NAME_PLACEHOLDER = 'MAHASISWA UJI';

    public const NIM_PLACEHOLDER = '20990000001';

    public const STUDENT_EMAIL_PLACEHOLDER = 'mahasiswa@example.test';

    /**
     * Every other email address, numbered in order of first appearance.
     */
    public const EMAIL_PLACEHOLDER = 'surel%d@example.test';

    /**
     * Phone numbers, numbered in order of first appearance; a number written
     * as +62… or 62… keeps that prefix.
     */
    public const PHONE_PLACEHOLDER = '081200000%03d';

    public const COOKIE_PLACEHOLDER = 'SANITIZED_COOKIE';

    public const TOKEN_PLACEHOLDER = 'SANITIZED_TOKEN';

    /**
     * An uploaded file keeps its extension; the second one becomes
     * "berkas-unggahan-2", and so on.
     */
    public const UPLOADED_FILE_PLACEHOLDER = 'berkas-unggahan';

    /**
     * Any other user_info value, named after its key
     * ("DATA_MAHASISWA_DEPARTMENT").
     */
    public const USER_INFO_PLACEHOLDER = 'DATA_MAHASISWA_%s';

    /**
     * Lecturers, lettered A, B, … Z, AA, …: those of the stored class list
     * first, then the page's in document order. Academic titles after the
     * name stay ("Dosen Uji A, M.Kom."), and a word of the name standing
     * alone becomes the whole placeholder. Letters, not numbers: the exam
     * list tells its lecturer from a schedule by the schedule's digits.
     */
    public const LECTURER_PLACEHOLDER = 'Dosen Uji %s';

    /**
     * Snapshots of the student's class list, whose classes name their
     * lecturers (`lecturer_info.name`).
     *
     * @var list<string>
     */
    private const CLASS_LIST_DATASETS = ['lms_classes', 'classes'];

    /**
     * A lecturer field's label ("Dosen", "Nama Dosen", "Dosen Pengampu") or
     * a lecturer column's header ("Nama Dosen / Jadwal Ujian"); contact
     * fields ("Nomor Kontak Dosen", "Email Dosen") name no one.
     */
    private const LECTURER_LABEL_PATTERN = '/^(?:nama\s+)?dosen(?:\s+(?:pengampu\w*|pembimbing(?:\s+\w+)?|wali))?$/u';

    private const LECTURER_HEADER_PATTERN = '/dosen|pengampu|pengajar/u';

    private const LECTURER_CONTACT_PATTERN = '/kontak|e-?mail|telp|telepon|\bhp\b|nomor|nidn|\bnip\b|whatsapp|\bwa\b/u';

    /**
     * Honorifics before a name ("Dr.", "Prof.", "Ir.", "H."), left in place.
     */
    private const LECTURER_PREFIX_PATTERN = '/^(?:(?:prof|dr|drs|dra|ir|h|hj)\.?\s+)+/iu';

    /**
     * @var list<string>
     */
    private const NAME_KEYS = ['name', 'nama', 'student_name', 'nama_mahasiswa', 'nama_lengkap', 'full_name'];

    /**
     * @var list<string>
     */
    private const NIM_KEYS = ['student_id', 'nim', 'npm', 'username', 'nomor_induk'];

    /**
     * Fields of the student's stored profile (the `profile` dataset) that
     * identify them. A real login leaves the session's user_info empty, so
     * the profile names the student. Generic fields (programme, intake year,
     * city, status) stay out: replacing them would also rewrite page text
     * such as "T.A 2025/2026".
     *
     * @var list<string>
     */
    private const PROFILE_IDENTITY_KEYS = ['nama', 'nim', 'email', 'hp', 'telp', 'alamat_rumah', 'tempat_lahir', 'tanggal_lahir', 'nisn', 'asal_sekolah', 'alamat_sekolah'];

    /**
     * Comments, whole script and style elements, and tags; everything
     * between them is text.
     */
    private const MARKUP_PATTERN = '/(<!--.*?-->|<(?:script|style)\b[^>]*>.*?<\/(?:script|style)\s*>|<[A-Za-z!\/?][^>]*>)/is';

    /**
     * An attribute with a value, after whitespace or right after the closing
     * quote of the attribute before it (name="a"value="b").
     */
    private const ATTRIBUTE_PATTERN = '/(\s|(?<=["\']))([^\s"\'<>\/=]+)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/';

    /**
     * Words in the name of a field, meta tag, query parameter or script
     * variable that holds a token or a session id.
     */
    private const TOKEN_NAMES = 'token|csrf|xsrf|nonce|authenticity|sesskey|captcha|signature|secret|api_?key|phpsessid|session_?id';

    private const TOKEN_NAME_PATTERN = '/'.self::TOKEN_NAMES.'/i';

    /**
     * Attributes that carry a token themselves (data-token, nonce).
     */
    private const TOKEN_ATTRIBUTE_PATTERN = '/^[\w-]*(?:token|csrf|xsrf|nonce|sess(?:ion)?[-_]?id)[\w-]*$/i';

    private const SESSION_PARAMETER_PATTERN = '/(?<![A-Za-z0-9_])(PHPSESSID|sessid|session_?id|sid)=([^&;#"\'\s<>]+)/i';

    private const TOKEN_PARAMETER_PATTERN = '/(?<![A-Za-z0-9_])(_?token|csrf_?token|csrf|xsrf_?token|xsrf|authenticity_token|nonce|sesskey|signature|secret|api_?key)=([^&;#"\'\s<>]+)/i';

    /**
     * A token a script assigns ("csrf_token": "…", _token = '…',
     * "sesskey": "…"), under any of the token names fields use.
     */
    private const SCRIPT_TOKEN_PATTERN = '/((?<![A-Za-z0-9])[\w-]*(?:'.self::TOKEN_NAMES.')[\w-]*["\']?\s*[:=]\s*["\'])([^"\'\s<>]{6,})(["\'])/i';

    /**
     * A percent escape (%20) right before a value separates it like
     * whitespace does: "Tugas%20Budi" holds the word "Budi".
     */
    private const AFTER_PERCENT_ESCAPE = '(?<=%[0-9A-Fa-f]{2})';

    /**
     * Words of the student's name that are kept when they stand alone:
     * month and day names and the words the write-action heuristics look
     * for, so dates and keywords in a capture still read as the portal
     * wrote them. The full name is replaced all the same.
     *
     * @var list<string>
     */
    private const PAGE_WORDS = [
        'januari', 'februari', 'maret', 'april', 'juni', 'juli', 'agustus', 'september', 'oktober', 'november', 'desember',
        'january', 'february', 'march', 'june', 'july', 'august', 'october', 'december',
        'senin', 'selasa', 'rabu', 'kamis', 'jumat', "jum'at", 'sabtu', 'minggu',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'absen', 'absensi', 'akhir', 'anda', 'belum', 'berhasil', 'daftar', 'data', 'dihapus', 'disimpan', 'diupload',
        'dosen', 'file', 'gagal', 'hadir', 'hapus', 'jadwal', 'jawaban', 'kehadiran', 'kelas', 'kode', 'kuliah', 'lihat',
        'list', 'mahasiswa', 'materi', 'nilai', 'open', 'perkuliahan', 'pertemuan', 'praktek', 'praktikum', 'reguler',
        'semester', 'sudah', 'sukses', 'susulan', 'tengah', 'teori', 'tercatat', 'terhapus', 'tersimpan', 'tidak',
        'tugas', 'ujian', 'upload', 'uploaded',
    ];

    private const CLOUDFLARE_LINK_PATTERN = '/(\/cdn-cgi\/l\/email-protection#)([0-9a-fA-F]{4,})/';

    private const EMAIL_PATTERN = '/(?<![A-Za-z0-9._+-])[A-Za-z0-9._+-]+(@|%40)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.([A-Za-z]{2,})(?![A-Za-z0-9-])/';

    /**
     * "Domains" that are file extensions: logo@2x.png is no email address.
     *
     * @var list<string>
     */
    private const FILE_EXTENSION_TLDS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp', 'css', 'js', 'pdf'];

    /**
     * Indonesian mobile numbers (08…, 628…, +62 8…) with or without
     * separators; the digit count is checked on the match.
     */
    private const PHONE_PATTERN = '/(?<![\w+])(?:\+62|62|0)[\s.-]?8\d{1,3}(?:[\s.-]?\d{2,5}){1,3}(?!\w)/';

    private static bool $productionRefusalReported = false;

    private string $unicodeFlag = 'u';

    /**
     * @var array<string, string>
     */
    private array $emailPlaceholders = [];

    /**
     * @var array<string, string>
     */
    private array $phonePlaceholders = [];

    /**
     * Placeholder numbers taken: those the response already holds (a page
     * captured before, a test fixture) and those handed out since.
     *
     * @var array{email: array<int, true>, phone: array<int, true>, lecturer: array<int, true>}
     */
    private array $reservedNumbers = ['email' => [], 'phone' => [], 'lecturer' => []];

    /**
     * @var list<string>
     */
    private array $studentEmails = [];

    /**
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * @var list<array{pattern: string, replacement: string}>
     */
    private array $fileNameRules = [];

    /**
     * @var list<array{pattern: string, replace: callable(array<int|string, string>): string}>
     */
    private array $identityRules = [];

    /**
     * Whether action responses are recorded: the debug switch is on, and
     * the app is not in production unless production was allowed too.
     */
    public function isEnabled(): bool
    {
        if (! (bool) config('portal.debug.record_action_responses', false)) {
            return false;
        }

        if (app()->environment('production') && ! (bool) config('portal.debug.allow_in_production', false)) {
            $this->reportProductionRefusal();

            return false;
        }

        return true;
    }

    /**
     * Store a sanitized copy of a portal response under
     * {capture_path}/{action}/{Y-m-d_His}_{hash}.html. Recording never
     * fails the action it records: an error is logged and nothing is kept.
     * Without a campus session naming the student and their NIM nothing is
     * kept either, because the page would keep them.
     *
     * @param  array<string, mixed>|null  $session  The stored campus session (PortalSessionManager::getSession()).
     * @param  list<string>  $uploadedFileNames  Names of the files the action uploaded.
     * @return string|null The stored file, or null when nothing was recorded.
     */
    public function record(string $action, string $html, ?array $session = null, array $uploadedFileNames = []): ?string
    {
        if ($html === '' || ! $this->isEnabled()) {
            return null;
        }

        try {
            $context = $this->contextFromSession($session, $uploadedFileNames);
            $missing = $this->missingIdentity($context);

            if ($missing !== []) {
                Log::warning('Portal action response not recorded: no student name and NIM to sanitize it with', [
                    'action' => $action,
                    'reason' => $session === null ? 'no_session' : 'incomplete_session',
                    'missing' => $missing,
                ]);

                return null;
            }

            $sanitized = $this->sanitize($html, $context);
            $directory = $this->capturePath().DIRECTORY_SEPARATOR.$this->actionDirectory($action);
            $path = $directory.DIRECTORY_SEPARATOR.now()->format('Y-m-d_His').'_'.substr(hash('sha256', $sanitized), 0, 8).'.html';

            File::ensureDirectoryExists($directory, 0700);

            if (File::put($path, $sanitized) === false) {
                throw new RuntimeException("The capture could not be written to {$path}.");
            }

            return $path;
        } catch (\Throwable $throwable) {
            Log::warning('Failed to record a portal action response', [
                'action' => $action,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * What sanitize() replaces for a campus session: the student's name,
     * NIM and username, email address, phone numbers and every other
     * user_info value, the identifying fields of the student's stored
     * profile, plus the session's cookie values and token, and the lecturers
     * of the student's stored class list.
     *
     * @param  array<string, mixed>|null  $session
     * @param  list<string>  $uploadedFileNames
     * @return array{names: list<string>, nims: list<string>, emails: list<string>, phones: list<string>, user_info: array<string, string>, uploaded_file_names: list<string>, secrets: list<string>, lecturers: list<string>}
     */
    public function contextFromSession(?array $session, array $uploadedFileNames = []): array
    {
        $context = [
            'names' => [],
            'nims' => [],
            'emails' => [],
            'phones' => [],
            'user_info' => [],
            'uploaded_file_names' => array_values(array_filter($uploadedFileNames, 'is_string')),
            'secrets' => [],
            'lecturers' => [],
        ];

        if (is_string($session['username'] ?? null) && trim($session['username']) !== '') {
            $context['nims'][] = trim($session['username']);
        }

        $entries = $this->flattenUserInfo(is_array($session['user_info'] ?? null) ? $session['user_info'] : []);

        if (is_string($session['token'] ?? null) && $session['token'] !== '') {
            array_push($entries, ...$this->storedProfileIdentity($session['token']));
            $context['lecturers'] = $this->storedLecturerNames($session['token']);
        }

        foreach ($entries as $entry) {
            $key = strtolower($entry['key']);
            $value = $entry['value'];

            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $context['emails'][] = $value;
            } elseif (in_array($key, self::NAME_KEYS, true)) {
                $context['names'][] = $value;
            } elseif (in_array($key, self::NIM_KEYS, true)) {
                $context['nims'][] = $value;
            } elseif ($this->looksLikePhone($value)) {
                $context['phones'][] = $value;
            } else {
                $context['user_info'][$entry['path']] = $value;
            }
        }

        foreach (is_array($session['cookies'] ?? null) ? $session['cookies'] : [] as $cookie) {
            $value = is_array($cookie) ? ($cookie['Value'] ?? null) : null;

            if (is_string($value) && $this->isSecret($value)) {
                $context['secrets'][] = $value;
            }
        }

        if (is_string($session['token'] ?? null) && $this->isSecret($session['token'])) {
            $context['secrets'][] = $session['token'];
        }

        return $context;
    }

    /**
     * What a context lacks for sanitize() to hide the student: a name with
     * letters and a NIM or username, each long enough to be matched. A page
     * sanitized without them would keep the student's name or NIM, so
     * record() and portal:capture-fixtures keep nothing then.
     *
     * @param  SanitizeContext  $context
     * @return list<'name'|'nim'>
     */
    public function missingIdentity(array $context): array
    {
        $usable = static fn (mixed $value, bool $needsLetter): bool => is_string($value)
            && mb_strlen(trim($value)) >= 3
            && (! $needsLetter || preg_match('/\pL/u', $value) === 1);

        $missing = [];

        if (array_filter($context['names'] ?? [], static fn (mixed $name): bool => $usable($name, true)) === []) {
            $missing[] = 'name';
        }

        if (array_filter($context['nims'] ?? [], static fn (mixed $nim): bool => $usable($nim, false)) === []) {
            $missing[] = 'nim';
        }

        return $missing;
    }

    /**
     * Replace everything that identifies the student or their session with
     * stable placeholders: the same value always becomes the same
     * placeholder, numbered placeholders count in document order, and the
     * markup around them is kept. A response that cannot be sanitized
     * throws instead of coming back unsanitized.
     *
     * @param  SanitizeContext  $context
     *
     * @throws RuntimeException
     */
    public function sanitize(string $html, array $context = []): string
    {
        $this->prepare($html, $context);

        $parts = preg_split(self::MARKUP_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            throw new RuntimeException('The portal response could not be split into markup and text: '.preg_last_error_msg());
        }

        $sanitized = '';

        foreach ($parts as $index => $part) {
            $sanitized .= $index % 2 === 0 ? $this->sanitizeText($part) : $this->sanitizeMarkup($part);
        }

        return $sanitized;
    }

    /**
     * @param  SanitizeContext  $context
     */
    private function prepare(string $html, array $context): void
    {
        $this->unicodeFlag = mb_check_encoding($html, 'UTF-8') ? 'u' : '';
        $this->emailPlaceholders = [];
        $this->phonePlaceholders = [];
        $this->reservedNumbers = [
            'email' => $this->numbersIn('/surel(\d+)@example\.test/i', $html),
            'phone' => $this->numbersIn('/(?<!\d)(?:0|62|\+62)81200000(\d{3})(?!\d)/', $html),
            'lecturer' => $this->lecturerLettersIn($html),
        ];
        $this->studentEmails = array_values(array_unique(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $context['emails'] ?? []
        )));
        $this->secrets = $this->secretVariants($context['secrets'] ?? []);
        $this->fileNameRules = $this->fileNameRules($context['uploaded_file_names'] ?? []);
        $this->identityRules = $this->identityRules($context, $this->lecturerNames($html, $context['lecturers'] ?? []));
    }

    private function sanitizeMarkup(string $markup): string
    {
        if (str_starts_with($markup, '<!--') && str_ends_with($markup, '-->') && strlen($markup) >= 7) {
            return '<!--'.$this->sanitizeText(substr($markup, 4, -3)).'-->';
        }

        if (preg_match('/^(<(?:script|style)\b[^>]*>)(.*)(<\/(?:script|style)\s*>)$/is', $markup, $element) === 1) {
            return $this->sanitizeTag($element[1]).$this->sanitizeText($element[2]).$element[3];
        }

        if (str_starts_with($markup, '<!') || str_starts_with($markup, '<?')) {
            return '<'.$this->sanitizeText(substr($markup, 1, -1)).'>';
        }

        return $this->sanitizeTag($markup);
    }

    /**
     * Each attribute value is sanitized by what its attribute is; whatever
     * lies between the attributes (an unclosed quote, a value no attribute
     * claims) is sanitized as text, so markup the attribute pattern does
     * not understand cannot carry the student's data through.
     */
    private function sanitizeTag(string $tag): string
    {
        if (preg_match('/^<([A-Za-z][A-Za-z0-9:-]*)/', $tag, $nameMatch) !== 1) {
            return $tag;
        }

        $offset = strlen($nameMatch[0]);
        $attributes = $this->attributeMatches($tag, $offset);
        $holdsToken = $this->holdsToken(strtolower($nameMatch[1]), $this->attributeValues($attributes));
        $sanitized = $nameMatch[0];

        foreach ($attributes as $attribute) {
            [$whole, $start] = $attribute[0];
            $quoted = $attribute[4][0];
            $quote = $quoted[0] === '"' || $quoted[0] === "'" ? $quoted[0] : '';
            $value = $quote === '' ? $quoted : substr($quoted, 1, -1);
            $sanitizedValue = $this->sanitizeAttributeValue(strtolower($attribute[2][0]), $value, $holdsToken);

            if ($quote === '' && preg_match('/[\s"\'=<>`]/', $sanitizedValue) === 1) {
                $quote = '"';
            }

            $sanitized .= $this->sanitizeText(substr($tag, $offset, $start - $offset))
                .$attribute[1][0].$attribute[2][0].$attribute[3][0].$quote.$sanitizedValue.$quote;
            $offset = $start + strlen($whole);
        }

        return $sanitized.$this->sanitizeText(substr($tag, $offset));
    }

    /**
     * The tag's attributes with values, from `$offset` (after the tag name).
     *
     * @return list<array<int, array{0: string, 1: int}>>
     */
    private function attributeMatches(string $tag, int $offset): array
    {
        if (preg_match_all(self::ATTRIBUTE_PATTERN, $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $offset) === false) {
            throw new RuntimeException('A portal tag could not be sanitized: '.preg_last_error_msg());
        }

        return $matches;
    }

    private function sanitizeAttributeValue(string $name, string $value, bool $holdsToken): string
    {
        if ($value === '') {
            return $value;
        }

        if ($holdsToken && ($name === 'value' || $name === 'content')) {
            return self::TOKEN_PLACEHOLDER;
        }

        if ($name !== 'name' && $name !== 'id' && preg_match(self::TOKEN_ATTRIBUTE_PATTERN, $name) === 1) {
            return self::TOKEN_PLACEHOLDER;
        }

        if ($name === 'data-cfemail') {
            return $this->sanitizeCloudflareHex($value);
        }

        return $this->sanitizeText($value);
    }

    /**
     * A CSRF or session field (by its name), a hidden field whose value
     * looks like a random token, or a csrf meta tag.
     *
     * @param  array<string, string>  $attributes
     */
    private function holdsToken(string $tagName, array $attributes): bool
    {
        if ($tagName === 'meta') {
            foreach (['name', 'http-equiv', 'property'] as $key) {
                if (preg_match(self::TOKEN_NAME_PATTERN, $attributes[$key] ?? '') === 1) {
                    return true;
                }
            }

            return false;
        }

        if (! in_array($tagName, ['input', 'button', 'textarea', 'select', 'option'], true)) {
            return false;
        }

        if (preg_match(self::TOKEN_NAME_PATTERN, ($attributes['name'] ?? '').' '.($attributes['id'] ?? '')) === 1) {
            return true;
        }

        return $tagName === 'input'
            && strtolower($attributes['type'] ?? '') === 'hidden'
            && $this->looksLikeToken($attributes['value'] ?? '');
    }

    /**
     * Long random strings (session ids, CSRF tokens, hashes): letters and
     * digits, no spaces, dots or URL parts. Ids, meeting numbers and exam
     * types the portal keeps in hidden fields do not look like this.
     */
    private function looksLikeToken(string $value): bool
    {
        $value = trim($value);

        return strlen($value) >= 16
            && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('/\d/', $value) === 1;
    }

    /**
     * Attribute values by lower-case name, decoded; the first of a repeated
     * attribute wins, as in a browser.
     *
     * @param  list<array<int, array{0: string, 1: int}>>  $matches
     * @return array<string, string>
     */
    private function attributeValues(array $matches): array
    {
        $attributes = [];

        foreach ($matches as $match) {
            $quoted = $match[4][0];
            $value = $quoted[0] === '"' || $quoted[0] === "'" ? substr($quoted, 1, -1) : $quoted;
            $attributes[strtolower($match[2][0])] ??= html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    private function sanitizeText(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::COOKIE_PLACEHOLDER, $text);
        }

        $text = $this->replace(self::SESSION_PARAMETER_PATTERN, static fn (array $match): string => $match[1].'='.self::COOKIE_PLACEHOLDER, $text);
        $text = $this->replace(self::TOKEN_PARAMETER_PATTERN, static fn (array $match): string => $match[1].'='.self::TOKEN_PLACEHOLDER, $text);
        $text = $this->replace(self::SCRIPT_TOKEN_PATTERN, static fn (array $match): string => $match[1].self::TOKEN_PLACEHOLDER.$match[3], $text);
        $text = $this->replace(self::CLOUDFLARE_LINK_PATTERN, fn (array $match): string => $match[1].$this->sanitizeCloudflareHex($match[2]), $text);
        $text = $this->replace(self::EMAIL_PATTERN, fn (array $match): string => $this->emailReplacement($match), $text);

        foreach ($this->fileNameRules as $rule) {
            $text = $this->replace($rule['pattern'], static fn (): string => $rule['replacement'], $text);
        }

        foreach ($this->identityRules as $rule) {
            $text = $this->replace($rule['pattern'], $rule['replace'], $text);
        }

        return $this->replace(self::PHONE_PATTERN, fn (array $match): string => $this->phoneReplacement($match[0]), $text);
    }

    /**
     * @param  callable(array<int|string, string>): string  $callback
     */
    private function replace(string $pattern, callable $callback, string $text): string
    {
        $replaced = preg_replace_callback($pattern, $callback, $text);

        if ($replaced === null) {
            throw new RuntimeException('A portal response could not be sanitized: '.preg_last_error_msg());
        }

        return $replaced;
    }

    /**
     * Cloudflare hides email addresses as hex XORed with the first byte; the
     * placeholder address is hidden the same way, so the page still decodes.
     */
    private function sanitizeCloudflareHex(string $hex): string
    {
        if (strlen($hex) < 4 || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return $hex;
        }

        $key = (int) hexdec(substr($hex, 0, 2));
        $decoded = '';

        for ($offset = 2; $offset < strlen($hex); $offset += 2) {
            $decoded .= chr((int) hexdec(substr($hex, $offset, 2)) ^ $key);
        }

        if (filter_var($decoded, FILTER_VALIDATE_EMAIL) === false) {
            return $hex;
        }

        $sanitized = $this->sanitizeText($decoded);

        if ($sanitized === $decoded) {
            return $hex;
        }

        $encoded = sprintf('%02x', $key);

        foreach (str_split($sanitized) as $character) {
            $encoded .= sprintf('%02x', ord($character) ^ $key);
        }

        return $encoded;
    }

    /**
     * @param  array<int|string, string>  $match
     */
    private function emailReplacement(array $match): string
    {
        if (in_array(strtolower($match[2]), self::FILE_EXTENSION_TLDS, true)) {
            return $match[0];
        }

        $encoded = $match[1] === '%40';
        $email = $encoded ? str_replace('%40', '@', $match[0]) : $match[0];
        $key = strtolower($email);

        if (str_ends_with($key, '@example.test')) {
            return $match[0];
        }

        $placeholder = in_array($key, $this->studentEmails, true)
            ? self::STUDENT_EMAIL_PLACEHOLDER
            : ($this->emailPlaceholders[$key] ??= sprintf(self::EMAIL_PLACEHOLDER, $this->nextNumber('email')));

        return $encoded ? str_replace('@', '%40', $placeholder) : $placeholder;
    }

    private function phoneReplacement(string $match): string
    {
        $national = $this->nationalPhoneNumber($match);

        if (strlen($national) < 10 || strlen($national) > 13 || preg_match('/^081200000\d{3}$/', $national) === 1) {
            return $match;
        }

        $placeholder = $this->phonePlaceholder($national);

        return match (true) {
            str_starts_with($match, '+62') => '+62'.substr($placeholder, 1),
            str_starts_with($match, '62') => '62'.substr($placeholder, 1),
            default => $placeholder,
        };
    }

    private function phonePlaceholder(string $national): string
    {
        return $this->phonePlaceholders[$national] ??= sprintf(self::PHONE_PLACEHOLDER, $this->nextNumber('phone'));
    }

    /**
     * The lowest placeholder number of a kind that is neither handed out
     * yet nor already in the response; it is taken from then on.
     *
     * @param  'email'|'phone'|'lecturer'  $kind
     */
    private function nextNumber(string $kind): int
    {
        $number = 1;

        while (isset($this->reservedNumbers[$kind][$number])) {
            $number++;
        }

        $this->reservedNumbers[$kind][$number] = true;

        return $number;
    }

    /**
     * @return array<int, true>
     */
    private function numbersIn(string $pattern, string $html): array
    {
        preg_match_all($pattern, $html, $matches);

        return array_fill_keys(array_map('intval', $matches[1] ?? []), true);
    }

    /**
     * The letters of a lecturer placeholder number: 1 is A, 26 Z, 27 AA.
     */
    private function lecturerLetters(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    /**
     * The numbers of the lecturer placeholders a response already holds.
     *
     * @return array<int, true>
     */
    private function lecturerLettersIn(string $html): array
    {
        preg_match_all('/\bDosen\s+Uji\s+([A-Z]{1,3})\b/i', $html, $matches);
        $numbers = [];

        foreach ($matches[1] as $letters) {
            $number = 0;

            foreach (str_split(strtoupper($letters)) as $letter) {
                $number = $number * 26 + (ord($letter) - 64);
            }

            $numbers[$number] = true;
        }

        return $numbers;
    }

    private function nationalPhoneNumber(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
    }

    private function looksLikePhone(string $value): bool
    {
        return preg_match('/^\+?[\d\s().-]+$/', $value) === 1
            && strlen((string) preg_replace('/\D+/', '', $value)) >= 9
            && preg_match('/^(?:\+?62|0)/', trim($value)) === 1;
    }

    /**
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private function secretVariants(array $secrets): array
    {
        $variants = [];

        foreach ($secrets as $secret) {
            if (! is_string($secret) || ! $this->isSecret($secret)) {
                continue;
            }

            $variants[] = $secret;
            $variants[] = rawurlencode($secret);
            $variants[] = urlencode($secret);
        }

        $variants = array_values(array_unique($variants));
        usort($variants, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $variants;
    }

    /**
     * Cookie values and tokens worth replacing wherever they appear: long
     * enough and not a plain word ("deleted", a language code), which would
     * take ordinary page text with it.
     */
    private function isSecret(string $value): bool
    {
        return strlen($value) >= 8 && preg_match('/^[A-Za-z]+$/', $value) !== 1;
    }

    /**
     * Uploaded file names as the portal may print them: as sent, URL
     * encoded, or with spaces turned into underscores or dashes. A name
     * counts only as a whole file name, so an upload called "1.pdf" leaves
     * the portal's own "9195-UAS-…-131901.pdf" alone.
     *
     * @param  list<string>  $fileNames
     * @return list<array{pattern: string, replacement: string}>
     */
    private function fileNameRules(array $fileNames): array
    {
        [$before, $after] = $this->boundaries('file');
        $rules = [];
        $seen = [];
        $number = 0;

        foreach (array_values(array_unique($fileNames)) as $fileName) {
            $basename = trim(basename(str_replace('\\', '/', $fileName)));

            if (mb_strlen($basename) < 3) {
                continue;
            }

            $number++;
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $placeholder = self::UPLOADED_FILE_PLACEHOLDER.($number > 1 ? '-'.$number : '').($extension !== '' ? '.'.$extension : '');

            $variants = [
                [$basename, $placeholder],
                [str_replace(' ', '_', $basename), $placeholder],
                [str_replace(' ', '-', $basename), $placeholder],
                [rawurlencode($basename), rawurlencode($placeholder)],
                [urlencode($basename), urlencode($placeholder)],
            ];

            foreach ($variants as [$variant, $replacement]) {
                if (isset($seen[$variant])) {
                    continue;
                }

                $seen[$variant] = true;
                $rules[] = [
                    'length' => strlen($variant),
                    'pattern' => '/'.$before.$this->literalPattern($variant).$after.'/i'.$this->unicodeFlag,
                    'replacement' => $replacement,
                ];
            }
        }

        usort($rules, static fn (array $first, array $second): int => $second['length'] <=> $first['length']);

        return array_map(static fn (array $rule): array => [
            'pattern' => $rule['pattern'],
            'replacement' => $rule['replacement'],
        ], $rules);
    }

    /**
     * Rules for the student's own values, longest value first so a name is
     * replaced before a shorter value inside it. After the full name, each
     * word of it (four letters or more, and not a word the portal's pages
     * use) is replaced on its own too: a greeting may print only the first
     * name, and markup may split the name ("<b>Budi</b> Santoso").
     * Lecturers' names and their words follow the same rules, after the
     * student's.
     *
     * @param  SanitizeContext  $context
     * @param  list<string>  $lecturers  Lecturers' names without titles (lecturerNames()).
     * @return list<array{pattern: string, replace: callable(array<int|string, string>): string}>
     */
    private function identityRules(array $context, array $lecturers = []): array
    {
        $entries = [];
        $nameWords = [];

        foreach ($context['names'] ?? [] as $name) {
            $entries[] = [$name, 'letters', fn (string $matched): string => $this->matchCase($matched, self::NAME_PLACEHOLDER)];

            foreach ($this->nameWords($name) as $position => $word) {
                $placeholder = $position === 0 ? 'Mahasiswa' : 'Uji';
                $nameWords[] = [$word, 'letters', fn (string $matched): string => $this->matchCase($matched, $placeholder)];
            }
        }

        foreach ($context['nims'] ?? [] as $nim) {
            $entries[] = [$nim, ctype_digit(trim($nim)) ? 'digits' : 'letters', static fn (): string => self::NIM_PLACEHOLDER];
        }

        foreach ($context['phones'] ?? [] as $phone) {
            $placeholder = fn (): string => $this->phonePlaceholder($this->nationalPhoneNumber($phone));
            $entries[] = [$phone, 'digits', $placeholder];
        }

        foreach ($context['user_info'] ?? [] as $key => $value) {
            $value = trim($value);

            if (mb_strlen($value) < 4 || (ctype_digit($value) && strlen($value) < 6)) {
                continue;
            }

            $placeholder = sprintf(self::USER_INFO_PLACEHOLDER, strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $key), '_')));
            $entries[] = [$value, 'letters', static fn (): string => $placeholder];
        }

        foreach ($lecturers as $lecturer) {
            $placeholder = sprintf(self::LECTURER_PLACEHOLDER, $this->lecturerLetters($this->nextNumber('lecturer')));
            $replace = fn (string $matched): string => $this->matchCase($matched, $placeholder);
            $entries[] = [$lecturer, 'letters', $replace];

            foreach ($this->nameWords($lecturer) as $word) {
                $nameWords[] = [$word, 'letters', $replace];
            }
        }

        $seen = [];
        $rules = [];

        foreach ([...$entries, ...$nameWords] as [$value, $boundary, $replace]) {
            $value = trim($value);
            $key = mb_strtolower($value);

            if (mb_strlen($value) < 3 || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            [$before, $after] = $this->boundaries($boundary);

            $rules[] = [
                'length' => mb_strlen($value),
                'pattern' => '/'.$before.$this->literalPattern($value).$after.'/i'.$this->unicodeFlag,
                'replace' => static fn (array $match): string => $replace($match[0]),
            ];

            foreach (array_unique([rawurlencode($value), urlencode($value)]) as $encoded) {
                if ($encoded === $value) {
                    continue;
                }

                $rules[] = [
                    'length' => strlen($encoded),
                    'pattern' => '/'.$before.preg_quote($encoded, '/').$after.'/i'.$this->unicodeFlag,
                    'replace' => static fn (array $match): string => rawurlencode($replace($match[0])),
                ];
            }
        }

        usort($rules, static fn (array $first, array $second): int => $second['length'] <=> $first['length']);

        return array_map(static fn (array $rule): array => [
            'pattern' => $rule['pattern'],
            'replace' => $rule['replace'],
        ], $rules);
    }

    /**
     * The words of a name worth replacing on their own: only letters (an
     * apostrophe or dash inside is fine), four letters or more, and not one
     * of the page words a capture must keep.
     *
     * @return array<int, string> Words by their position in the name.
     */
    private function nameWords(string $name): array
    {
        $words = [];

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $position => $word) {
            if (mb_strlen((string) preg_replace('/\PL+/u', '', $word)) >= 4
                && preg_match('/^\pL+(?:[\'’-]\pL+)*$/u', $word) === 1
                && ! in_array(mb_strtolower($word), self::PAGE_WORDS, true)) {
                $words[$position] = $word;
            }
        }

        return $words;
    }

    /**
     * Lookarounds that keep a value to whole words: `digits` for numbers
     * (the NIM inside a longer number stays), `letters` for words, `file`
     * for file names, which also do not continue a longer name joined by a
     * dot, dash or underscore. A percent escape before the value counts as
     * a separator.
     *
     * @param  'digits'|'letters'|'file'  $kind
     * @return array{0: string, 1: string}
     */
    private function boundaries(string $kind): array
    {
        return match ($kind) {
            'digits' => ['(?:'.self::AFTER_PERCENT_ESCAPE.'|(?<!\d))', '(?!\d)'],
            'file' => ['(?:'.self::AFTER_PERCENT_ESCAPE.'|(?<![\pL\pN._-]))', '(?![\pL\pN])'],
            default => ['(?:'.self::AFTER_PERCENT_ESCAPE.'|(?<![\pL\pN]))', '(?![\pL\pN])'],
        };
    }

    /**
     * A value as a pattern: any run of whitespace matches any other, and
     * characters the portal may print as entities match those too.
     */
    private function literalPattern(string $value): string
    {
        $words = preg_split('/\s+/'.$this->unicodeFlag, trim($value)) ?: [trim($value)];

        $quoted = array_map(static fn (string $word): string => strtr(preg_quote($word, '/'), [
            '&' => '(?:&|&amp;)',
            "'" => "(?:'|&\#0?39;|&apos;|&\#x27;)",
            '"' => '(?:"|&quot;|&\#0?34;)',
            '\<' => '(?:<|&lt;)',
            '\>' => '(?:>|&gt;)',
        ]), $words);

        return implode('(?:\s|&nbsp;|&\#160;)+', $quoted);
    }

    /**
     * The placeholder in the matched text's case: "BUDI" stays upper case,
     * "budi" lower case, anything else title case.
     */
    private function matchCase(string $matched, string $placeholder): string
    {
        if (mb_strtoupper($matched) === $matched) {
            return mb_strtoupper($placeholder);
        }

        if (mb_strtolower($matched) === $matched) {
            return mb_strtolower($placeholder);
        }

        return mb_convert_case(mb_strtolower($placeholder), MB_CASE_TITLE);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<array{key: string, path: string, value: string}>
     */
    /**
     * Lecturers' names to replace, without their titles and each once: the
     * known ones (the stored class list) first, then the page's own.
     *
     * @param  list<string>  $known
     * @return list<string>
     */
    private function lecturerNames(string $html, array $known): array
    {
        $names = [];

        foreach ([...$known, ...$this->pageLecturerNames($html)] as $raw) {
            $name = $this->lecturerName((string) $raw);

            if ($name !== null && ! isset($names[mb_strtolower($name)])) {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * The names a page shows in its lecturer fields, in document order: the
     * value of a "Dosen" / "Nama Dosen" row, every cell of a column headed
     * "Dosen" ("Nama Dosen / Jadwal Ujian"), up to the cell's first line
     * break (contact lines follow it), and the page's author meta tag.
     *
     * @return list<string>
     */
    private function pageLecturerNames(string $html): array
    {
        if (stripos($html, 'dosen') === false && stripos($html, 'pengampu') === false && stripos($html, 'author') === false) {
            return [];
        }

        try {
            $crawler = new Crawler($html);
        } catch (\Throwable) {
            return [];
        }

        $names = [];

        foreach ($crawler->filter('meta[name="author"]') as $meta) {
            $names[] = (string) $meta->getAttribute('content');
        }

        foreach ($crawler->filter('table') as $table) {
            $lecturerColumn = null;

            foreach ((new Crawler($table))->filter('tr') as $position => $row) {
                $cells = $this->rowCells($row);
                $label = mb_strtolower(rtrim($this->nodeText($cells[0] ?? null), ' :'));

                if (count($cells) >= 2 && preg_match(self::LECTURER_LABEL_PATTERN, $label) === 1) {
                    $separator = $this->nodeText($cells[1]);
                    $names[] = $this->firstLine($cells[count($cells) >= 3 && ($separator === ':' || $separator === '') ? 2 : 1]);

                    continue;
                }

                if ($lecturerColumn !== null) {
                    if (isset($cells[$lecturerColumn])) {
                        $names[] = $this->firstLine($cells[$lecturerColumn]);
                    }

                    continue;
                }

                $lecturerColumn = $this->lecturerColumn($cells, $position === 0);
            }
        }

        return $names;
    }

    /**
     * The index of a header row's lecturer column: a cell headed "Dosen"
     * (not a contact column) in a row of `th` cells, or in a table's first
     * row of three cells or more that is no "label : value" row.
     *
     * @param  list<\DOMElement>  $cells
     */
    private function lecturerColumn(array $cells, bool $firstRow): ?int
    {
        $isHeaderRow = $cells !== [] && array_filter($cells, static fn (\DOMElement $cell): bool => strtolower($cell->nodeName) !== 'th') === [];

        if (! $isHeaderRow && (! $firstRow || count($cells) < 3 || in_array(':', array_map(fn (\DOMElement $cell): string => $this->nodeText($cell), $cells), true))) {
            return null;
        }

        foreach ($cells as $index => $cell) {
            $header = mb_strtolower($this->nodeText($cell));

            if (preg_match(self::LECTURER_HEADER_PATTERN, $header) === 1 && preg_match(self::LECTURER_CONTACT_PATTERN, $header) !== 1) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A lecturer's name as it is replaced: up to the first comma (titles
     * follow it), without trailing dots or leading honorifics. Null for an
     * empty value, a placeholder, or a word the portal's pages use.
     */
    private function lecturerName(string $raw): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{A0}", ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $name = rtrim(trim(explode(',', $text, 2)[0]), ' .');
        $name = trim((string) preg_replace(self::LECTURER_PREFIX_PATTERN, '', $name));

        if (mb_strlen((string) preg_replace('/\PL+/u', '', $name)) < 3
            || preg_match('/^dosen\s+uji(?:\s+[a-z]{1,3})?$/iu', $name) === 1
            || in_array(mb_strtolower($name), self::PAGE_WORDS, true)) {
            return null;
        }

        return $name;
    }

    /**
     * The lecturers of the student's stored class lists (snapshot, or the
     * archived copy).
     *
     * @return list<string>
     */
    private function storedLecturerNames(string $sessionToken): array
    {
        $names = [];

        foreach (self::CLASS_LIST_DATASETS as $dataset) {
            try {
                $stored = app(PortalSnapshotStore::class)->get($sessionToken, $dataset)
                    ?? app(PortalDataArchive::class)->restore($sessionToken, $dataset);
            } catch (\Throwable) {
                continue;
            }

            $this->collectLecturerNames(is_array($stored['data'] ?? null) ? $stored['data'] : [], $names);
        }

        return array_values(array_unique($names));
    }

    /**
     * Every `lecturer_info.name` and `lecturer_name` in a stored payload.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $names
     */
    private function collectLecturerNames(array $data, array &$names): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'lecturer_info' && is_array($value) && is_string($value['name'] ?? null) && trim($value['name']) !== '') {
                $names[] = trim($value['name']);
            } elseif ($key === 'lecturer_name' && is_string($value) && trim($value) !== '') {
                $names[] = trim($value);
            }

            if (is_array($value)) {
                $this->collectLecturerNames($value, $names);
            }
        }
    }

    /**
     * The row's own cells; a row the portal left unclosed may hold the next
     * rows, whose cells are not this row's.
     *
     * @return list<\DOMElement>
     */
    private function rowCells(\DOMNode $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->nodeName), ['td', 'th'], true)) {
                $cells[] = $child;
            }
        }

        return $cells;
    }

    private function nodeText(?\DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /**
     * The node's text up to its first line break after some text.
     */
    private function firstLine(\DOMNode $node): string
    {
        $text = '';

        $walk = function (\DOMNode $current) use (&$walk, &$text): bool {
            foreach ($current->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'br') {
                    if (trim($text) !== '') {
                        return false;
                    }

                    continue;
                }

                if ($child instanceof \DOMText) {
                    $text .= $child->textContent;
                } elseif ($child->hasChildNodes() && ! $walk($child)) {
                    return false;
                }
            }

            return true;
        };

        $walk($node);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The identifying fields of the profile MyCIC stored for this session's
     * student (its snapshot, or the archived copy), as user_info entries.
     * Placeholder values such as "-" are left out.
     *
     * @return list<array{key: string, path: string, value: string}>
     */
    private function storedProfileIdentity(string $sessionToken): array
    {
        try {
            $stored = app(PortalSnapshotStore::class)->get($sessionToken, 'profile')
                ?? app(PortalDataArchive::class)->restore($sessionToken, 'profile');
        } catch (\Throwable) {
            return [];
        }

        $data = is_array($stored['data'] ?? null) ? $stored['data'] : [];
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : $data;

        return array_values(array_filter(
            $this->flattenUserInfo($profile, 'profile'),
            static fn (array $entry): bool => in_array(strtolower($entry['key']), self::PROFILE_IDENTITY_KEYS, true)
                && mb_strlen($entry['value']) >= 3
                && preg_match('/[\pL\pN]/u', $entry['value']) === 1
        ));
    }

    private function flattenUserInfo(array $values, string $prefix = ''): array
    {
        $entries = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'_'.$key;

            if (is_array($value)) {
                array_push($entries, ...$this->flattenUserInfo($value, $path));

                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                $entries[] = ['key' => (string) $key, 'path' => $path, 'value' => $text];
            }
        }

        return $entries;
    }

    private function capturePath(): string
    {
        $path = trim((string) config('portal.debug.capture_path', ''));

        return rtrim($path !== '' ? $path : storage_path('app/portal-captures'), '/\\');
    }

    private function actionDirectory(string $action): string
    {
        $directory = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', strtolower($action)), '_-');

        return $directory !== '' ? $directory : 'unknown';
    }

    private function reportProductionRefusal(): void
    {
        if (self::$productionRefusalReported) {
            return;
        }

        self::$productionRefusalReported = true;

        Log::warning('portal.debug.record_action_responses is on in production without portal.debug.allow_in_production; portal responses are not recorded.');
    }
}
