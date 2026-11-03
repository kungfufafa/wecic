import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, View } from 'react-native';

import type { ActionResponse, MeetingResponse } from '@/api/types/actions';
import { useSession } from '@/auth/session-provider';
import { AttendanceForm } from '@/components/actions/attendance-form';
import { FileUpload } from '@/components/actions/file-upload';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { InfoRows } from '@/components/info-rows';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { ATTENDANCE_STATE_LABELS, meetingAttendanceState, meetingAttendanceView, parseIndonesianDate, todayIso } from '@/lib/attendance';
import type { Tone } from '@/lib/format';
import { assignmentTarget, classRouteOf, meetingTitleParts, saysNoAssignment } from '@/lib/meetings';

/** One meeting: its attendance, materials and assignment, as the web's meeting page. */
export default function MeetingScreen() {
  const router = useRouter();
  const { api } = useSession();
  const { id, courses_id: coursesParam } = useLocalSearchParams<{ id: string; courses_id?: string }>();
  const meetingId = String(id);
  const coursesId = typeof coursesParam === 'string' && coursesParam !== '' ? coursesParam : null;
  const meeting = useApiResource<MeetingResponse>(`meeting/${encodeURIComponent(meetingId)}${coursesId ? `?courses_id=${encodeURIComponent(coursesId)}` : ''}`);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const detail = meeting.data?.class_detail;
  const resolvedCoursesId = coursesId ?? meeting.data?.meta?.courses_id ?? null;
  const titles = meetingTitleParts(detail?.meeting_info?.breadcrumb);
  const classRoute = classRouteOf(detail?.class_url);
  const attendanceState = meetingAttendanceState(detail?.attendance_info);
  const attendanceView = meetingAttendanceView(detail?.attendance_info, parseIndonesianDate(detail?.schedule_info?.date), todayIso());
  const assignment = detail?.assignment_info;
  const materials = detail?.materials;
  const target = assignmentTarget(
    {
      uploadLink: assignment?.upload_link,
      uploadText: assignment?.upload_text,
      deadline: assignment?.deadline,
      statusText: assignment?.status_text,
      meetingNumber: assignment?.meeting_number,
      assignmentTopic: materials?.assignment_topic,
      assignmentInstructions: materials?.assignment_instructions,
      assignmentFile: materials?.assignment_material_iframe,
      mainTopic: materials?.main_topic,
    },
    resolvedCoursesId,
    meetingId,
  );
  const noAssignment = saysNoAssignment(assignment?.status_text) || saysNoAssignment(assignment?.deadline);
  const uploaded = assignment?.status === 'uploaded' || Boolean(assignment?.submission);
  const deadlinePassed = /sudah\s+berakhir|ditutup|kedaluwarsa/i.test(assignment?.status_text ?? assignment?.deadline ?? '');

  function confirmDelete() {
    const payload = assignment?.delete_payload;

    if (!payload) {
      return;
    }

    Alert.alert('Hapus tugas?', 'File tugas yang sudah dikirim ke portal akan dihapus.', [
      { text: 'Batal', style: 'cancel' },
      {
        text: 'Hapus',
        style: 'destructive',
        onPress: async () => {
          setDeleting(true);
          setDeleteError(null);

          try {
            await api.delete<ActionResponse>('assignment', {
              id: Number(payload.id),
              idx: Number(payload.idx),
              pertemuan: Number(payload.pertemuan),
              ...(resolvedCoursesId ? { courses_id: Number(resolvedCoursesId) } : {}),
            });
            meeting.reload();
          } catch (error) {
            setDeleteError(error instanceof Error ? error.message : String(error));
          } finally {
            setDeleting(false);
          }
        },
      },
    ]);
  }

  return (
    <Screen
      back
      title={titles.meeting ?? 'Pertemuan'}
      subtitle={titles.course}
      accessory={
        classRoute ? (
          <Chip label="Buka kelas" icon="bookOutline" onPress={() => router.push({ pathname: '/class/[id]', params: classRoute })} />
        ) : null
      }
      refreshing={meeting.isRefreshing}
      dataSavedAt={meeting.savedAt}
      onRefresh={meeting.refresh}>
      {meeting.error ? <Notice tone="error" message={meeting.error} /> : null}

      {meeting.isLoading ? (
        <Section label="Memuat pertemuan">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {detail && detail.found === false ? (
        <Section>
          <Panel>
            <EmptyState icon="calendarEmpty" title="Pertemuan tidak ditemukan" description="Portal tidak punya halaman untuk pertemuan ini." />
          </Panel>
        </Section>
      ) : null}

      {detail && detail.found !== false ? (
        <>
          <Section label="Jadwal">
            <Panel padded={false}>
              <InfoRows
                rows={[
                  { label: 'Dosen', value: detail.lecturer_info?.name },
                  { label: 'Tanggal', value: detail.schedule_info?.date },
                  { label: 'Mulai', value: detail.schedule_info?.start_time },
                ]}
              />
            </Panel>
          </Section>

          <Section
            label="Absensi"
            accessory={<StatusPill label={attendanceLabel(attendanceState, detail.attendance_info?.time)} tone={attendanceTone(attendanceView)} />}>
            <Panel style={styles.gap}>
              <ThemedText type="small" themeColor="textSecondary">
                {attendanceHint(attendanceView)}
              </ThemedText>
              {attendanceView === 'open' && resolvedCoursesId ? (
                <AttendanceForm target={{ courseid: resolvedCoursesId, modulesid: meetingId }} onMarked={meeting.reload} />
              ) : null}
            </Panel>
          </Section>

          {materials?.main_topic || materials?.main_description || materials?.main_material_iframe || detail.rps_info?.file_link ? (
            <Section label="Materi">
              <Panel padded={false}>
                {materials?.main_topic || materials?.main_description ? (
                  <View style={styles.text}>
                    {materials?.main_topic ? <ThemedText type="bodyMedium">{materials.main_topic}</ThemedText> : null}
                    {materials?.main_description ? (
                      <ThemedText type="small" themeColor="textSecondary" selectable>
                        {materials.main_description}
                      </ThemedText>
                    ) : null}
                  </View>
                ) : null}
                <FileRow href={materials?.main_material_iframe} label="Modul pertemuan" />
                <FileRow href={detail.rps_info?.file_link} label="RPS mata kuliah" />
              </Panel>
            </Section>
          ) : null}

          <Section
            label="Tugas"
            accessory={
              <StatusPill
                label={uploaded ? 'Sudah dikumpulkan' : noAssignment ? 'Tidak ada tugas' : 'Belum dikumpulkan'}
                tone={uploaded ? 'positive' : noAssignment || deadlinePassed ? 'muted' : 'attention'}
              />
            }>
            <Panel padded={false}>
              <View style={styles.text}>
                {materials?.assignment_topic ? <ThemedText type="bodyMedium">{materials.assignment_topic}</ThemedText> : null}
                <ThemedText type="small" themeColor="textSecondary">
                  {assignment?.status_text || assignment?.deadline || 'Belum ada keterangan tugas untuk pertemuan ini.'}
                </ThemedText>
              </View>
              <FileRow href={materials?.assignment_material_iframe} label="Soal / latihan" />
              {assignment?.submission?.file_info?.href ? (
                <FileRow
                  href={assignment.submission.file_info.href}
                  label="File yang dikumpulkan"
                  detail={[assignment.submission.date, assignment.submission.score ? `Nilai ${assignment.submission.score}` : null].filter(Boolean).join(' · ')}
                />
              ) : null}
              {target && !deadlinePassed ? (
                <>
                  <Divider inset={Spacing.three} />
                  <View style={styles.text}>
                    <FileUpload
                      path="assignment"
                      fields={{ courses_id: target.courses_id, id: target.id, pertemuan: target.pertemuan }}
                      mimeTypes={['application/pdf']}
                      maxBytes={10 * 1024 * 1024}
                      hint="PDF, maksimal 10 MB"
                      label={uploaded ? 'Kirim ulang' : 'Kumpulkan'}
                      onUploaded={meeting.reload}
                    />
                  </View>
                </>
              ) : null}
              {assignment?.delete_payload ? (
                <View style={styles.text}>
                  {deleteError ? <Notice tone="error" message={deleteError} /> : null}
                  <Button variant="danger" icon="trash" label="Hapus tugas" loading={deleting} onPress={confirmDelete} />
                </View>
              ) : null}
            </Panel>
          </Section>

          {detail.conformity_info?.description ? (
            <Section label="Kesesuaian materi">
              <Panel style={styles.gap}>
                <ThemedText type="small" themeColor="textSecondary">
                  {detail.conformity_info.description}
                </ThemedText>
                <ThemedText type="caption" themeColor="textTertiary">
                  Penilaian kesesuaian materi diisi langsung di portal kampus.
                </ThemedText>
              </Panel>
            </Section>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function FileRow({ href, label, detail }: { href: string | null | undefined; label: string; detail?: string }) {
  const theme = useTheme();
  const file = usePortalFile();

  if (!href || href.trim() === '') {
    return null;
  }

  const busy = file.busyHref === href.trim();

  return (
    <>
      <Divider inset={Spacing.three} />
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`Buka ${label}`}
        onPress={() => void file.open(href, label)}
        style={({ pressed }) => [styles.fileRow, pressed && { backgroundColor: theme.section }]}>
        <View style={[styles.fileIcon, { backgroundColor: theme.elevated }]}>
          <Icon name="document" size={18} color={theme.text} />
        </View>
        <View style={styles.fileText}>
          <ThemedText type="bodyMedium">{label}</ThemedText>
          {detail ? (
            <ThemedText type="small" themeColor="textTertiary">
              {detail}
            </ThemedText>
          ) : null}
        </View>
        {busy ? <ActivityIndicator color={theme.textSecondary} /> : <Icon name="download" size={18} color={theme.textTertiary} />}
      </Pressable>
    </>
  );
}

function attendanceLabel(state: ReturnType<typeof meetingAttendanceState>, time: string | null | undefined): string {
  const label = ATTENDANCE_STATE_LABELS[state];

  return time && ['present', 'late', 'permit', 'sick'].includes(state) ? `${label} · ${time}` : label;
}

function attendanceTone(view: ReturnType<typeof meetingAttendanceView>): Tone {
  return { recorded: 'positive', open: 'attention', past: 'muted', upcoming: 'neutral', closed: 'muted', not_open: 'neutral' }[view] as Tone;
}

function attendanceHint(view: ReturnType<typeof meetingAttendanceView>): string {
  return {
    recorded: 'Kehadiranmu di pertemuan ini sudah tercatat di portal.',
    open: 'Masukkan kode absensi dari dosen untuk mencatat kehadiran.',
    past: 'Pertemuan ini sudah lewat. Absensi tidak bisa dikirim lagi.',
    upcoming: 'Absensi bisa dikirim pada hari pertemuan.',
    closed: 'Absensi pertemuan ini sudah ditutup di portal.',
    not_open: 'Absensi belum dibuka dosen. Tarik ke bawah untuk memeriksa lagi.',
  }[view];
}

const styles = StyleSheet.create({
  gap: {
    gap: Spacing.three - Spacing.one,
  },
  text: {
    gap: Spacing.two,
    padding: Spacing.three,
  },
  fileRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
  },
  fileIcon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  fileText: {
    flex: 1,
    gap: Spacing.half,
  },
});
