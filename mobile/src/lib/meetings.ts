/**
 * Reading the portal's meeting page: its title parts, and where an
 * assignment upload goes.
 */

/** "Pertemuan 3" and "Manajemen Proyek" from "Beranda > Kelas Manajemen Proyek >Pertemuan 3". */
export function meetingTitleParts(breadcrumb: string | null | undefined): { meeting: string | null; course: string | null } {
  const parts = (breadcrumb ?? '')
    .split('>')
    .map((part) => part.trim())
    .filter((part) => part !== '');
  const meeting = parts.find((part) => /^pertemuan\b/i.test(part)) ?? null;
  const course = parts.find((part) => /^kelas\s+/i.test(part))?.replace(/^kelas\s+/i, '').trim() ?? null;

  return { meeting, course: course === '' ? null : course };
}

/** The query values of a portal link such as `?mod=sipandai&act=discuss&id=30061&courses_id=8987`. */
export function linkParams(href: string | null | undefined): Record<string, string> {
  const query = (href ?? '').split('?')[1]?.split('#')[0] ?? '';

  return Object.fromEntries(
    query
      .split('&')
      .map((pair) => pair.split('='))
      .filter(([key, value]) => key && value !== undefined && value !== '')
      .map(([key, value]) => [decodeURIComponent(key), decodeURIComponent(value)]),
  );
}

export type AssignmentTarget = { courses_id: string; id: string; pertemuan: string };

export type AssignmentSource = {
  uploadLink?: string | null;
  uploadText?: string | null;
  deadline?: string | null;
  statusText?: string | null;
  meetingNumber?: string | number | null;
  assignmentTopic?: string | null;
  assignmentInstructions?: string | null;
  assignmentFile?: string | null;
  mainTopic?: string | null;
};

/** Whether the portal says the meeting has no assignment at all. */
export function saysNoAssignment(text: string | null | undefined): boolean {
  return /tidak\s+ada\s+tugas/i.test(text ?? '');
}

/**
 * Where an assignment upload goes, as the web reads it
 * (meetings/show/scripts/parse.blade.php): the upload link's query, else,
 * when the meeting has assignment content, the meeting's own course and id
 * with its meeting number. Null when there is nothing to upload to.
 */
export function assignmentTarget(
  source: AssignmentSource,
  routeCoursesId: string | null | undefined,
  meetingId: string | null | undefined,
): AssignmentTarget | null {
  const params = linkParams(source.uploadLink);
  const linked = params.courses_id ?? params.course_id;

  if (linked && params.id && params.pertemuan) {
    return { courses_id: linked, id: params.id, pertemuan: params.pertemuan };
  }

  if (saysNoAssignment(source.statusText) || saysNoAssignment(source.deadline)) {
    return null;
  }

  const hasContent = [source.assignmentTopic, source.assignmentInstructions, source.assignmentFile, source.uploadText, source.deadline].some(
    (value) => typeof value === 'string' && value.trim() !== '',
  );
  const meetingNumber =
    (source.meetingNumber !== null && source.meetingNumber !== undefined && String(source.meetingNumber).trim() !== '' ? String(source.meetingNumber) : null) ??
    source.assignmentTopic?.match(/(\d+)/)?.[1] ??
    source.mainTopic?.match(/Pertemuan\s+(\d+)/i)?.[1] ??
    null;

  if (!hasContent || !routeCoursesId || !meetingId || !meetingNumber) {
    return null;
  }

  return { courses_id: routeCoursesId, id: meetingId, pertemuan: meetingNumber };
}

/** The class screen a meeting's `class_url` (`/class/5413?dosen_id=2744&course_id=9521`) opens. */
export function classRouteOf(classUrl: string | null | undefined): { id: string; dosen_id?: string; course_id?: string } | null {
  const id = classUrl?.match(/\/class\/([^/?#]+)/)?.[1];

  if (!id) {
    return null;
  }

  const params = linkParams(classUrl);

  return {
    id,
    ...(params.dosen_id ? { dosen_id: params.dosen_id } : {}),
    ...(params.course_id ? { course_id: params.course_id } : {}),
  };
}
